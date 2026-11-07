<?php

declare(strict_types=1);

namespace App;

/**
 * OpenAI互換プロバイダの「プリセット」一覧。
 * 一般ユーザーがベースURLを手入力しなくて済むよう、よく使うサービスを事前定義する。
 * ユーザーはプリセットを選んでAPIキーを入れるだけ。モデルも候補から選べる。
 */
final class CompatPresets
{
    /**
     * @return array<int,array{
     *   id:string, name:string, base_url:string, key_hint:string,
     *   free:bool, note:string, models:array<int,string>, signup:string
     * }>
     */
    public static function list(): array
    {
        return [
            [
                'id' => 'groq',
                'name' => 'Groq',
                'base_url' => 'https://api.groq.com/openai/v1',
                'key_hint' => 'gsk_...',
                'free' => true,
                'note' => '無料枠あり・非常に高速。オープンモデル（Llama等）。',
                'models' => [
                    'llama-3.3-70b-versatile',
                    'llama-3.1-8b-instant',
                    'qwen-2.5-32b',
                ],
                'signup' => 'https://console.groq.com',
            ],
            [
                'id' => 'openrouter',
                'name' => 'OpenRouter',
                'base_url' => 'https://openrouter.ai/api/v1',
                'key_hint' => 'sk-or-...',
                'free' => true,
                'note' => '1つのキーで多数のモデル。無料モデルもあり。',
                'models' => [
                    'deepseek/deepseek-chat',
                    'meta-llama/llama-3.3-70b-instruct',
                    'google/gemini-2.0-flash-exp:free',
                    'qwen/qwen-2.5-72b-instruct',
                ],
                'signup' => 'https://openrouter.ai',
            ],
            [
                'id' => 'deepseek',
                'name' => 'DeepSeek',
                'base_url' => 'https://api.deepseek.com',
                'key_hint' => 'sk-...',
                'free' => false,
                'note' => '格安・高品質。長文脈に強い。',
                'models' => [
                    'deepseek-chat',
                    'deepseek-reasoner',
                ],
                'signup' => 'https://platform.deepseek.com',
            ],
            [
                'id' => 'xai',
                'name' => 'xAI (Grok)',
                'base_url' => 'https://api.x.ai/v1',
                'key_hint' => 'xai-...',
                'free' => false,
                'note' => 'フロンティア級で比較的安価。',
                'models' => [
                    'grok-4.1-fast',
                    'grok-4',
                ],
                'signup' => 'https://console.x.ai',
            ],
            [
                'id' => 'nvidia',
                'name' => 'NVIDIA Build',
                'base_url' => 'https://integrate.api.nvidia.com/v1',
                'key_hint' => 'nvapi-...',
                'free' => true,
                'note' => '無料クレジットあり（試用向け・レート制限あり）。',
                'models' => [
                    'meta/llama-3.1-70b-instruct',
                    'deepseek-ai/deepseek-r1',
                    'qwen/qwen2.5-72b-instruct',
                ],
                'signup' => 'https://build.nvidia.com',
            ],
            [
                'id' => 'ollama',
                'name' => 'Ollama（ローカル）',
                'base_url' => 'http://127.0.0.1:11434/v1',
                'key_hint' => 'ollama（任意の文字列でOK）',
                'free' => true,
                'note' => '自分のPC/サーバーで動かすローカルLLM。APIキーは不要なので任意の文字列を入れてください。',
                'models' => [
                    'qwen2.5:7b',
                    'llama3.1:8b',
                    'gemma2:9b',
                ],
                'signup' => 'https://ollama.com/download',
            ],
            [
                'id' => 'lmstudio',
                'name' => 'LM Studio（ローカル）',
                'base_url' => 'http://127.0.0.1:1234/v1',
                'key_hint' => 'lm-studio（任意の文字列でOK）',
                'free' => true,
                'note' => 'LM Studio の Local Server。読み込んだモデル名をモデル欄に入れてください。',
                'models' => [
                    'qwen2.5-7b-instruct',
                ],
                'signup' => 'https://lmstudio.ai',
            ],
        ];
    }

    public static function find(string $id): ?array
    {
        foreach (self::list() as $p) {
            if ($p['id'] === $id) {
                return $p;
            }
        }
        return null;
    }

    /** プリセットIDからベースURLを解決（不明なら空） */
    public static function baseUrl(string $id): string
    {
        $p = self::find($id);
        return $p ? $p['base_url'] : '';
    }

    public static function label(string $id): string
    {
        $p = self::find($id);
        return $p ? $p['name'] : $id;
    }
}
