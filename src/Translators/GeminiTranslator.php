<?php

declare(strict_types=1);

namespace App\Translators;

use App\App;
use App\Settings;
use App\Support\HttpClient;

/**
 * Google Gemini 翻訳プロバイダ。
 * generateContent エンドポイントを使い、JSON出力モード(responseMimeType)で受け取る。
 * 無料枠あり（モデルにより1日あたりのリクエスト上限あり・要確認）。
 */
final class GeminiTranslator implements TranslatorInterface
{
    public function name(): string
    {
        return 'Gemini ' . self::model();
    }

    public function isConfigured(): bool
    {
        return Settings::isConfigured('gemini_api_key');
    }

    private static function model(): string
    {
        $m = (string) Settings::get('gemini_model', 'gemini-2.5-flash');
        return $m !== '' ? $m : 'gemini-2.5-flash';
    }

    /** アップロード画面で選べるGeminiモデル一覧（コスト/特徴つき） */
    public static function models(): array
    {
        return [
            ['id' => 'gemini-2.5-flash', 'name' => 'Gemini 2.5 Flash', 'note' => '無料枠あり・高速。日本語に強い。'],
            ['id' => 'gemini-2.5-pro',   'name' => 'Gemini 2.5 Pro',   'note' => '高精度。難しい内容向け（無料枠は少なめ）。'],
            ['id' => 'gemini-2.0-flash', 'name' => 'Gemini 2.0 Flash', 'note' => '旧世代の高速モデル。'],
        ];
    }

    public function translateJson(string $systemText, string $userText, int $maxTokens, string $modelOverride = ''): array
    {
        $apiKey = (string) Settings::get('gemini_api_key', '');
        if ($apiKey === '') {
            throw new \RuntimeException('Gemini APIキーが未設定です（設定ページで登録してください）。');
        }
        // 上書きが Gemini 用モデルのときだけ採用（他プロバイダのIDが渡っても無視）
        $model = (strncmp($modelOverride, 'gemini', 6) === 0) ? $modelOverride : self::model();

        $url = 'https://generativelanguage.googleapis.com/v1beta/models/'
            . rawurlencode($model) . ':generateContent?key=' . rawurlencode($apiKey);

        $body = [
            'systemInstruction' => [
                'parts' => [['text' => $systemText]],
            ],
            'contents' => [[
                'role'  => 'user',
                'parts' => [['text' => $userText]],
            ]],
            'generationConfig' => [
                'temperature'      => 0.2,
                'maxOutputTokens'  => $maxTokens,
                'responseMimeType' => 'application/json',
                'responseSchema'   => self::schema(),
            ],
        ];

        [$status, $resp] = HttpClient::requestJson('POST', $url, [], $body, 300);
        if ($status < 200 || $status >= 300) {
            $msg = $resp['error']['message'] ?? ($resp['raw'] ?? 'unknown');
            throw new \RuntimeException('Gemini APIエラー(' . $status . '): ' . $msg);
        }

        // candidates[0].content.parts[].text を連結
        $text = '';
        foreach (($resp['candidates'][0]['content']['parts'] ?? []) as $p) {
            $text .= (string) ($p['text'] ?? '');
        }
        $data = json_decode(trim($text), true);
        return is_array($data) ? $data : [];
    }

    /** Gemini の responseSchema（OpenAPI風） */
    private static function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'translations' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
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
