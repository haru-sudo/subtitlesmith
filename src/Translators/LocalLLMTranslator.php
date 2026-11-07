<?php

declare(strict_types=1);

namespace App\Translators;

use App\App;
use App\Settings;
use App\Support\Proc;

/**
 * ローカルLLM翻訳プロバイダ（CTranslate2 + 小型instructモデル）。
 *
 * 共有レンタルサーバー（GPU無し・常駐プロセス不可）でも動く一発実行型。
 * faster-whisper と同じ CTranslate2 系で、Ollama等の常駐サーバーは不要。
 * APIキー不要・APIコスト0。要セットアップ（pip install ctranslate2 transformers sentencepiece）。
 *
 * instruct LLMなので、Translate が組み立てたプロンプト
 * （用語集・翻訳メモリ・文脈・校正の指示）をそのまま理解し、
 * {"translations":[{id,ja}]} を生成する。system/user をそのままモデルへ渡すだけでよい。
 */
final class LocalLLMTranslator implements TranslatorInterface
{
    public function name(): string
    {
        return 'ローカルLLM';
    }

    /** ローカル実行なのでキー不要。常に選択可能（要サーバーセットアップ）。 */
    public function isConfigured(): bool
    {
        return true;
    }

    public function translateJson(string $systemText, string $userText, int $maxTokens, string $modelOverride = ''): array
    {
        $payload = [
            'system'     => $systemText,
            'user'       => $userText,
            'max_tokens' => $maxTokens,
        ];

        $tmp = tempnam(sys_get_temp_dir(), 'subtitlesmith_llm_');
        if ($tmp === false) {
            throw new \RuntimeException('一時ファイルを作成できません（ローカルLLM翻訳）');
        }
        @file_put_contents($tmp, json_encode($payload, JSON_UNESCAPED_UNICODE));

        try {
            $data = self::runPython($tmp, $modelOverride);
        } finally {
            @unlink($tmp);
        }

        if (empty($data['ok'])) {
            $err = (string) ($data['error'] ?? 'ローカルLLM翻訳スクリプトが失敗しました');
            App::log('ローカルLLM翻訳エラー: ' . $err, 'translate');
            throw new \RuntimeException('ローカルLLM翻訳エラー: ' . $err);
        }
        $out = ['translations' => []];
        foreach (($data['translations'] ?? []) as $t) {
            if (!isset($t['id'])) {
                continue;
            }
            $out['translations'][] = ['id' => (int) $t['id'], 'ja' => (string) ($t['ja'] ?? '')];
        }
        return $out;
    }

    /** translate_llm_local.py を実行して結果JSONを返す */
    private static function runPython(string $inputJsonPath, string $modelOverride): array
    {
        // Python 3 必須。優先順: config 'python_llm' → 'python_translate' → 'python_whisper' → 'python3' → 'python'
        $python = (string) App::config('python_llm', '');
        foreach (['python_translate', 'python_whisper', 'python3'] as $k) {
            if ($python === '') {
                $python = (string) App::config($k, '');
            }
        }
        if ($python === '') {
            $python = (string) App::config('python', 'python3');
        }

        $script = APP_BASE_PATH . '/python/translate_llm_local.py';
        if (!is_file($script)) {
            throw new \RuntimeException('ローカルLLM翻訳スクリプトがありません: ' . $script);
        }

        // モデル等はサーバー設定で持つ（文字起こしの local_whisper_* と同様）。
        $model   = $modelOverride !== '' && strncmp($modelOverride, 'claude', 6) !== 0 && strncmp($modelOverride, 'gemini', 6) !== 0 && str_contains($modelOverride, '/')
            ? $modelOverride
            : (string) App::config('local_llm_model', 'Qwen/Qwen2.5-3B-Instruct');
        $compute = (string) App::config('local_llm_compute', 'int8');
        $device  = (string) App::config('local_llm_device', 'cpu');
        $threads = (int) App::config('local_llm_cpu_threads', 0);
        $inter   = (int) App::config('local_llm_inter_threads', 1);

        $cache = (string) App::config('storage_dir', APP_BASE_PATH . '/storage');
        $cache = rtrim($cache, '/\\') . '/models/ct2_llm';
        if (!is_dir($cache)) {
            @mkdir($cache, 0775, true);
        }

        $args = [
            $script,
            '--input', $inputJsonPath,
            '--model', $model,
            '--compute-type', $compute,
            '--device', $device,
            '--ct2-cache', $cache,
        ];
        if ($threads > 0) {
            $args[] = '--cpu-threads';
            $args[] = (string) $threads;
        }
        if ($inter > 0) {
            $args[] = '--inter-threads';
            $args[] = (string) $inter;
        }

        [, $out, $err] = Proc::run($python, $args);

        $data = json_decode(trim($out), true);
        if (!is_array($data)) {
            $hint = $err !== '' ? $err : substr($out, 0, 300);
            throw new \RuntimeException('ローカルLLM翻訳の出力を解析できません（依存/設定を確認）: ' . $hint);
        }
        return $data;
    }
}
