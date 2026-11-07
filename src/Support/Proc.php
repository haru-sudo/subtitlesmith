<?php

declare(strict_types=1);

namespace App\Support;

/**
 * 外部コマンド実行。stdout/stderr は一時ファイルに逃がす
 * （パイプ方式は ffmpeg 等の大量stderrでデッドロックするため）。
 */
final class Proc
{
    /**
     * @param array<int,string> $args
     * @return array{0:int,1:string,2:string} [exitCode, stdout, stderr]
     */
    public static function run(string $bin, array $args, ?string $cwd = null): array
    {
        $parts = array_map('escapeshellarg', $args);
        array_unshift($parts, escapeshellarg($bin));
        $cmd = implode(' ', $parts);

        $outFile = tempnam(sys_get_temp_dir(), 'subtitlesmith_out_');
        $errFile = tempnam(sys_get_temp_dir(), 'subtitlesmith_err_');
        $devNull = (DIRECTORY_SEPARATOR === '\\') ? 'NUL' : '/dev/null';

        $descriptors = [
            0 => ['file', $devNull, 'r'],
            1 => ['file', $outFile, 'w'],
            2 => ['file', $errFile, 'w'],
        ];

        $proc = proc_open($cmd, $descriptors, $pipes, $cwd);
        if (!is_resource($proc)) {
            @unlink($outFile);
            @unlink($errFile);
            throw new \RuntimeException('プロセスを起動できません: ' . $bin);
        }
        $code = proc_close($proc);

        $stdout = (string) @file_get_contents($outFile);
        $stderr = (string) @file_get_contents($errFile);
        @unlink($outFile);
        @unlink($errFile);

        return [$code, $stdout, $stderr];
    }

    /**
     * run() と同様だが、実行中に stderr へ書き出される行を逐次読み取り、
     * 行ごとに $onLine($line) を呼ぶ。長時間処理の進捗表示に使う。
     * stderr はファイルへ逃がしたまま（パイプ方式のデッドロックを避ける）、
     * そのファイルを実行中にポーリングして growth を読む。
     *
     * @param array<int,string> $args
     * @return array{0:int,1:string,2:string} [exitCode, stdout, stderr]
     */
    public static function runWithProgress(string $bin, array $args, ?callable $onLine = null, ?string $cwd = null): array
    {
        $parts = array_map('escapeshellarg', $args);
        array_unshift($parts, escapeshellarg($bin));
        $cmd = implode(' ', $parts);

        $outFile = tempnam(sys_get_temp_dir(), 'subtitlesmith_out_');
        $errFile = tempnam(sys_get_temp_dir(), 'subtitlesmith_err_');
        $devNull = (DIRECTORY_SEPARATOR === '\\') ? 'NUL' : '/dev/null';

        $descriptors = [
            0 => ['file', $devNull, 'r'],
            1 => ['file', $outFile, 'w'],
            2 => ['file', $errFile, 'w'],
        ];

        $proc = proc_open($cmd, $descriptors, $pipes, $cwd);
        if (!is_resource($proc)) {
            @unlink($outFile);
            @unlink($errFile);
            throw new \RuntimeException('プロセスを起動できません: ' . $bin);
        }

        $errFp = fopen($errFile, 'r');
        $buf = '';
        $emit = static function (string $chunk) use (&$buf, $onLine): void {
            if ($chunk === '') {
                return;
            }
            $buf .= $chunk;
            while (($nl = strpos($buf, "\n")) !== false) {
                $line = rtrim(substr($buf, 0, $nl), "\r");
                $buf = substr($buf, $nl + 1);
                if ($onLine !== null) {
                    $onLine($line);
                }
            }
        };

        while (true) {
            $status = proc_get_status($proc);
            if ($errFp) {
                $emit((string) stream_get_contents($errFp));
            }
            if (!$status['running']) {
                break;
            }
            usleep(200000); // 200ms ごとにポーリング
        }

        // 残りを読み切る
        if ($errFp) {
            $emit((string) stream_get_contents($errFp));
            if ($buf !== '' && $onLine !== null) {
                $onLine(rtrim($buf, "\r"));
            }
            fclose($errFp);
        }

        $code = proc_close($proc);
        $stdout = (string) @file_get_contents($outFile);
        $stderr = (string) @file_get_contents($errFile);
        @unlink($outFile);
        @unlink($errFile);

        return [$code, $stdout, $stderr];
    }
}
