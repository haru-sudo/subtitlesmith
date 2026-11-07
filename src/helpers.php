<?php

declare(strict_types=1);

/**
 * テンプレート等で使うグローバルヘルパ関数。
 */

if (!function_exists('e')) {
    /** HTMLエスケープ */
    function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('url')) {
    /** アプリ内URL（ベースパス前置）。$path は "/login" のように先頭スラッシュ。 */
    function url(string $path = '/'): string
    {
        $base = defined('APP_BASE') ? APP_BASE : '';
        if ($path === '' || $path === '/') {
            return $base === '' ? '/' : $base . '/';
        }
        return $base . $path;
    }
}

if (!function_exists('asset')) {
    /** アセットURL。更新検知のため filemtime をバージョンとして付与（キャッシュ最適化） */
    function asset(string $path): string
    {
        $base = defined('APP_BASE') ? APP_BASE : '';
        $rel = ltrim($path, '/');
        $url = $base . '/assets/' . $rel;
        static $vers = [];
        if (!array_key_exists($rel, $vers)) {
            $file = dirname(__DIR__) . '/assets/' . $rel;
            $vers[$rel] = is_file($file) ? (string) filemtime($file) : '';
        }
        return $vers[$rel] !== '' ? $url . '?v=' . $vers[$rel] : $url;
    }
}

if (!function_exists('webview_info')) {
    /**
     * User-Agent からアプリ内ブラウザ(WebView)を判定する。
     *
     * Google OAuth は埋め込みWebView（LINE / Instagram / Facebook 等のアプリ内ブラウザ）
     * からのリクエストを 403 disallowed_useragent で拒否するため、
     * 同意画面へ飛ばす前にここで検出し、OS標準ブラウザへ誘導する。
     *
     * @return array{in_app:bool, name:string, os:string} os は 'ios'|'android'|''
     */
    function webview_info(?string $ua = null): array
    {
        $ua = $ua ?? (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        $os = '';
        if (preg_match('/iPhone|iPad|iPod/i', $ua)) {
            $os = 'ios';
        } elseif (stripos($ua, 'Android') !== false) {
            $os = 'android';
        }

        // 既知のアプリ内ブラウザ（UA断片 => 表示名）
        $known = [
            'Line/'          => 'LINE',
            'NAVER(inapp'    => 'LINE',
            'FBAN'           => 'Facebook',
            'FBAV'           => 'Facebook',
            'FB_IAB'         => 'Facebook',
            'Instagram'      => 'Instagram',
            'Messenger'      => 'Messenger',
            'Twitter'        => 'X(Twitter)',
            'TwitterAndroid' => 'X(Twitter)',
            'MicroMessenger' => 'WeChat',
            'TikTok'         => 'TikTok',
            'musical_ly'     => 'TikTok',
            'KAKAOTALK'      => 'KakaoTalk',
            'Snapchat'       => 'Snapchat',
            'Pinterest'      => 'Pinterest',
            'GSA/'           => 'Googleアプリ',
        ];
        foreach ($known as $needle => $label) {
            if (stripos($ua, $needle) !== false) {
                return ['in_app' => true, 'name' => $label, 'os' => $os];
            }
        }

        // 汎用WebView検出:
        // - Android: "; wv)" を含む WebView。Chrome/SafariのフルUAは除外。
        if ($os === 'android' && stripos($ua, '; wv)') !== false) {
            return ['in_app' => true, 'name' => 'アプリ内ブラウザ', 'os' => $os];
        }
        // - iOS: Safari純正は "Safari/" を含む。AppleWebKit はあるが Safari/ や CriOS/FxiOS が
        //   無い場合は WKWebView（アプリ内ブラウザ）の可能性が高い。
        if ($os === 'ios'
            && stripos($ua, 'AppleWebKit') !== false
            && stripos($ua, 'Safari/') === false
            && stripos($ua, 'CriOS') === false
            && stripos($ua, 'FxiOS') === false
            && stripos($ua, 'EdgiOS') === false) {
            return ['in_app' => true, 'name' => 'アプリ内ブラウザ', 'os' => $os];
        }

        return ['in_app' => false, 'name' => '', 'os' => $os];
    }
}

if (!function_exists('ms_to_clock')) {
    /** ミリ秒 → 表示用 mm:ss または hh:mm:ss */
    function ms_to_clock(int $ms): string
    {
        $totalSec = intdiv($ms, 1000);
        $h = intdiv($totalSec, 3600);
        $m = intdiv($totalSec % 3600, 60);
        $s = $totalSec % 60;
        return $h > 0
            ? sprintf('%d:%02d:%02d', $h, $m, $s)
            : sprintf('%d:%02d', $m, $s);
    }
}

if (!function_exists('ms_to_srt')) {
    /** ミリ秒 → SRTタイムコード hh:mm:ss,mmm */
    function ms_to_srt(int $ms): string
    {
        $h = intdiv($ms, 3600000);
        $m = intdiv($ms % 3600000, 60000);
        $s = intdiv($ms % 60000, 1000);
        $milli = $ms % 1000;
        return sprintf('%02d:%02d:%02d,%03d', $h, $m, $s, $milli);
    }
}

if (!function_exists('ms_to_vtt')) {
    /** ミリ秒 → WebVTTタイムコード hh:mm:ss.mmm */
    function ms_to_vtt(int $ms): string
    {
        $h = intdiv($ms, 3600000);
        $m = intdiv($ms % 3600000, 60000);
        $s = intdiv($ms % 60000, 1000);
        $milli = $ms % 1000;
        return sprintf('%02d:%02d:%02d.%03d', $h, $m, $s, $milli);
    }
}

if (!function_exists('ms_to_ass')) {
    /** ミリ秒 → ASSタイムコード h:mm:ss.cc（センチ秒） */
    function ms_to_ass(int $ms): string
    {
        $h = intdiv($ms, 3600000);
        $m = intdiv($ms % 3600000, 60000);
        $s = intdiv($ms % 60000, 1000);
        $cs = intdiv($ms % 1000, 10);
        return sprintf('%d:%02d:%02d.%02d', $h, $m, $s, $cs);
    }
}

if (!function_exists('status_label')) {
    /** ステータスの日本語表示 */
    function status_label(string $status): string
    {
        return [
            'catalog'      => '未取込(ファイル待ち)',
            'uploaded'     => 'アップロード済み',
            'extracting'   => '音声抽出中',
            'transcribing' => '文字起こし中',
            'translating'  => '翻訳中',
            'ready'        => '編集可能',
            'exporting'    => '書き出し中',
            'exported'     => '書き出し完了',
            'trimming'     => '動画編集中',
            'error'        => 'エラー',
        ][$status] ?? $status;
    }
}
