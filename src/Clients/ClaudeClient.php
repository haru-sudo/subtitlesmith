<?php

declare(strict_types=1);

namespace App\Clients;

use App\App;
use App\Support\HttpClient;

/**
 * Claude (Anthropic Messages API) クライアント。
 * 翻訳に用いる。Prompt Caching / 構造化出力は呼び出し側がparamsで指定する。
 */
final class ClaudeClient
{
    private string $apiKey;

    public function __construct(?string $apiKey = null)
    {
        $this->apiKey = $apiKey ?? (string) \App\Settings::get('anthropic_api_key', '');
    }

    private function requireKey(): void
    {
        if ($this->apiKey === '') {
            throw new \RuntimeException('Anthropic APIキーが未設定です。設定ページの「APIキー」から登録してください。');
        }
    }

    /**
     * Messages API を呼び出し、デコード済みレスポンスを返す。
     *
     * @param array $params model/max_tokens/system/messages/output_config 等
     */
    public function message(array $params): array
    {
        $this->requireKey();

        $params += [
            'model'      => \App\Models::default(),
            'max_tokens' => 8000,
        ];

        $headers = [
            'x-api-key'         => $this->apiKey,
            'anthropic-version' => '2023-06-01',
        ];

        [$status, $body] = HttpClient::requestJson(
            'POST',
            'https://api.anthropic.com/v1/messages',
            $headers,
            $params
        );

        if ($status !== 200) {
            $msg = $body['error']['message'] ?? json_encode($body, JSON_UNESCAPED_UNICODE);
            throw new \RuntimeException("翻訳APIエラー($status): $msg");
        }

        return $body;
    }

    /** レスポンスからテキストブロックを連結して返す */
    public static function textFrom(array $response): string
    {
        $out = '';
        foreach (($response['content'] ?? []) as $block) {
            if (($block['type'] ?? '') === 'text') {
                $out .= $block['text'] ?? '';
            }
        }
        return $out;
    }

    public function usage(array $response): array
    {
        return $response['usage'] ?? [];
    }
}
