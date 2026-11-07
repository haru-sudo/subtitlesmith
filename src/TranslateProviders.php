<?php

declare(strict_types=1);

namespace App;

/**
 * 翻訳プロバイダの一覧と設定の入口。
 *
 * 3系統に対応:
 *   - anthropic : Claude（既定・最高精度）
 *   - gemini    : Google Gemini（無料枠あり・高品質）
 *   - openai    : OpenAI互換の汎用枠（base_url+キーで Groq/OpenRouter/DeepSeek/Grok/NVIDIA等に接続）
 *
 * どのプロバイダを使うかは設定 'translate_provider'。
 * 各プロバイダの接続情報も設定ページから登録する。
 */
final class TranslateProviders
{
    public const ANTHROPIC = 'anthropic';
    public const GEMINI    = 'gemini';
    public const OPENAI    = 'openai';
    public const LOCAL_LLM = 'local-llm';

    /** @return array<int,array{id:string,name:string,note:string,free:bool}> */
    public static function list(): array
    {
        return [
            [
                'id' => self::ANTHROPIC,
                'name' => 'Claude（Anthropic）',
                'note' => '最高精度。専門用語・長文脈に最も強い。プロンプトキャッシュ対応。',
                'free' => false,
                'local' => false,
            ],
            [
                'id' => self::GEMINI,
                'name' => 'Google Gemini',
                'note' => '無料枠あり（Flashは1日1,500回・要確認）。品質も高く日本語に強い。',
                'free' => true,
                'local' => false,
            ],
            [
                'id' => self::OPENAI,
                'name' => 'OpenAI互換（汎用）',
                'note' => 'base_URLとキーを設定して Groq / OpenRouter / DeepSeek / Grok / NVIDIA Build 等に接続。',
                'free' => true,
                'local' => false,
            ],
            [
                'id' => self::LOCAL_LLM,
                'name' => 'ローカルLLM（コスト0）',
                'note' => 'サーバー上で小型LLM(Qwen2.5等)を実行。APIコスト0・キー不要・常駐サーバー不要。'
                    . '用語集・翻訳メモリ・文脈・校正がすべて効く。'
                    . 'GPU不要(CPU・int8)。要セットアップ(ctranslate2/transformers)。',
                'free' => false,
                'local' => true,
            ],
        ];
    }

    /** 現在のプロバイダID（未設定/不正は anthropic） */
    public static function current(): string
    {
        $p = (string) Settings::get('translate_provider', self::ANTHROPIC);
        return self::isValid($p) ? $p : self::ANTHROPIC;
    }

    public static function isValid(string $id): bool
    {
        foreach (self::list() as $p) {
            if ($p['id'] === $id) {
                return true;
            }
        }
        return false;
    }

    public static function label(string $id): string
    {
        foreach (self::list() as $p) {
            if ($p['id'] === $id) {
                return $p['name'];
            }
        }
        return $id;
    }

    /**
     * アップロード画面の選択肢用。各プロバイダに「利用可能か(キー設定済みか)」と
     * 「現在のモデル表示名」を付ける。未設定のものは選んでも翻訳できないので印を付ける。
     * @return array<int,array{id:string,name:string,note:string,free:bool,ready:bool,model_label:string}>
     */
    public static function choices(): array
    {
        $out = [];
        foreach (self::list() as $p) {
            $impl = self::make($p['id']);
            $ready = false;
            try {
                $ready = $impl->isConfigured();
            } catch (\Throwable $e) {
                $ready = false;
            }
            $modelLabel = '';
            if ($p['id'] === self::ANTHROPIC) {
                $modelLabel = ''; // Claudeはモデルを別途ラジオで選ぶ
            } elseif ($p['id'] === self::GEMINI) {
                $modelLabel = (string) Settings::get('gemini_model', 'gemini-2.5-flash');
            } elseif ($p['id'] === self::OPENAI) {
                $preset = (string) Settings::get('openai_compat_preset', '');
                $model = (string) Settings::get('openai_compat_model', '');
                $svc = $preset !== '' ? CompatPresets::label($preset) : '';
                $modelLabel = trim($svc . ' ' . $model);
            } elseif ($p['id'] === self::LOCAL_LLM) {
                $m = (string) \App\App::config('local_llm_model', 'Qwen/Qwen2.5-3B-Instruct');
                $modelLabel = basename($m);
            }
            $out[] = [
                'id' => $p['id'],
                'name' => $p['name'],
                'note' => $p['note'],
                'free' => $p['free'],
                'local' => $p['local'] ?? false,
                'ready' => $ready,
                'model_label' => $modelLabel,
            ];
        }
        return $out;
    }

    /**
     * プロバイダごとの「選べるモデル一覧」を返す。
     * アップロード画面で、選んだプロバイダに応じたモデル選択を出すために使う。
     *   anthropic → Claudeのモデル（Models::list 相当）
     *   gemini    → Geminiのモデル
     *   openai    → 設定中のプリセットが持つモデル候補（無ければ設定のモデル1件）
     * @return array<string,array<int,array{id:string,name:string,note:string}>>
     */
    public static function modelChoices(): array
    {
        // Claude
        $claude = [];
        foreach (Models::list() as $m) {
            $claude[] = [
                'id' => $m['id'],
                'name' => $m['name'] . ($m['recommended'] ? '（推奨）' : ''),
                'note' => $m['note'] . ' / 精度:' . $m['quality'] . ' 速度:' . $m['speed']
                    . ' / $' . number_format($m['in'], 2) . '・$' . number_format($m['out'], 2) . '(入/出 100万)',
            ];
        }

        // Gemini
        $gemini = Translators\GeminiTranslator::models();

        // OpenAI互換: 選択中プリセットのモデル候補
        $openai = [];
        $preset = (string) Settings::get('openai_compat_preset', '');
        $presetData = $preset !== '' ? CompatPresets::find($preset) : null;
        if ($presetData) {
            foreach ($presetData['models'] as $mid) {
                $openai[] = ['id' => $mid, 'name' => $mid, 'note' => $presetData['name']];
            }
        }
        // 設定済みのモデルが候補に無ければ先頭に足す
        $cur = (string) Settings::get('openai_compat_model', '');
        if ($cur !== '') {
            $exists = false;
            foreach ($openai as $o) {
                if ($o['id'] === $cur) { $exists = true; break; }
            }
            if (!$exists) {
                array_unshift($openai, ['id' => $cur, 'name' => $cur . '（現在の設定）', 'note' => '']);
            }
        }

        return [
            self::ANTHROPIC => $claude,
            self::GEMINI    => $gemini,
            self::OPENAI    => $openai,
            // ローカルLLMはモデルをサーバー設定で持つためアップロード画面ではモデル選択を出さない
            self::LOCAL_LLM => [],
        ];
    }

    /** 指定プロバイダ（空/不正なら設定の既定）の実装を生成する */
    public static function make(string $override = ''): Translators\TranslatorInterface
    {
        $id = ($override !== '' && self::isValid($override)) ? $override : self::current();
        switch ($id) {
            case self::GEMINI:
                return new Translators\GeminiTranslator();
            case self::OPENAI:
                return new Translators\OpenAICompatTranslator();
            case self::LOCAL_LLM:
                return new Translators\LocalLLMTranslator();
            case self::ANTHROPIC:
            default:
                return new Translators\ClaudeTranslator();
        }
    }
}
