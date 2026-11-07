<?php
/**
 * 共通ブートストラップ。
 * - PSR-4風の簡易オートローダ（App\ → src/）を登録（Composer不要）
 * - 設定を読み込み App::boot() で初期化
 * - Web入口(public/index.php) と CLI(worker.php) の双方から require する
 */

declare(strict_types=1);

define('APP_BASE_PATH', dirname(__DIR__));

// ---- 簡易オートローダ ----
spl_autoload_register(function (string $class): void {
    $prefix = 'App\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = APP_BASE_PATH . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

// ---- グローバルヘルパ関数 ----
require __DIR__ . '/helpers.php';

// ---- 設定読み込み ----
$configFile = APP_BASE_PATH . '/config/config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    fwrite(STDERR, "設定ファイルがありません: config/config.php を config.example.php からコピーしてください。\n");
    // Web経由の場合にも分かるように
    if (PHP_SAPI !== 'cli') {
        echo '設定ファイル config/config.php がありません。config.example.php をコピーして作成してください。';
    }
    exit(1);
}
$config = require $configFile;

// タイムゾーン（既定: 日本時間）。DBの datetime('now','localtime') と揃える。
date_default_timezone_set($config['timezone'] ?? 'Asia/Tokyo');

// ベースパス（サブディレクトリ設置対応, 例 "/subtitlesmith"）。
// config の base_path を優先し、未指定なら SCRIPT_NAME から自動検出。ルート設置なら ''。
if (array_key_exists('base_path', $config) && $config['base_path'] !== null) {
    $appBase = (string) $config['base_path'];
} elseif (PHP_SAPI === 'cli') {
    $appBase = ''; // CLI(worker)はURL生成しないので空
} else {
    $sn = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '')));
    $appBase = ($sn === '/' || $sn === '.' || $sn === '') ? '' : $sn;
}
define('APP_BASE', rtrim($appBase, '/'));

App\App::boot($config);
