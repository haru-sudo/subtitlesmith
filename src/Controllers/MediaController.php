<?php

declare(strict_types=1);

namespace App\Controllers;

use App\App;
use App\Http\Request;
use App\Http\Response;
use App\Repo\VideoLangs;
use App\Repo\Videos;
use App\TargetLangs;

/**
 * storage配下（非公開）のメディアを認証付きで配信。
 */
final class MediaController
{
    public function thumbnail(Request $request, array $params): void
    {
        $v = Videos::find((int) $params['id']);
        if (!$v || empty($v['thumbnail_path'])) {
            Response::notFound();
            return;
        }
        Response::file(App::storagePath($v['thumbnail_path']));
    }

    public function stream(Request $request, array $params): void
    {
        $v = Videos::find((int) $params['id']);
        if (!$v || empty($v['file_path'])) {
            Response::notFound();
            return;
        }
        Response::file(App::storagePath($v['file_path']));
    }

    /**
     * 字幕を焼き込んでいないオリジナルの元動画をダウンロードさせる。
     * stream() と同じファイルだが Content-Disposition で添付保存させる。
     */
    public function source(Request $request, array $params): void
    {
        $v = Videos::find((int) $params['id']);
        if (!$v || empty($v['file_path'])) {
            Response::notFound('元動画がありません');
            return;
        }
        $ext = strtolower(pathinfo((string) $v['file_path'], PATHINFO_EXTENSION));
        if ($ext === '') {
            $ext = 'mp4';
        }
        $name = self::baseName($v) . '.' . $ext;
        Response::file(App::storagePath($v['file_path']), $name);
    }

    public function vtt(Request $request, array $params): void
    {
        $videoId = (int) $params['id'];
        $v = Videos::find($videoId);
        if (!$v) {
            Response::notFound();
            return;
        }
        // ?lang= で言語を選ぶ（未指定は主言語）
        $lang = VideoLangs::resolve($videoId, (string) $request->input('lang', ''));
        // 焼き込みと同じ1行上限で行数を判定し、最低表示時間を揃える（映像サイズ未取得なら既定サイズ）
        $w = (int) ($v['video_width'] ?? 0);
        $h = (int) ($v['video_height'] ?? 0);
        $vtt = ($w > 0 && $h > 0)
            ? \App\Subtitles::vtt(\App\Repo\Segments::forVideo($videoId, $lang), $w, $h)
            : \App\Subtitles::vtt(\App\Repo\Segments::forVideo($videoId, $lang));
        // ?dl=1 のときは One-Stream 等へアップロードできるよう .vtt ファイルとして保存させる
        $isDownload = ((string) $request->input('dl', '') !== '');
        http_response_code(200);
        header('Content-Type: text/vtt; charset=UTF-8');
        header('Cache-Control: no-store');
        if ($isDownload) {
            header('Content-Disposition: ' . Response::contentDisposition(self::baseName($v) . '_' . $lang . '.vtt'));
        }
        echo $vtt;
    }

    /** 焼き込み済みMP4のダウンロード。?lang= でどの言語版かを選ぶ（未指定は主言語）。 */
    public function download(Request $request, array $params): void
    {
        $videoId = (int) $params['id'];
        $v = Videos::find($videoId);
        if (!$v) {
            Response::notFound('動画が見つかりません');
            return;
        }
        $lang = VideoLangs::resolve($videoId, (string) $request->input('lang', ''));
        $row = VideoLangs::find($videoId, $lang);
        $path = (string) ($row['export_path'] ?? '');
        if ($path === '') {
            Response::notFound(TargetLangs::label($lang) . 'の書き出し済み動画がありません');
            return;
        }
        $name = self::baseName($v) . '_subtitled_' . $lang . '.mp4';
        Response::file(App::storagePath($path), $name);
    }

    /**
     * ダウンロードファイル名のベース（拡張子なし）を作る。
     * タイトル > 元ファイル名 > video_{id} の優先順で採用する。
     */
    private static function baseName(array $v): string
    {
        $base = trim((string) ($v['title'] ?? ''));
        if ($base === '') {
            $base = pathinfo((string) ($v['source_file_name'] ?? ''), PATHINFO_FILENAME);
        }
        if ($base === '') {
            $base = 'video_' . (int) ($v['id'] ?? 0);
        }
        return $base;
    }
}
