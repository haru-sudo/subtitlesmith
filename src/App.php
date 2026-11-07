<?php

declare(strict_types=1);

namespace App;

/**
 * アプリ全体のサービス保持（設定・DB）。グローバル変数の代わりの静的レジストリ。
 */
final class App
{
    private static array $config = [];
    private static ?Database $db = null;

    public static function boot(array $config): void
    {
        self::$config = $config;

        // 必要ディレクトリを用意
        foreach ([
            self::config('storage_dir'),
            self::config('log_dir'),
            self::storagePath('videos'),
            self::storagePath('audio'),
            self::storagePath('thumbs'),
            self::storagePath('subs'),
            self::storagePath('exports'),
            self::storagePath('uploads_tmp'),
        ] as $dir) {
            if ($dir && !is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
        }

        // storage は公開領域外配置が前提だが、万一Web配下でも直アクセスを遮断（二重防御）
        $storageHtaccess = self::config('storage_dir') . '/.htaccess';
        if (self::config('storage_dir') && !is_file($storageHtaccess)) {
            @file_put_contents($storageHtaccess, "Require all denied\n");
        }

        // 動画は再生を軽くするため Web サーバーから直接配信する（編集画面のプレーヤーが使う）。
        // 上の storage/.htaccess が遮断するので、videos/ だけ許可する .htaccess を置く（Apache 用）。
        $videosHtaccess = self::storagePath('videos', '.htaccess');
        $template = APP_BASE_PATH . '/deploy/storage_videos.htaccess';
        if (self::config('storage_dir') && !is_file($videosHtaccess) && is_file($template)) {
            @copy($template, $videosHtaccess);
        }

        // ログは公開領域にあっても読めないようにする（二重防御）
        $logHtaccess = self::config('log_dir') . '/.htaccess';
        if (self::config('log_dir') && !is_file($logHtaccess)) {
            @file_put_contents($logHtaccess, "Require all denied\n");
        }
    }

    /** 設定値の取得（ドット記法は未対応の単純キー） */
    public static function config(string $key, mixed $default = null): mixed
    {
        return self::$config[$key] ?? $default;
    }

    public static function db(): Database
    {
        if (self::$db === null) {
            $db = new Database((string) self::config('db_path'));
            $db->migrate(APP_BASE_PATH . '/schema.sql');
            self::$db = $db;
        }
        return self::$db;
    }

    /** storage配下の絶対パスを組み立てる */
    public static function storagePath(string ...$parts): string
    {
        $base = rtrim((string) self::config('storage_dir'), '/\\');
        return $base . ($parts ? '/' . implode('/', $parts) : '');
    }

    /** ログ出力（worker・エラー用） */
    public static function log(string $message, string $channel = 'app'): void
    {
        $dir = (string) self::config('log_dir');
        if ($dir && !is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $line = '[' . date('Y-m-d H:i:s') . "] $message\n";
        @file_put_contents($dir . "/$channel.log", $line, FILE_APPEND);
    }
}
