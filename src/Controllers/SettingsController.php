<?php

declare(strict_types=1);

namespace App\Controllers;

use App\App;
use App\Http\Request;
use App\Http\Response;
use App\Models;
use App\Settings;
use App\View;

/**
 * 設定ページ。APIキーと既定の翻訳モデルをUIから保存する。
 * 保存値は app_settings テーブルに入り、config.php より優先される。
 */
final class SettingsController
{
    public function index(Request $request): void
    {
        Response::html(View::render('settings', [
            'anthropicSet' => Settings::isConfigured('anthropic_api_key'),
            'openaiSet'    => Settings::isConfigured('openai_api_key'),
            'refineOn'     => Settings::getBool('translate_refine', false),
            'cacheOn'      => Settings::getBool('prompt_cache', true),
            'saved'        => $request->input('saved') !== null,
            'anthropicFromConfig' => self::onlyFromConfig('anthropic_api_key'),
            'openaiFromConfig'    => self::onlyFromConfig('openai_api_key'),
            // Gemini / OpenAI互換のAPIキー（キーのみ設定ページで管理、モデル/プリセットはアップロード画面）
            'geminiSet'  => Settings::isConfigured('gemini_api_key'),
            'compatSet'  => Settings::isConfigured('openai_compat_api_key'),
            'compatPresets' => \App\CompatPresets::list(),
            'compatPreset'  => (string) Settings::get('openai_compat_preset', ''),
            'compatBaseUrl' => (string) Settings::get('openai_compat_base_url', ''),
            'compatModel'   => (string) Settings::get('openai_compat_model', ''),
            'deepgramSet' => Settings::isConfigured('deepgram_api_key'),
            'deepgramKeyterms' => (string) Settings::get('deepgram_keyterms', ''),
            'deepgramKeytermGlossary' => Settings::getBool('deepgram_keyterm_glossary', true),
            'targetLangs'       => \App\TargetLangs::choices(),
            'currentTargetLang' => \App\TargetLangs::current(),
            'minSecMulti'  => \App\Subtitles::minDisplaySec()[0],
            'minSecSingle' => \App\Subtitles::minDisplaySec()[1],
        ], '設定'));
    }

    public function save(Request $request): void
    {
        // APIキー: 入力が空欄なら「変更しない」。クリアは専用チェックで。
        foreach (['anthropic_api_key', 'openai_api_key'] as $key) {
            if ((string) $request->input('clear_' . $key, '') === '1') {
                Settings::set($key, '');
                continue;
            }
            $val = trim((string) $request->input($key, ''));
            if ($val !== '') {
                Settings::set($key, $val);
            }
        }

        // 訳出先(字幕の出力言語)の既定。アップロード画面の初期選択になる。
        $targetLang = (string) $request->input('target_lang', '');
        if (\App\TargetLangs::isValid($targetLang)) {
            Settings::set('target_lang', $targetLang);
        }

        // 精度オプション（チェックボックス）
        Settings::set('translate_refine', (string) $request->input('translate_refine', '') !== '' ? '1' : '');
        Settings::set('prompt_cache', (string) $request->input('prompt_cache', '') !== '' ? '1' : '');

        // 字幕の最低表示時間（秒）。数値以外は保存しない（0=無効、上限30秒）。
        // 値が変わったときだけ保存する（更新時刻で既存の焼き込み動画を「作り直しが必要」と判定するため）
        [$curMulti, $curSingle] = \App\Subtitles::minDisplaySec();
        foreach (['subtitle_min_sec_multi' => $curMulti, 'subtitle_min_sec_single' => $curSingle] as $key => $cur) {
            $val = trim((string) $request->input($key, ''));
            if ($val === '' || !is_numeric($val)) {
                continue;
            }
            $new = max(0.0, min(30.0, round((float) $val, 1)));
            if (abs($new - $cur) > 0.001) {
                Settings::set($key, (string) $new);
            }
        }

        // Gemini / OpenAI互換のAPIキー（キーのみ。モデル/プリセットはアップロード画面で動画ごとに設定）
        foreach (['gemini_api_key', 'openai_compat_api_key', 'deepgram_api_key'] as $key) {
            if ((string) $request->input('clear_' . $key, '') === '1') {
                Settings::set($key, '');
                continue;
            }
            $val = trim((string) $request->input($key, ''));
            if ($val !== '') {
                Settings::set($key, $val);
            }
        }

        // OpenAI互換の接続先（プリセット or 任意のURL）と既定モデル
        $preset = (string) $request->input('openai_compat_preset', '');
        if ($preset === '' || \App\CompatPresets::find($preset) !== null) {
            Settings::set('openai_compat_preset', $preset);
        }
        $baseUrl = trim((string) $request->input('openai_compat_base_url', ''));
        if ($baseUrl === '' || preg_match('#^https?://[^\s]+$#i', $baseUrl)) {
            Settings::set('openai_compat_base_url', $baseUrl);
        }
        Settings::set('openai_compat_model', trim((string) $request->input('openai_compat_model', '')));

        // Deepgram キーターム（専門用語の認識強化）
        Settings::set('deepgram_keyterms', trim((string) $request->input('deepgram_keyterms', '')));
        Settings::set('deepgram_keyterm_glossary', (string) $request->input('deepgram_keyterm_glossary', '') !== '' ? '1' : '');

        Response::redirect('/settings?saved=1');
    }

    /** DBに値が無く config.php のみで設定されているか */
    private static function onlyFromConfig(string $key): bool
    {
        $all = Settings::all();
        $db = isset($all[$key]) && $all[$key] !== '';
        $cfg = (string) App::config($key, '') !== '';
        return !$db && $cfg;
    }
}
