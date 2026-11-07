<?php

declare(strict_types=1);

namespace App;

/**
 * UIから保存できるアプリ設定（APIキー等）。
 * app_settings テーブルに保存し、config.php の値より優先する。
 * 空文字なら未設定とみなし config.php にフォールバックする。
 *
 * 値は1リクエスト内でキャッシュし、DBアクセスを最小化する。
 */
final class Settings
{
    /** UIから編集可能なキー（許可リスト） */
    public const EDITABLE = [
        'anthropic_api_key',
        'openai_api_key',
        'deepgram_api_key',
        'deepgram_keyterms',           // 手動キーターム（改行/カンマ区切り。専門用語の認識強化）
        'deepgram_keyterm_glossary',   // '1'=用語集からキータームを自動生成（既定ON相当）, ''=OFF
        'translate_model',
        'transcribe_model',
        'target_lang',          // 訳出先(字幕の出力言語)の既定。動画ごとに上書きできる
        'translate_refine',     // '1'=2パス校正で精度優先, ''=1パス
        'prompt_cache',         // '1'=プロンプトキャッシュ有効(既定), ''=無効
        'subtitle_min_sec_multi',  // 字幕の最低表示時間（秒）: 2行以上のブロック。0=無効
        'subtitle_min_sec_single', // 字幕の最低表示時間（秒）: 1行のブロック。0=無効
        // 翻訳プロバイダ
        'translate_provider',   // anthropic|gemini|openai
        'gemini_api_key',
        'gemini_model',
        'openai_compat_preset',    // groq|openrouter|deepseek|xai|nvidia|ollama|lmstudio（空=カスタムURL）
        'openai_compat_base_url',  // プリセットが custom のときのみ使う任意指定
        'openai_compat_api_key',
        'openai_compat_model',     // 例 llama-3.3-70b-versatile
    ];

    /** @var array<string,string>|null */
    private static ?array $cache = null;

    /** @return array<string,string> 全設定（DB） */
    public static function all(): array
    {
        if (self::$cache === null) {
            self::$cache = [];
            try {
                foreach (App::db()->fetchAll('SELECT key, value FROM app_settings') as $r) {
                    self::$cache[(string) $r['key']] = (string) $r['value'];
                }
            } catch (\Throwable $e) {
                // テーブル未作成など。configフォールバックに任せる。
                self::$cache = [];
            }
        }
        return self::$cache;
    }

    /**
     * 設定値を取得。DBに非空の値があればそれを、無ければ config.php、最後に $default。
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $db = self::all();
        if (isset($db[$key]) && $db[$key] !== '') {
            return $db[$key];
        }
        $cfg = App::config($key);
        if ($cfg !== null && $cfg !== '') {
            return $cfg;
        }
        return $default;
    }

    /** UIから保存（許可リストのキーのみ）。空文字も保存可（=未設定に戻す）。 */
    public static function set(string $key, string $value): void
    {
        if (!in_array($key, self::EDITABLE, true)) {
            return;
        }
        App::db()->run(
            'INSERT INTO app_settings (key, value, updated_at) VALUES (:k, :v, :u)
             ON CONFLICT(key) DO UPDATE SET value = :v2, updated_at = :u2',
            [':k' => $key, ':v' => $value, ':u' => date('Y-m-d H:i:s'),
             ':v2' => $value, ':u2' => date('Y-m-d H:i:s')]
        );
        self::$cache = null;
    }

    /** キーが（DBまたはconfigで）設定済みか */
    public static function isConfigured(string $key): bool
    {
        $v = self::get($key, '');
        return is_string($v) && $v !== '';
    }

    /**
     * ON/OFFトグル用。キーがDBに保存されていれば、空=OFF・非空=ON として解釈する
     * （APIキー用の get() と違い、明示的なOFFを既定値で上書きしない）。
     * DBに無ければ config.php → $default の順。
     */
    public static function getBool(string $key, bool $default): bool
    {
        $db = self::all();
        if (array_key_exists($key, $db)) {
            return $db[$key] !== '';   // 明示保存された値を尊重（空=OFF）
        }
        $cfg = App::config($key);
        if ($cfg !== null) {
            return (string) $cfg !== '' && (string) $cfg !== '0';
        }
        return $default;
    }
}
