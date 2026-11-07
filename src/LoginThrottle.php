<?php

declare(strict_types=1);

namespace App;

/**
 * パスワードログインの総当たり対策。
 * 同じ IP から一定時間内に失敗が続いたら、しばらくログインを受け付けない。
 * 記録は storage/login_attempts.json（IP はハッシュ化して保存）。
 */
final class LoginThrottle
{
    private const MAX_FAILURES = 10;     // この回数失敗すると
    private const WINDOW_SEC   = 900;    // この秒数のあいだロックする

    public static function isLocked(): bool
    {
        $rec = self::load()[self::key()] ?? null;
        return $rec !== null
            && $rec['count'] >= self::MAX_FAILURES
            && (time() - $rec['first']) < self::WINDOW_SEC;
    }

    public static function fail(): void
    {
        self::update(static function (array $all, string $key): array {
            $now = time();
            $rec = $all[$key] ?? null;
            if ($rec === null || ($now - $rec['first']) >= self::WINDOW_SEC) {
                $rec = ['count' => 0, 'first' => $now];
            }
            $rec['count']++;
            $all[$key] = $rec;
            return $all;
        });
    }

    public static function reset(): void
    {
        self::update(static function (array $all, string $key): array {
            unset($all[$key]);
            return $all;
        });
    }

    private static function key(): string
    {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        return hash('sha256', $ip . '|' . (string) App::config('app_secret', ''));
    }

    private static function file(): string
    {
        return App::storagePath('login_attempts.json');
    }

    /** @return array<string,array{count:int,first:int}> */
    private static function load(): array
    {
        $raw = @file_get_contents(self::file());
        $data = $raw ? json_decode($raw, true) : null;
        return is_array($data) ? $data : [];
    }

    private static function update(callable $fn): void
    {
        $fp = @fopen(self::file(), 'c+');
        if ($fp === false) {
            return;
        }
        try {
            flock($fp, LOCK_EX);
            $raw = stream_get_contents($fp);
            $all = $raw ? json_decode($raw, true) : [];
            $all = is_array($all) ? $all : [];
            // 期限切れの記録を掃除
            $now = time();
            foreach ($all as $k => $rec) {
                if (!is_array($rec) || ($now - (int) ($rec['first'] ?? 0)) >= self::WINDOW_SEC) {
                    unset($all[$k]);
                }
            }
            $all = $fn($all, self::key());
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, (string) json_encode($all));
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }
}
