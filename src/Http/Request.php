<?php

declare(strict_types=1);

namespace App\Http;

/**
 * HTTPリクエストの薄いラッパ。
 */
final class Request
{
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $post,
        public readonly ?array $json,
        public readonly array $files,
    ) {
    }

    public static function capture(): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        // メソッドオーバーライド（HTMLフォームからのPUT/DELETE）
        if ($method === 'POST' && isset($_POST['_method'])) {
            $method = strtoupper((string) $_POST['_method']);
        }

        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';

        // ベースパス（サブディレクトリ設置時の前置 "/subtitlesmith" 等）を除去
        $base = defined('APP_BASE') ? APP_BASE : '';
        if ($base !== '' && (strncmp($path, $base . '/', strlen($base) + 1) === 0 || $path === $base)) {
            $path = substr($path, strlen($base));
        }

        $path = rtrim($path, '/');
        if ($path === '') {
            $path = '/';
        }

        $json = null;
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (stripos($contentType, 'application/json') !== false) {
            $raw = file_get_contents('php://input') ?: '';
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $json = $decoded;
            }
        }

        return new self($method, $path, $_GET, $_POST, $json, $_FILES);
    }

    /** json → post → query の順で取得 */
    public function input(string $key, mixed $default = null): mixed
    {
        if ($this->json !== null && array_key_exists($key, $this->json)) {
            return $this->json[$key];
        }
        if (array_key_exists($key, $this->post)) {
            return $this->post[$key];
        }
        if (array_key_exists($key, $this->query)) {
            return $this->query[$key];
        }
        return $default;
    }

    public function file(string $key): ?array
    {
        return $this->files[$key] ?? null;
    }

    public function wantsJson(): bool
    {
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        return $this->json !== null || stripos($accept, 'application/json') !== false;
    }
}
