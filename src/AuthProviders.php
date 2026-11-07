<?php

declare(strict_types=1);

namespace App;

use App\Support\HttpClient;

/**
 * ログイン方式の一覧（config から組み立てる）。
 *
 * - OpenID Connect プロバイダ（Google / Microsoft / GitLab / Keycloak / Auth0 / Okta / Authentik など）
 *     config の oidc_providers に並べる。Google だけは従来の google_client_id 等でも設定できる。
 * - パスワードログイン
 *     config の password_users に、メール・表示名・password_hash() のハッシュを並べる。
 */
final class AuthProviders
{
    /** よく使うプロバイダの既定値（issuer の書き方・ボタン表記など） */
    private const PRESETS = [
        'google' => [
            'label'                  => 'Google でログイン',
            'issuer'                 => 'https://accounts.google.com',
            'authorization_endpoint' => 'https://accounts.google.com/o/oauth2/v2/auth',
            'token_endpoint'         => 'https://oauth2.googleapis.com/token',
            'userinfo_endpoint'      => 'https://openidconnect.googleapis.com/v1/userinfo',
            'extra_params'           => ['access_type' => 'online', 'prompt' => 'select_account'],
            // Google はアプリ内ブラウザ（LINE 等）からのログインを 403 disallowed_useragent で拒否する
            'blocks_webview'         => true,
        ],
        'microsoft' => [
            'label'  => 'Microsoft でログイン',
            // tenant は 'common' / 'organizations' / 'consumers' / テナントID
            'issuer' => 'https://login.microsoftonline.com/{tenant}/v2.0',
            'tenant' => 'common',
            // Microsoft の ID トークンには email_verified が無いことが多い
            'require_verified_email' => false,
            'extra_params' => ['prompt' => 'select_account'],
        ],
        'gitlab' => [
            'label'  => 'GitLab でログイン',
            'issuer' => 'https://gitlab.com',
        ],
    ];

    /** @var array<string,array<string,mixed>>|null */
    private static ?array $cache = null;

    /**
     * 有効な OpenID Connect プロバイダ（id => 設定）。
     * @return array<string,array<string,mixed>>
     */
    public static function oidc(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $out = [];

        // 従来の Google 設定（google_client_id / google_client_secret / google_redirect_uri）
        $gid = (string) App::config('google_client_id', '');
        if ($gid !== '') {
            $out['google'] = self::normalize('google', [
                'preset'        => 'google',
                'client_id'     => $gid,
                'client_secret' => (string) App::config('google_client_secret', ''),
                'redirect_uri'  => (string) App::config('google_redirect_uri', ''),
            ]);
        }

        foreach ((array) App::config('oidc_providers', []) as $key => $p) {
            if (!is_array($p) || empty($p['client_id'])) {
                continue;
            }
            $id = (string) ($p['id'] ?? (is_string($key) ? $key : ($p['preset'] ?? '')));
            $id = strtolower((string) preg_replace('/[^A-Za-z0-9_-]/', '', $id));
            if ($id === '' || isset($out[$id])) {
                continue;
            }
            $out[$id] = self::normalize($id, $p);
        }

        return self::$cache = $out;
    }

    /** @return array<string,mixed>|null */
    public static function find(string $id): ?array
    {
        return self::oidc()[$id] ?? null;
    }

    /** パスワードログイン用のユーザー（メール小文字 => [name, hash]） */
    public static function passwordUsers(): array
    {
        $out = [];
        foreach ((array) App::config('password_users', []) as $u) {
            if (!is_array($u)) {
                continue;
            }
            $email = strtolower(trim((string) ($u['email'] ?? '')));
            $hash = (string) ($u['password_hash'] ?? '');
            if ($email === '' || $hash === '') {
                continue;
            }
            $out[$email] = ['name' => trim((string) ($u['name'] ?? '')), 'hash' => $hash];
        }
        return $out;
    }

    public static function passwordEnabled(): bool
    {
        return self::passwordUsers() !== [];
    }

    /** ログイン方式がひとつでも設定されているか */
    public static function any(): bool
    {
        return self::oidc() !== [] || self::passwordEnabled();
    }

    /**
     * 認可・トークン・ユーザー情報のエンドポイントと issuer を返す。
     * 設定に無ければ {issuer}/.well-known/openid-configuration から取得する。
     * @return array{authorization_endpoint:string,token_endpoint:string,userinfo_endpoint:string,issuer:string}
     */
    public static function endpoints(array $p): array
    {
        $ep = [
            'authorization_endpoint' => (string) ($p['authorization_endpoint'] ?? ''),
            'token_endpoint'         => (string) ($p['token_endpoint'] ?? ''),
            'userinfo_endpoint'      => (string) ($p['userinfo_endpoint'] ?? ''),
            'issuer'                 => (string) ($p['issuer'] ?? ''),
        ];
        if ($ep['authorization_endpoint'] !== '' && $ep['token_endpoint'] !== '') {
            return $ep;
        }
        if ($ep['issuer'] === '' || !str_starts_with($ep['issuer'], 'https://')) {
            throw new \RuntimeException('OIDC の issuer（https://〜）が設定されていません: ' . $p['id']);
        }
        [$status, $doc] = HttpClient::requestJson(
            'GET',
            rtrim($ep['issuer'], '/') . '/.well-known/openid-configuration',
            [],
            null,
            20
        );
        if ($status !== 200 || empty($doc['authorization_endpoint']) || empty($doc['token_endpoint'])) {
            throw new \RuntimeException('OIDC の設定情報（discovery）を取得できません: ' . $p['id'] . ' HTTP ' . $status);
        }
        foreach (['authorization_endpoint', 'token_endpoint', 'userinfo_endpoint'] as $k) {
            if ($ep[$k] === '' && !empty($doc[$k])) {
                $ep[$k] = (string) $doc[$k];
            }
        }
        if (!empty($doc['issuer'])) {
            $ep['issuer'] = (string) $doc['issuer'];
        }
        return $ep;
    }

    /** ログイン後に戻ってくる URL（未指定なら現在のホストから組み立てる） */
    public static function defaultRedirectUri(string $id): string
    {
        $scheme = Auth::isHttps() ? 'https' : 'http';
        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        return $scheme . '://' . $host . url('/auth/oidc/' . rawurlencode($id) . '/callback');
    }

    /** @return array<string,mixed> */
    private static function normalize(string $id, array $p): array
    {
        $preset = self::PRESETS[(string) ($p['preset'] ?? $id)] ?? [];
        $p = array_replace($preset, $p);
        $p['id'] = $id;
        $p['label'] = (string) ($p['label'] ?? ($id . ' でログイン'));
        $p['scopes'] = (string) ($p['scopes'] ?? 'openid email profile');
        $p['require_verified_email'] = (bool) ($p['require_verified_email'] ?? true);
        $p['blocks_webview'] = (bool) ($p['blocks_webview'] ?? false);
        $p['extra_params'] = (array) ($p['extra_params'] ?? []);
        $p['client_secret'] = (string) ($p['client_secret'] ?? '');
        if (isset($p['issuer'])) {
            $p['issuer'] = str_replace('{tenant}', (string) ($p['tenant'] ?? 'common'), (string) $p['issuer']);
        }
        if (empty($p['redirect_uri'])) {
            $p['redirect_uri'] = self::defaultRedirectUri($id);
        }
        return $p;
    }
}
