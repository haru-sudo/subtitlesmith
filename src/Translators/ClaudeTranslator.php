<?php

declare(strict_types=1);

namespace App\Translators;

use App\Clients\ClaudeClient;
use App\Models;
use App\Settings;

/**
 * Claude（Anthropic）翻訳プロバイダ。
 * プロンプトキャッシュとJSONスキーマ出力を活用する（最高精度）。
 */
final class ClaudeTranslator implements TranslatorInterface
{
    private ClaudeClient $client;

    public function __construct()
    {
        $this->client = new ClaudeClient();
    }

    public function name(): string
    {
        return 'Claude';
    }

    public function isConfigured(): bool
    {
        return Settings::isConfigured('anthropic_api_key');
    }

    public function translateJson(string $systemText, string $userText, int $maxTokens, string $modelOverride = ''): array
    {
        // システムブロック（プロンプトキャッシュは設定で制御）
        $sysBlock = ['type' => 'text', 'text' => $systemText];
        if (Settings::getBool('prompt_cache', true)) {
            $sysBlock['cache_control'] = ['type' => 'ephemeral'];
        }

        $params = [
            'max_tokens' => $maxTokens,
            'system'     => [$sysBlock],
            'messages'   => [[
                'role'    => 'user',
                'content' => $userText,
            ]],
            'output_config' => [
                'format' => ['type' => 'json_schema', 'schema' => self::schema()],
            ],
        ];
        $model = $modelOverride !== '' ? $modelOverride : '';
        if ($model !== '' && Models::isValid($model)) {
            $params['model'] = $model;
        }

        $resp = $this->client->message($params);
        $text = ClaudeClient::textFrom($resp);
        $data = json_decode($text, true);
        return is_array($data) ? $data : [];
    }

    /** translations[].{id,ja} のJSONスキーマ */
    private static function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'translations' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'id' => ['type' => 'integer'],
                            'ja' => ['type' => 'string'],
                        ],
                        'required' => ['id', 'ja'],
                    ],
                ],
            ],
            'required' => ['translations'],
        ];
    }
}
