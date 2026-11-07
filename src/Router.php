<?php

declare(strict_types=1);

namespace App;

use App\Http\Request;
use App\Http\Response;

/**
 * 最小ルータ。{param} を名前付きキャプチャに変換してマッチング。
 */
final class Router
{
    /** @var array<int, array{method:string, regex:string, handler:callable, auth:bool}> */
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler, bool $auth = true): void
    {
        $regex = preg_replace('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', '(?P<$1>[^/]+)', $pattern);
        $regex = '#^' . $regex . '$#';
        $this->routes[] = [
            'method'  => strtoupper($method),
            'regex'   => $regex,
            'handler' => $handler,
            'auth'    => $auth,
        ];
    }

    public function get(string $p, callable $h, bool $auth = true): void
    {
        $this->add('GET', $p, $h, $auth);
    }

    public function post(string $p, callable $h, bool $auth = true): void
    {
        $this->add('POST', $p, $h, $auth);
    }

    public function put(string $p, callable $h, bool $auth = true): void
    {
        $this->add('PUT', $p, $h, $auth);
    }

    public function delete(string $p, callable $h, bool $auth = true): void
    {
        $this->add('DELETE', $p, $h, $auth);
    }

    public function dispatch(Request $request): void
    {
        $pathMatched = false;

        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $request->path, $m)) {
                continue;
            }
            $pathMatched = true;
            if ($route['method'] !== $request->method) {
                continue;
            }

            // CSRF 対策: 状態を変えるリクエストは同じサイトからのものだけ受け付ける
            if (!in_array($request->method, ['GET', 'HEAD', 'OPTIONS'], true) && !Auth::isSameOrigin()) {
                if (str_starts_with($request->path, '/api/') || $request->wantsJson()) {
                    Response::json(['error' => 'forbidden', 'message' => '別のサイトからのリクエストは受け付けません。'], 403);
                } else {
                    Response::html('<h1>403</h1><p>別のサイトからのリクエストは受け付けません。</p>', 403);
                }
                return;
            }

            // 認証が必要なルートのガード
            if ($route['auth'] && !Auth::check()) {
                $isApi = str_starts_with($request->path, '/api/');
                if ($isApi || $request->wantsJson()) {
                    Response::json(['error' => 'unauthorized'], 401);
                } else {
                    Response::redirect('/login');
                }
                return;
            }

            $params = [];
            foreach ($m as $k => $v) {
                if (!is_int($k)) {
                    $params[$k] = $v;
                }
            }

            try {
                ($route['handler'])($request, $params);
            } catch (\Throwable $e) {
                App::log('Unhandled: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine(), 'error');
                if ($request->wantsJson()) {
                    Response::json(['error' => 'server_error', 'message' => $e->getMessage()], 500);
                } else {
                    Response::error('サーバーエラーが発生しました。');
                }
            }
            return;
        }

        if ($pathMatched) {
            Response::html('<h1>405</h1><p>許可されていないメソッドです。</p>', 405);
        } else {
            Response::notFound();
        }
    }
}
