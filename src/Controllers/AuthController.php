<?php

declare(strict_types=1);

namespace App\Controllers;

use App\App;
use App\Auth;
use App\AuthProviders;
use App\Http\Request;
use App\Http\Response;
use App\LoginThrottle;
use App\Support\HttpClient;
use App\View;

/**
 * ログイン（OpenID Connect プロバイダ / パスワード）。
 * どの方式でも、ログインできるのは許可されたユーザーだけ
 * （OIDC は allowed_emails / allowed_domains、パスワードは password_users に登録された人）。
 */
final class AuthController
{
    /** 存在しないユーザーでも同じ時間だけハッシュ検証するためのダミー（中身に意味はない） */
    private const DUMMY_HASH = '$2y$10$EG.GyG1P7nRAkOcY2YEr1uzqKReMwtFymX8n3pTbZ8v4tSmVcUIw2';

    public function showLogin(Request $request, array $params = []): void
    {
        if (Auth::check()) {
            Response::redirect('/');
            return;
        }
        $wv = webview_info();
        $providers = array_values(array_map(
            static fn (array $p): array => ['id' => $p['id'], 'label' => $p['label'], 'blocks_webview' => $p['blocks_webview']],
            AuthProviders::oidc()
        ));
        Response::html(View::render('login', [
            'error'           => $request->input('error'),
            'providers'       => $providers,
            'passwordEnabled' => AuthProviders::passwordEnabled(),
            'inApp'           => $wv['in_app'],
            'inAppName'       => $wv['name'],
            'inAppOs'         => $wv['os'],
        ], 'ログイン'));
    }

    /** パスワードでログイン（POST /login） */
    public function passwordLogin(Request $request): void
    {
        $users = AuthProviders::passwordUsers();
        if ($users === []) {
            $this->fail('パスワードログインは設定されていません。');
            return;
        }
        if (LoginThrottle::isLocked()) {
            $this->fail('ログインの失敗が続いたため、しばらく時間をおいてからお試しください。');
            return;
        }

        $email = strtolower(trim((string) $request->input('email', '')));
        $password = (string) $request->input('password', '');
        $user = $users[$email] ?? null;

        // ユーザーが存在しない場合もハッシュ検証を行い、応答時間で存在を推測されにくくする
        $valid = password_verify($password, $user['hash'] ?? self::DUMMY_HASH);
        if ($user === null || !$valid) {
            LoginThrottle::fail();
            $this->fail('メールアドレスまたはパスワードが違います。');
            return;
        }
        LoginThrottle::reset();
        Auth::login($email, $user['name'], true);
        Response::redirect('/');
    }

    /** プロバイダの同意画面へリダイレクト */
    public function oidcStart(Request $request, array $params): void
    {
        $p = AuthProviders::find((string) ($params['provider'] ?? ''));
        if ($p === null) {
            $this->fail('このログイン方式は設定されていません。');
            return;
        }

        // Google などはアプリ内ブラウザ(WebView)からのログインを拒否する。
        // 同意画面へ飛ばさず、OS標準ブラウザで開くよう案内画面へ戻す。
        $wv = webview_info();
        if ($p['blocks_webview'] && $wv['in_app']) {
            Response::redirect('/login?inapp=1');
            return;
        }

        try {
            $ep = AuthProviders::endpoints($p);
        } catch (\Throwable $e) {
            App::log('OIDC設定の取得に失敗: ' . $e->getMessage(), 'auth');
            $this->fail('ログイン先の設定を取得できませんでした。');
            return;
        }

        $state = bin2hex(random_bytes(16));
        $nonce = bin2hex(random_bytes(16));
        $verifier = self::b64url(random_bytes(32));
        $challenge = self::b64url(hash('sha256', $verifier, true));
        $_SESSION['oidc'] = [
            'provider' => $p['id'],
            'state'    => $state,
            'nonce'    => $nonce,
            'verifier' => $verifier,
            'issuer'   => $ep['issuer'],
            'token'    => $ep['token_endpoint'],
            'userinfo' => $ep['userinfo_endpoint'],
        ];

        $query = array_merge($p['extra_params'], [
            'client_id'             => $p['client_id'],
            'redirect_uri'          => $p['redirect_uri'],
            'response_type'         => 'code',
            'scope'                 => $p['scopes'],
            'state'                 => $state,
            'nonce'                 => $nonce,
            'code_challenge'        => $challenge,
            'code_challenge_method' => 'S256',
        ]);
        $sep = str_contains($ep['authorization_endpoint'], '?') ? '&' : '?';
        Response::redirect($ep['authorization_endpoint'] . $sep . http_build_query($query));
    }

    /** 旧URL /auth/google 互換 */
    public function googleStart(Request $request): void
    {
        $this->oidcStart($request, ['provider' => 'google']);
    }

    /** 旧URL /auth/google/callback 互換 */
    public function googleCallback(Request $request): void
    {
        $this->oidcCallback($request, ['provider' => 'google']);
    }

