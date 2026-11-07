<?php

declare(strict_types=1);

namespace App;

/**
 * 認証（Google OAuth + 許可メールのallowlist）。
 * ログインできるのは config の allowed_emails に含まれるメールのみ。
 */
final class Auth
{
    public static function startSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_name('subtitlesmith_session');
            session_set_cookie_params([
                'httponly' => true,
                'secure'   => self::isHttps(),
                'samesite' => 'Lax',
            ]);
            session_start();
        }
    }

    /** HTTPS でアクセスされているか（リバースプロキシ経由の X-Forwarded-Proto も考慮） */
    public static function isHttps(): bool
    {
        $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
        if ($https !== '' && $https !== 'off') {
            return true;
        }
        if ((string) ($_SERVER['SERVER_PORT'] ?? '') === '443') {
            return true;
        }
        $proto = strtolower(trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
        return $proto === 'https';
    }

    /**
     * 状態を変えるリクエスト（POST/PUT/DELETE など）が同じサイトから来たかを確認する（CSRF 対策）。
     * ブラウザが付ける Origin（無ければ Referer）のホストが、このサーバーのホストと一致するときだけ許可する。
     * どちらのヘッダーも無いリクエスト（curl など、ブラウザ以外）は許可する。
     */
    public static function isSameOrigin(): bool
    {
        $source = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
        if ($source === '' || $source === 'null') {
            $source = (string) ($_SERVER['HTTP_REFERER'] ?? '');
        }
        if ($source === '') {
            return true;
        }
        $srcHost = strtolower((string) parse_url($source, PHP_URL_HOST));
        if ($srcHost === '') {
            return false;
        }
        $srcPort = parse_url($source, PHP_URL_PORT);
        if (is_int($srcPort)) {
            $srcHost .= ':' . $srcPort;
        }

        $strip = static fn (string $h): string => (string) preg_replace('/:(80|443)$/', '', $h);
        foreach (['HTTP_HOST', 'HTTP_X_FORWARDED_HOST'] as $key) {
            $h = strtolower(trim(explode(',', (string) ($_SERVER[$key] ?? ''))[0]));
            if ($h !== '' && $strip($h) === $strip($srcHost)) {
                return true;
            }
        }
        return false;
    }

    public static function check(): bool
    {
        return !empty($_SESSION['authenticated']);
    }

    public static function email(): ?string
    {
        return $_SESSION['user_email'] ?? null;
    }

    /** 表示名（確認者の記録などに使う）。未設定ならメールのローカル部にフォールバック。 */
    public static function name(): ?string
    {
        $name = $_SESSION['user_name'] ?? '';
        if (is_string($name) && trim($name) !== '') {
            return trim($name);
        }
        $email = self::email();
        if ($email) {
            $local = explode('@', $email)[0];
            return $local !== '' ? $local : $email;
        }
        return null;
    }

    /**
     * メールが許可されているか（大文字小文字無視）。
     *  - allowed_emails  … メールアドレス単位で許可
     *  - allowed_domains … ドメイン単位で許可（例: 'example.com' なら @example.com の全員）
     */
    public static function isAllowed(string $email): bool
    {
        $email = strtolower(trim($email));
        if ($email === '' || substr_count($email, '@') !== 1) {
            return false;
        }
        foreach ((array) App::config('allowed_emails', []) as $a) {
            if (strtolower(trim((string) $a)) === $email) {
                return true;
            }
        }
        $domain = substr($email, strpos($email, '@') + 1);
        foreach ((array) App::config('allowed_domains', []) as $d) {
            $d = ltrim(strtolower(trim((string) $d)), '@');
            if ($d !== '' && $d === $domain) {
                return true;
            }
        }
        return false;
    }

    /**
     * ログインさせる。成功で true。
     * @param bool $preAuthorized true なら許可リストを確認しない（password_users に登録済みのユーザー）
     */
    public static function login(string $email, string $name = '', bool $preAuthorized = false): bool
    {
        if (!$preAuthorized && !self::isAllowed($email)) {
            return false;
        }
        session_regenerate_id(true);
        $_SESSION['authenticated'] = true;
        $_SESSION['user_email'] = strtolower(trim($email));
        $_SESSION['user_name'] = trim($name);
        return true;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }
}
