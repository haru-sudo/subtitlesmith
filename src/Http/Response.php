<?php

declare(strict_types=1);

namespace App\Http;

/**
 * レスポンス出力ヘルパ。各メソッドはヘッダ設定＋出力を行う。
 */
final class Response
{
    public static function html(string $body, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: text/html; charset=UTF-8');
        echo $body;
    }

    public static function json(mixed $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public static function redirect(string $location, int $status = 302): void
    {
        // アプリ内パス("/..."）にはベースパスを前置（"//" や http(s):// は対象外）
        if ($location !== '' && $location[0] === '/' && substr($location, 0, 2) !== '//') {
            $base = defined('APP_BASE') ? APP_BASE : '';
            if ($base !== '') {
                $location = $base . $location;
            }
        }
        http_response_code($status);
        header('Location: ' . $location);
    }

    public static function text(string $body, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: text/plain; charset=UTF-8');
        echo $body;
    }

    public static function notFound(string $message = 'Not Found'): void
    {
        self::html('<h1>404</h1><p>' . htmlspecialchars($message) . '</p>', 404);
    }

    public static function error(string $message, int $status = 500): void
    {
        self::html('<h1>' . $status . '</h1><p>' . htmlspecialchars($message) . '</p>', $status);
    }

    /**
     * ファイルをストリーム配信（認証済みエンドポイントから利用）。
     * Rangeリクエスト対応で <video> のシークに対応。
     */
    public static function file(string $path, ?string $downloadName = null, ?string $mime = null): void
    {
        if (!is_file($path)) {
            self::notFound('ファイルがありません');
            return;
        }

        // 大きな動画の配信で出力バッファや圧縮が邪魔をしないよう無効化する
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (function_exists('apache_setenv')) {
            @apache_setenv('no-gzip', '1');
        }
        @ini_set('zlib.output_compression', '0');
        @ini_set('output_buffering', '0');
        @set_time_limit(0);

        $size = (int) filesize($path);
        $mime ??= self::guessMime($path);

        header('Content-Type: ' . $mime);
        header('Accept-Ranges: bytes');
        // nginx等のリバースプロキシが応答を溜め込まないようにする（Safariが固まる主因対策）
        header('X-Accel-Buffering: no');
        if ($downloadName !== null) {
            header('Content-Disposition: ' . self::contentDisposition($downloadName));
        }

        $start = 0;
        $end = $size - 1;
        $range = $_SERVER['HTTP_RANGE'] ?? null;
        $isRange = false;

        // 1リクエストで返す最大バイト数。Safariは bytes=0- で全体を要求してくるが、
        // 全部(数GB)を一度に返すとプロキシのバッファに詰まって再生が始まらない。
        // 範囲の一部だけ返すのはHTTP仕様上も正当で、ブラウザは続きを小刻みに取りに来る。
        $maxServe = 8 * 1024 * 1024; // 8MB

        if ($range && preg_match('/bytes=(\d*)-(\d*)/', $range, $m)) {
            $isRange = true;
            if ($m[1] !== '') {
                $start = (int) $m[1];
            }
            if ($m[2] !== '') {
                $end = (int) $m[2];
            }
            if ($start < 0) {
                $start = 0;
            }
            if ($end >= $size) {
                $end = $size - 1;
            }
            if ($start > $end) {
                // 不正なレンジ → 416 を返す
                http_response_code(416);
                header(sprintf('Content-Range: bytes */%d', $size));
                return;
            }
            // 終端が明示されていない（bytes=0- のような）要求は、上限で区切る
            if ($m[2] === '' && ($end - $start + 1) > $maxServe) {
                $end = $start + $maxServe - 1;
            }
            http_response_code(206);
            header(sprintf('Content-Range: bytes %d-%d/%d', $start, $end, $size));
        }

        $length = $end - $start + 1;
        // 2GB超でも桁あふれしないよう sprintf で出力
        header(sprintf('Content-Length: %d', $length));

        $fp = fopen($path, 'rb');
        if ($fp === false) {
            self::error('ファイルを開けません');
            return;
        }

        // 大きいオフセットへの移動（64bit環境なら fseek が直接効く。失敗時は読み飛ばしで対応）
        if ($start > 0 && fseek($fp, $start, SEEK_SET) !== 0) {
            $toSkip = $start;
            while ($toSkip > 0 && !feof($fp)) {
                $skip = fread($fp, (int) min(1048576, $toSkip));
                if ($skip === false) {
                    break;
                }
                $toSkip -= strlen($skip);
            }
        }

        $bufSize = 1048576; // 1MBずつ読んで送る
        $remaining = $length;
        while ($remaining > 0 && !feof($fp)) {
            $read = (int) min($bufSize, $remaining);
            $chunk = fread($fp, $read);
            if ($chunk === false || $chunk === '') {
                break;
            }
            echo $chunk;
            flush();
            $remaining -= strlen($chunk);
            // クライアントが切断したら無駄な配信を止める
            if (connection_aborted()) {
                break;
            }
        }
        fclose($fp);
    }

    /**
     * ダウンロード用 Content-Disposition 値を生成する。
     * 日本語などの非ASCIIファイル名でも正しく保存できるよう、
     * ASCIIフォールバック(filename=) と RFC5987(filename*=UTF-8'') を併記する。
     */
    public static function contentDisposition(string $name, string $type = 'attachment'): string
    {
        // 制御文字・パス区切りを除去
        $name = str_replace(["\r", "\n", "\\", '/'], '', $name);
        $name = trim($name);
        if ($name === '') {
            $name = 'download';
        }
        // ASCIIフォールバック（非ASCIIは "_" に、ダブルクォートも除去）
        $ascii = preg_replace('/[^\x20-\x7E]/', '_', $name);
        $ascii = str_replace('"', '', (string) $ascii);
        if (trim($ascii, '_ ') === '') {
            $ascii = 'download';
        }
        $encoded = rawurlencode($name);
        return $type . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . $encoded;
    }

    private static function guessMime(string $path): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return match ($ext) {
            'mp4', 'm4v' => 'video/mp4',
            'webm'       => 'video/webm',
            'mov'        => 'video/quicktime',
            'mkv'        => 'video/x-matroska',
            'mp3'        => 'audio/mpeg',
            'wav'        => 'audio/wav',
            'jpg', 'jpeg' => 'image/jpeg',
            'png'        => 'image/png',
            'vtt'        => 'text/vtt',
            'srt'        => 'application/x-subrip',
            default      => 'application/octet-stream',
        };
    }
}
