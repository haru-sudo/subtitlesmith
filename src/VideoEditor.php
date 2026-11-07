<?php

declare(strict_types=1);

namespace App;

use App\Repo\Segments;
use App\Repo\Videos;

/**
 * 動画編集（区間カット）。worker の 'trim' ジョブから呼ばれる。
 *
 * 指定区間 [cutStart, cutEnd) を映像・音声から取り除き、
 * セグメント（字幕）側も同じ区間を削除して後続を繰り上げる。
 * 焼き込み済み動画は古くなるため破棄し、サムネイル・総尺を更新する。
 */
final class VideoEditor
{
    public static function run(array $job): void
    {
        $videoId = (int) $job['video_id'];
        $payload = Jobs::payload($job);
        $cutStart = (int) ($payload['cut_start_ms'] ?? -1);
        $cutEnd   = (int) ($payload['cut_end_ms'] ?? -1);

        $v = Videos::find($videoId);
        if ($v === null) {
            throw new \RuntimeException('動画レコードがありません: ' . $videoId);
        }
        if ($cutStart < 0 || $cutEnd <= $cutStart) {
            throw new \RuntimeException('カット範囲が不正です。');
        }

        $srcRel = (string) $v['file_path'];
        $srcAbs = App::storagePath($srcRel);
        if ($srcRel === '' || !is_file($srcAbs)) {
            throw new \RuntimeException('動画ファイルがありません: ' . $srcAbs);
        }

        Videos::setStatus($videoId, 'trimming');
        Videos::setStage($videoId, 1, 1, '動画をカット中');
        Videos::setProgress($videoId, 10, '');

        // 総尺を把握し、末尾を越える指定はクランプする
        $durationMs = $v['duration_ms'] !== null ? (int) $v['duration_ms'] : Media::probeDurationMs($srcAbs);
        if ($durationMs !== null && $durationMs > 0) {
            $cutEnd = min($cutEnd, $durationMs);
        }
        if ($cutEnd <= $cutStart) {
            throw new \RuntimeException('カット範囲が不正です。');
        }
        $cutMs = $cutEnd - $cutStart;

        // 新しいファイル名（キャッシュ衝突を避けるため毎回変える）。出力は常にmp4。
        // 既存の編集サフィックスは重ねずに置き換える。重ねるとカットのたびに名前が19文字ずつ
        // 伸び、十数回でファイル名長の上限（255バイト）を超えて ffmpeg が失敗する。
        $base = (string) preg_replace('/(?:_edit\d{14}(?:_\d+)?)+$/', '', pathinfo($srcRel, PATHINFO_FILENAME));
        $stamp = date('YmdHis');
        $newRel = 'videos/' . $base . '_edit' . $stamp . '.mp4';
        // 同一秒に2回カットすると入力と同名になり得るので、その場合だけ連番を足す
        for ($i = 1; App::storagePath($newRel) === $srcAbs || is_file(App::storagePath($newRel)); $i++) {
            $newRel = 'videos/' . $base . '_edit' . $stamp . '_' . $i . '.mp4';
        }
        $newAbs = App::storagePath($newRel);

        Videos::setProgress($videoId, 20, '映像を書き出し中');
        Media::cutOutRange($srcAbs, $newAbs, $cutStart, $cutEnd, $durationMs);

        // セグメント（字幕）側を同じ区間で詰める
        Videos::setProgress($videoId, 80, '字幕を調整中');
        Segments::applyCut($videoId, $cutStart, $cutEnd);

        // 総尺・サムネイルを更新
        $newDurationMs = Media::probeDurationMs($newAbs);
        $thumbRel = (string) ($v['thumbnail_path'] ?? '');
        if ($thumbRel === '') {
            $thumbRel = 'thumbs/' . $base . '.jpg';
        }
        try {
            $atSec = ($newDurationMs && $newDurationMs > 0) ? min(1.0, ($newDurationMs / 1000) / 2) : 1.0;
            Media::thumbnail($newAbs, App::storagePath($thumbRel), $atSec);
        } catch (\Throwable $e) {
            App::log('カット後のサムネイル生成に失敗: ' . $e->getMessage(), 'worker');
        }

        // 焼き込み済み動画は総尺が変わって古くなるので破棄（次回は作り直し）。
        // 多言語の動画では全言語分の焼き込みが古くなる。
        $oldExport = (string) ($v['export_path'] ?? '');
        if ($oldExport !== '') {
            @unlink(App::storagePath($oldExport));
        }
        foreach (\App\Repo\VideoLangs::forVideo($videoId) as $vl) {
            if (!empty($vl['export_path'])) {
                @unlink(App::storagePath((string) $vl['export_path']));
            }
            App::db()->update(
                'video_langs',
                ['export_path' => null, 'exported_at' => null],
                ['id' => (int) $vl['id']]
            );
        }

        Videos::update($videoId, [
            'file_path'      => $newRel,
            'duration_ms'    => $newDurationMs ?? (($durationMs ?? 0) > $cutMs ? $durationMs - $cutMs : null),
            'thumbnail_path' => $thumbRel,
            'export_path'    => null,
            'exported_at'    => null,
        ]);

        // 元の動画ファイルを削除（新ファイルへ置き換え済み）
        if ($srcAbs !== $newAbs) {
            @unlink($srcAbs);
        }

        Videos::setProgress($videoId, 100, '');
        Videos::setStatus($videoId, 'ready');
        App::log("動画をカット: video #{$videoId} [{$cutStart}-{$cutEnd}]ms", 'worker');
    }
}
