<?php

declare(strict_types=1);

/**
 * バックグラウンド処理のエントリ（cronの代替）。
 * Worker::spawn() からデタッチ起動される。アップロード後の文字起こし→翻訳や
 * 書き出しを、HTTPリクエストとは独立したプロセスで実行する。
 *
 * 単体でも実行可能:  php runner.php [maxSeconds]
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI専用です。\n");
}

require __DIR__ . '/src/bootstrap.php';

// バックグラウンドなので時間制限を解除（環境が許す範囲で）
@set_time_limit(0);
ignore_user_abort(true);

// 既定の1回あたりの処理予算（秒）。引数で上書き可。
$maxSeconds = isset($argv[1]) ? max(5, (int) $argv[1]) : 600;

App\Worker::run($maxSeconds);
