<?php

declare(strict_types=1);

/**
 * 予備のワーカーエントリ（任意）。
 *
 * 通常運用ではcronは不要です。動画アップロード時に Worker::spawn() が
 * バックグラウンド処理（runner.php）を自動起動します。
 *
 * このファイルは保険として残してあります。もし「即時起動」が
 * 環境的に動かない場合のみ、cronに毎分登録して保留ジョブを拾わせられます:
 *   * * * * * /usr/bin/php /path/to/runner.php >> /path/to/logs/cron.log 2>&1
 *
 * 引数で1tickの最大処理秒数を指定可能（既定50秒）。
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI専用です。\n");
}

require __DIR__ . '/src/bootstrap.php';

$maxSeconds = isset($argv[1]) ? max(5, (int) $argv[1]) : 50;

App\Worker::run($maxSeconds);