    /** プロバイダからのコールバック */
    public function oidcCallback(Request $request, array $params): void
    {
        $saved = $_SESSION['oidc'] ?? null;
        unset($_SESSION['oidc']);

        $state = (string) $request->input('state', '');
        $code = (string) $request->input('code', '');
        $p = AuthProviders::find((string) ($params['provider'] ?? ''));

        if (!is_array($saved) || $p === null || $saved['provider'] !== $p['id']
            || $state === '' || !hash_equals((string) $saved['state'], $state)) {
            $this->fail('認証セッションが無効です。もう一度お試しください。');
            return;
        }
        if ($code === '') {
            $this->fail('認証がキャンセルされました。');
            return;
        }

        try {
            [$status, $body] = HttpClient::postForm((string) $saved['token'], [
                'code'          => $code,
                'client_id'     => $p['client_id'],
                'client_secret' => $p['client_secret'],
                'redirect_uri'  => $p['redirect_uri'],
                'grant_type'    => 'authorization_code',
                'code_verifier' => (string) $saved['verifier'],
            ], ['Accept' => 'application/json']);
        } catch (\Throwable $e) {
            App::log('OIDCトークン交換失敗(' . $p['id'] . '): ' . $e->getMessage(), 'auth');
            $this->fail('ログインに失敗しました。');
            return;
        }
        if ($status !== 200 || empty($body['id_token'])) {
            App::log('OIDCトークン交換失敗(' . $p['id'] . '): HTTP ' . $status, 'auth');
            $this->fail('ログインに失敗しました。');
            return;
        }

        // ID トークンはトークンエンドポイントから TLS で直接受け取ったものなので、
        // OpenID Connect Core 3.1.3.7 に従い署名検証は省略し、クレームを検証する。
        $claims = self::decodeJwtPayload((string) $body['id_token']);
        if (!$claims) {
            $this->fail('IDトークンを解析できませんでした。');
            return;
        }
        $aud = $claims['aud'] ?? '';
        $audOk = is_array($aud) ? in_array($p['client_id'], $aud, true) : ($aud === $p['client_id']);
        $issuer = (string) $saved['issuer'];
        // Microsoft のマルチテナント（issuer に {tenantid} を含む）は issuer がテナントごとに変わるため照合しない
        $issOk = $issuer === '' || str_contains($issuer, '{')
            || rtrim((string) ($claims['iss'] ?? ''), '/') === rtrim($issuer, '/');
        $nonceOk = hash_equals((string) $saved['nonce'], (string) ($claims['nonce'] ?? ''));
        $expOk = (int) ($claims['exp'] ?? 0) >= time() - 60;
        if (!$audOk || !$issOk || !$nonceOk || !$expOk) {
            $this->fail('不正なトークンです。');
            return;
        }

        // ID トークンにメールが無いプロバイダは userinfo から取得する
        if (empty($claims['email']) && $saved['userinfo'] !== '' && !empty($body['access_token'])) {
            try {
                [$st, $info] = HttpClient::requestJson('GET', (string) $saved['userinfo'], [
                    'Authorization' => 'Bearer ' . $body['access_token'],
                ], null, 20);
                if ($st === 200 && isset($info['sub']) && $info['sub'] === ($claims['sub'] ?? null)) {
                    foreach (['email', 'email_verified', 'name', 'given_name', 'preferred_username'] as $k) {
                        if (!isset($claims[$k]) && isset($info[$k])) {
                            $claims[$k] = $info[$k];
                        }
                    }
                }
            } catch (\Throwable $e) {
                App::log('OIDC userinfo取得失敗(' . $p['id'] . '): ' . $e->getMessage(), 'auth');
            }
        }

        $email = strtolower(trim((string) ($claims['email'] ?? '')));
        $verified = filter_var($claims['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if ($email === '' || ($p['require_verified_email'] && !$verified)) {
            $this->fail('メールアドレスが確認できませんでした。');
            return;
        }

        $name = trim((string) ($claims['name'] ?? ''));
        if ($name === '') {
            $name = trim((string) ($claims['given_name'] ?? ($claims['preferred_username'] ?? '')));
        }

        if (!Auth::login($email, $name)) {
            $this->fail('このアカウント（' . $email . '）はログインを許可されていません。');
            return;
        }
        Response::redirect('/');
    }

    public function logout(Request $request): void
    {
        Auth::logout();
        Response::redirect('/login');
    }

    private function fail(string $message): void
    {
        Response::redirect('/login?error=' . rawurlencode($message));
    }

    private static function b64url(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    /** JWTのペイロード(base64url)をデコード */
    private static function decodeJwtPayload(string $jwt): ?array
    {
        $parts = explode('.', $jwt);
        if (count($parts) < 2) {
            return null;
        }
        $payload = strtr($parts[1], '-_', '+/');
        $pad = strlen($payload) % 4;
        if ($pad) {
            $payload .= str_repeat('=', 4 - $pad);
        }
        $json = base64_decode($payload, true);
        if ($json === false) {
            return null;
        }
        $data = json_decode($json, true);
        return is_array($data) ? $data : null;
    }
}
