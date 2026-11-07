<?php

declare(strict_types=1);

namespace App;

use App\Repo\Segments;
use App\Repo\VideoLangs;
use App\Repo\Videos;

/**
 * 字幕焼き込み（書き出し）。worker の 'export' ジョブから呼ばれる。
 * ※ 生成（文字起こし・翻訳）とは別工程。ユーザーが「動画を書き出す」を押した時のみ実行される。
 *
 * 多言語の動画では言語ごとに別のMP4を作る（ファイル名の末尾に言語IDが付く）。
 */
final class Exporter
{
    public static function run(array $job): void
    {
        $videoId = (int) $job['video_id'];
        $v = Videos::find($videoId);
        if ($v === null) {
            throw new \RuntimeException('動画レコードがありません: ' . $videoId);
        }

        $lang = VideoLangs::resolve($videoId, Jobs::payload($job)['target_lang'] ?? null);

        $segments = Segments::forVideo($videoId, $lang);
        if (!$segments) {
            throw new \RuntimeException('字幕がありません。');
        }

        $videoAbs = App::storagePath((string) $v['file_path']);
        if (!is_file($videoAbs)) {
            throw new \RuntimeException('動画ファイルがありません: ' . $videoAbs);
        }

        Videos::setStatus($videoId, 'exporting');

        [$w, $h] = Media::probeDimensions($videoAbs);
        // 編集画面の「1行の長さ警告」も同じ寸法から算出するので、映像サイズを控えておく
        if ((int) ($v['video_width'] ?? 0) !== $w || (int) ($v['video_height'] ?? 0) !== $h) {
            Videos::update($videoId, ['video_width' => $w, 'video_height' => $h]);
        }

        // 言語ごとに別ファイル（同時に複数言語を持てるよう、ファイル名に言語IDを入れる）
        $base = pathinfo((string) $v['file_path'], PATHINFO_FILENAME);
        $suffix = preg_replace('/[^A-Za-z0-9_-]/', '', $lang) ?: 'ja';
        $assRel = 'subs/' . $base . '_' . $suffix . '.ass';
        $assAbs = App::storagePath($assRel);
        file_put_contents($assAbs, Subtitles::ass($segments, $w, $h, $lang));

        $outRel = 'exports/' . $base . '_subtitled_' . $suffix . '.mp4';
        $outAbs = App::storagePath($outRel);

        Media::burnSubtitles($videoAbs, $assAbs, $outAbs);

        VideoLangs::add($videoId, $lang, 'ready');
        VideoLangs::setExport($videoId, $lang, $outRel);

        // videos 側は主言語の書き出しを映す（一覧の「書き出し済み」表示などが従来通り動くように）
        if ($lang === VideoLangs::primaryLang($videoId)) {
            Videos::update($videoId, [
                'export_path' => $outRel,
                'exported_at' => date('Y-m-d H:i:s'),
            ]);
        }
        Videos::setStatus($videoId, 'exported');
    }
}
