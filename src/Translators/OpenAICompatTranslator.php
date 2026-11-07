<?php

declare(strict_types=1);

namespace App\Translators;

use App\Settings;
use App\Support\HttpClient;

/**
 * OpenAI互換の汎用翻訳プロバイダ。
 * base_url と APIキー、モデル名を設定するだけで、
 * Groq / OpenRouter / DeepSeek / xAI(Grok) / NVIDIA Build などに接続できる。
 *
 * /chat/completions を叩き、JSON出力を要求する（response_format=json_object）。
 * 互換実装によっては response_format 非対応のことがあるため、
 * プロンプト側でもJSONのみを返すよう指示し、生成テキストからJSONを抽出する。
 */
final class OpenAICompatTranslator implements TranslatorInterface
{
    public function name(): string
    {
        $m = self::model();
        return 'OpenAI互換' . ($m !== '' ? '（' . $m . '）' : '');
    }

    public function isConfigured(): bool
    {
        return self::baseUrl() !== ''
            && Settings::isConfigured('openai_compat_api_key')
            && Settings::isConfigured('openai_compat_model');
    }

    private static function baseUrl(): string
    {
        // プリセットIDからベースURLを解決。プリセット未選択時は手入力URLにフォールバック。
        $preset = (string) Settings::get('openai_compat_preset', '');
        if ($preset !== '' && $preset !== 'custom') {
            $url = \App\CompatPresets::baseUrl($preset);
            if ($url !== '') {
                return rtrim($url, '/');
            }
        }
        return rtrim((string) Settings::get('openai_compat_base_url', ''), '/');
    }

    private static function model(): string
    {
        return (string) Settings::get('openai_compat_model', '');
    }

    public function translateJson(string $systemText, string $userText, int $maxTokens, string $modelOverride = ''): array
    {
        $base = self::baseUrl();
        $key  = (string) Settings::get('openai_compat_api_key', '');
        // Claude/Gemini のIDが誤って渡された場合は設定のモデルにフォールバック
        $isOther = (strncmp($modelOverride, 'claude', 6) === 0) || (strncmp($modelOverride, 'gemini', 6) === 0);
        $model = ($modelOverride !== '' && !$isOther) ? $modelOverride : self::model();
        if ($base === '' || $key === '' || $model === '') {
            throw new \RuntimeException('OpenAI互換プロバイダの設定が不足しています（base_URL・APIキー・モデル名を確認）。');
        }

        // 末尾が /chat/completions でなければ補う（base_urlは .../v1 を想定）
        $url = (substr($base, -16) === '/chat/completions') ? $base : $base . '/chat/completions';

        $body = [
            'model'    => $model,
            'messages' => [
                ['role' => 'system', 'content' => $systemText
                    . "\n\n重要: 出力は指定どおりのJSONのみ。前置きやコードフェンスを付けないこと。"],
                ['role' => 'user', 'content' => $userText],
            ],
            'temperature' => 0.2,
            'max_tokens'  => $maxTokens,
            'response_format' => ['type' => 'json_object'],
        ];

        [$status, $resp] = HttpClient::requestJson('POST', $url, [
            'Authorization' => 'Bearer ' . $key,
        ], $body, 300);

        // response_format 非対応で 400 が返る実装向けに、一度だけ外して再試行
        if ($status === 400) {
            unset($body['response_format']);
            [$status, $resp] = HttpClient::requestJson('POST', $url, [
                'Authorization' => 'Bearer ' . $key,
            ], $body, 300);
        }

        if ($status < 200 || $status >= 300) {
            $msg = $resp['error']['message'] ?? ($resp['error'] ?? ($resp['raw'] ?? 'unknown'));
            if (is_array($msg)) {
                $msg = json_encode($msg, JSON_UNESCAPED_UNICODE);
            }
            throw new \RuntimeException('翻訳APIエラー(' . $status . '): ' . $msg);
        }

        $text = (string) ($resp['choices'][0]['message']['content'] ?? '');
        return self::extractJson($text);
    }

    /** モデルがコードフェンスや前置きを付けても拾えるよう、JSON部分を抽出してデコード */
    private static function extractJson(string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }
        // そのままデコードできれば即返す
        $data = json_decode($text, true);
        if (is_array($data)) {
            return $data;
        }
        // ```json ... ``` を除去
        $stripped = preg_replace('/^```[a-zA-Z]*\s*|\s*```$/m', '', $text);
        $data = json_decode(trim((string) $stripped), true);
        if (is_array($data)) {
            return $data;
        }
        // 最初の { から最後の } までを抜き出す
        $start = strpos($text, '{');
        $end   = strrpos($text, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $data = json_decode(substr($text, $start, $end - $start + 1), true);
            if (is_array($data)) {
                return $data;
            }
        }
        return [];
    }
}
