<?php

declare(strict_types=1);

namespace App\Controllers;

use App\App;
use App\Http\Request;
use App\Http\Response;
use App\Settings;
use App\Support\Proc;
use App\View;

/**
 * 環境セルフチェック（デプロイ後の動作確認用）。ログイン必須。
 *
 * 最適化:
 *  - ffmpeg/ffprobe/python のバージョン確認は外部プロセス起動が重いので、
 *    結果をファイルにキャッシュ（既定6時間）。?refresh=1 で強制再チェック。
 *  - PHP拡張は1行にまとめて表示（不足分のみ列挙）。
 */
final class HealthController
{
    /** 実行ファイルチェック結果のキャッシュ有効期間（秒） */
    private const BIN_CACHE_TTL = 21600; // 6時間

    public function index(Request $request): void
    {
        $refresh = isset($_GET['refresh']);
        $checks = [];

        // PHP 本体
        $checks[] = [
            'PHP バージョン',
            version_compare(PHP_VERSION, '8.1.0', '>=') ? 'ok' : 'ng',
            PHP_VERSION . '（8.1以上が必要）',
        ];

        // PHP拡張（1行にまとめ、不足分のみ列挙）
        $required = ['pdo_sqlite', 'curl', 'mbstring', 'json', 'fileinfo'];
        $missing = array_values(array_filter($required, static fn(string $e): bool => !extension_loaded($e)));
        $checks[] = [
            'PHP拡張（' . implode(', ', $required) . '）',
            $missing === [] ? 'ok' : 'ng',
            $missing === []
                ? 'すべて有効'
                : '無効: ' . implode(', ', $missing) . '（php.ini で有効化してください）',
        ];

        // ffmpeg / ffprobe（キャッシュ付き）
        $checks[] = self::binCheck('ffmpeg', (string) App::config('ffmpeg', 'ffmpeg'), '-version', false, $refresh);
        $checks[] = self::binCheck('ffprobe', (string) App::config('ffprobe', 'ffprobe'), '-version', false, $refresh);

        // storage 書き込み（実際に書いて確認: is_writableはWindowsで不正確なため）
        $storage = (string) App::config('storage_dir');
        $writable = false;
        if (is_dir($storage)) {
            $probe = $storage . '/.write_test_' . bin2hex(random_bytes(3));
            if (@file_put_contents($probe, 'x') !== false) {
                $writable = true;
                @unlink($probe);
            }
        }
        $checks[] = ['storage 書き込み', $writable ? 'ok' : 'ng', $storage];

        // DB
        try {
            App::db()->fetchValue('SELECT 1');
            $checks[] = ['データベース(SQLite)', 'ok', (string) App::config('db_path')];
        } catch (\Throwable $e) {
            $checks[] = ['データベース(SQLite)', 'ng', $e->getMessage()];
        }

        // ログイン方式
        $providers = \App\AuthProviders::oidc();
        $pwUsers = count(\App\AuthProviders::passwordUsers());
        $methods = [];
        foreach ($providers as $p) {
            $methods[] = $p['id'] . '（redirect: ' . $p['redirect_uri'] . '）';
        }
        if ($pwUsers > 0) {
            $methods[] = 'パスワード（' . $pwUsers . ' 人）';
        }
        $checks[] = [
            'ログイン方式',
            $methods ? 'ok' : 'ng',
            $methods ? implode(' / ', $methods) : 'password_users か oidc_providers を設定してください',
        ];

        // 許可リスト（OIDC でログインする人）
        if ($providers) {
            $allowedCount = count((array) App::config('allowed_emails', []));
            $domainCount = count((array) App::config('allowed_domains', []));
            $checks[] = [
                'ログイン許可リスト',
                ($allowedCount + $domainCount) > 0 ? 'ok' : 'warn',
                'メール ' . $allowedCount . ' 件 / ドメイン ' . $domainCount . ' 件',
            ];
        }

        // APIキー（設定ページ or config.php）。isConfigured は1キー1回だけ呼ぶ。
        foreach ([
            'openai_api_key'    => 'OpenAI APIキー(文字起こし/埋め込み)',
            'anthropic_api_key' => 'Anthropic APIキー(翻訳)',
        ] as $key => $label) {
            $set = Settings::isConfigured($key);
            $checks[] = [
                $label,
                $set ? 'ok' : 'warn',
                $set ? '設定済み' : '未設定（設定ページで登録してください）',
            ];
        }

        // Python(OCR, 任意)（キャッシュ付き）
        $checks[] = self::binCheck('Python(OCR用・任意)', (string) App::config('python', 'python'), '--version', true, $refresh);

        Response::html(View::render('health', ['checks' => $checks], '環境チェック'));
    }

    /**
     * 実行ファイルの存在/動作チェック。
     * バージョン出力は変化しないため、結果をTTL付きでキャッシュして
     * ページ表示ごとの外部プロセス起動を避ける。
     */
    private static function binCheck(
        string $label,
        string $bin,
        string $verArg = '-version',
        bool $optional = false,
        bool $refresh = false
    ): array {
        $cacheFile = self::binCacheFile($bin, $verArg);

        if (!$refresh && $cacheFile !== null && is_file($cacheFile)
            && (time() - (int) filemtime($cacheFile)) < self::BIN_CACHE_TTL) {
            $cached = json_decode((string) @file_get_contents($cacheFile), true);
            if (is_array($cached) && isset($cached['ok'], $cached['detail'])) {
                return $cached['ok']
                    ? [$label, 'ok', (string) $cached['detail']]
                    : [$label, $optional ? 'warn' : 'ng', (string) $cached['detail']];
            }
        }

        $ok = false;
        $detail = '';
        try {
            [$code, $out, $err] = Proc::run($bin, [$verArg]);
            if ($code === 0) {
                $ok = true;
                $detail = trim((string) strtok($out !== '' ? $out : $err, "\n"));
            } else {
                $detail = "実行できません（パス: $bin）";
            }
        } catch (\Throwable $e) {
            $detail = "見つかりません（config の該当パスを確認: $bin）";
        }

        if ($cacheFile !== null) {
            @file_put_contents($cacheFile, json_encode(['ok' => $ok, 'detail' => $detail], JSON_UNESCAPED_UNICODE));
        }

        return $ok ? [$label, 'ok', $detail] : [$label, $optional ? 'warn' : 'ng', $detail];
    }

    /** キャッシュファイルのパス（storage が無ければ null = キャッシュ無効） */
    private static function binCacheFile(string $bin, string $verArg): ?string
    {
        $storage = rtrim((string) App::config('storage_dir', ''), '/\\');
        if ($storage === '' || !is_dir($storage)) {
            return null;
        }
        return $storage . '/.health_bin_' . substr(sha1($bin . ' ' . $verArg), 0, 16) . '.json';
    }
}
