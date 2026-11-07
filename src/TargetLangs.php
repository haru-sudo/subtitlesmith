<?php

declare(strict_types=1);

namespace App;

/**
 * 訳出先（字幕として出力する言語）の一覧。
 *
 * 以前は「原語(英/独) → 日本語」固定だったが、動画ごとに出力言語を選べるようにしたため、
 * 言語ごとに変わる情報（表示名・AIへの指示名・字幕トラックの言語タグ・焼き込みフォント・
 * 字幕の作法）はすべてここに集約する。
 *
 * 既存データはすべて日本語訳なので、未設定は 'ja' として扱う（DBの既定値も 'ja'）。
 */
final class TargetLangs
{
    public const DEFAULT = 'ja';

    /**
     * id => 定義
     *   label   画面に出す名前（日本語UI用）
     *   name    翻訳プロンプトでAIに指示するときの言語名
     *   vtt     <track srclang> に入れる言語タグ
     *   wide    1文字を全角幅として書く言語か（プロンプトの字数指示の書き方に使う）
     *   period  行末の句点を落とす字幕作法を適用するか
     *   font    焼き込み(ASS)の既定フォント。config 'subtitle_font_<id>' で上書き可
     */
    private const LANGS = [
        'ja' => [
            'label' => '日本語', 'name' => '日本語', 'vtt' => 'ja',
            'wide' => true, 'period' => true,
            'font' => 'Noto Sans CJK JP',
        ],
        'zh-Hans' => [
            'label' => '中国語（簡体字）', 'name' => '中国語（簡体字・大陸で使われる中文）', 'vtt' => 'zh-Hans',
            'wide' => true, 'period' => true,
            'font' => 'Noto Sans CJK SC',
        ],
        'zh-Hant' => [
            'label' => '中国語（繁体字）', 'name' => '中国語（繁体字・台湾で使われる中文）', 'vtt' => 'zh-Hant',
            'wide' => true, 'period' => true,
            'font' => 'Noto Sans CJK TC',
        ],
        'ko' => [
            'label' => '韓国語', 'name' => '韓国語', 'vtt' => 'ko',
            'wide' => true, 'period' => false,
            'font' => 'Noto Sans CJK KR',
        ],
        'en' => [
            'label' => '英語', 'name' => '英語', 'vtt' => 'en',
            'wide' => false, 'period' => false,
            'font' => 'Noto Sans',
        ],
        'de' => [
            'label' => 'ドイツ語', 'name' => 'ドイツ語', 'vtt' => 'de',
            'wide' => false, 'period' => false,
            'font' => 'Noto Sans',
        ],
        'fr' => [
            'label' => 'フランス語', 'name' => 'フランス語', 'vtt' => 'fr',
            'wide' => false, 'period' => false,
            'font' => 'Noto Sans',
        ],
        'es' => [
            'label' => 'スペイン語', 'name' => 'スペイン語', 'vtt' => 'es',
            'wide' => false, 'period' => false,
            'font' => 'Noto Sans',
        ],
        'pt' => [
            'label' => 'ポルトガル語', 'name' => 'ポルトガル語', 'vtt' => 'pt',
            'wide' => false, 'period' => false,
            'font' => 'Noto Sans',
        ],
        'th' => [
            'label' => 'タイ語', 'name' => 'タイ語', 'vtt' => 'th',
            'wide' => false, 'period' => false,
            'font' => 'Noto Sans Thai',
        ],
        'vi' => [
            'label' => 'ベトナム語', 'name' => 'ベトナム語', 'vtt' => 'vi',
            'wide' => false, 'period' => false,
            'font' => 'Noto Sans',
        ],
        'id' => [
            'label' => 'インドネシア語', 'name' => 'インドネシア語', 'vtt' => 'id',
            'wide' => false, 'period' => false,
            'font' => 'Noto Sans',
        ],
    ];

    /** 訳出先には無いが、原語（音声の言語）として扱う言語の名前 */
    private const SOURCE_ONLY = [
        'it' => 'イタリア語', 'nl' => 'オランダ語', 'ru' => 'ロシア語', 'pl' => 'ポーランド語',
        'sv' => 'スウェーデン語', 'tr' => 'トルコ語', 'ar' => 'アラビア語', 'hi' => 'ヒンディー語',
        'uk' => 'ウクライナ語', 'cs' => 'チェコ語', 'da' => 'デンマーク語', 'fi' => 'フィンランド語',
        'no' => 'ノルウェー語', 'el' => 'ギリシャ語', 'he' => 'ヘブライ語', 'hu' => 'ハンガリー語',
        'ms' => 'マレー語', 'tl' => 'タガログ語',
    ];

    /**
     * 原語（音声の言語）の名前。訳出先の言語も含め、未知のコードでも空にならない。
     * 'zh' / 'en-US' のような地域付きコードは主言語で引く。
     */
    public static function sourceName(?string $code): string
    {
        $code = trim((string) $code);
        if ($code === '' || $code === 'auto') {
            return '原語';
        }
        if (isset(self::LANGS[$code])) {
            return self::LANGS[$code]['label'];
        }
        $base = strtolower((string) preg_split('/[-_]/', $code)[0]);
        if ($base === 'zh') {
            return '中国語';
        }
        return self::LANGS[$base]['label'] ?? (self::SOURCE_ONLY[$base] ?? $code);
    }

    /** 用語集などで選べる原語の一覧（'auto' = 共通 を含む） */
    public static function sourceChoices(): array
    {
        $out = [];
        foreach (self::LANGS as $id => $def) {
            $out[] = ['id' => $id, 'label' => $def['label']];
        }
        foreach (self::SOURCE_ONLY as $id => $label) {
            $out[] = ['id' => $id, 'label' => $label];
        }
        $out[] = ['id' => 'auto', 'label' => '共通'];
        return $out;
    }

    /** 原語として有効なコードか（'auto' を含む） */
    public static function isSourceValid(string $code): bool
    {
        foreach (self::sourceChoices() as $c) {
            if ($c['id'] === $code) {
                return true;
            }
        }
        return false;
    }

    /** 原語と訳出先が同じ言語か（主言語で比較。例: zh-Hans と zh） */
    public static function sameLanguage(?string $source, ?string $target): bool
    {
        $a = strtolower((string) preg_split('/[-_]/', trim((string) $source))[0]);
        $b = strtolower((string) preg_split('/[-_]/', trim((string) $target))[0]);
        return $a !== '' && $a === $b;
    }

    /** @return array<int,array{id:string,label:string}> 選択UI用 */
    public static function choices(): array
    {
        $out = [];
        foreach (self::LANGS as $id => $def) {
            $out[] = ['id' => $id, 'label' => $def['label']];
        }
        return $out;
    }

    public static function isValid(string $id): bool
    {
        return isset(self::LANGS[$id]);
    }

    /** 未設定・不正な値を既定(ja)へ丸める。DBの古い行にも使う。 */
    public static function normalize(?string $id): string
    {
        $id = trim((string) $id);
        return self::isValid($id) ? $id : self::DEFAULT;
    }

    /** 設定の既定訳出先（設定ページで変更可） */
    public static function current(): string
    {
        return self::normalize((string) Settings::get('target_lang', self::DEFAULT));
    }

    /** 画面表示用の名前（例: 中国語（簡体字）） */
    public static function label(?string $id): string
    {
        return self::LANGS[self::normalize($id)]['label'];
    }

    /** 翻訳プロンプトでAIに伝える言語名 */
    public static function name(?string $id): string
    {
        return self::LANGS[self::normalize($id)]['name'];
    }

    /** 字幕トラック(<track srclang>)用の言語タグ */
    public static function vttTag(?string $id): string
    {
        return self::LANGS[self::normalize($id)]['vtt'];
    }

    /** 全角幅で数える言語か（プロンプトの字数指示の書き方に使う） */
    public static function isWide(?string $id): bool
    {
        return (bool) self::LANGS[self::normalize($id)]['wide'];
    }

    /** 行末の句点を落とす字幕作法を使う言語か */
    public static function dropsSentencePeriod(?string $id): bool
    {
        return (bool) self::LANGS[self::normalize($id)]['period'];
    }


    /**
     * 焼き込み(ASS)に使うフォント名。
     * 優先順: config 'subtitle_font_<id>' → （日本語のみ）従来の config 'subtitle_font' → 言語ごとの既定。
     * ※ 'subtitle_font' は日本語専用の旧設定なので、他言語へは適用しない
     *   （日本語フォントで中国語を出すと字形が中国語として不自然になるため）。
     */
    public static function font(?string $id): string
    {
        $id = self::normalize($id);
        $perLang = trim((string) App::config('subtitle_font_' . $id, ''));
        if ($perLang !== '') {
            return $perLang;
        }
        if ($id === 'ja') {
            $legacy = trim((string) App::config('subtitle_font', ''));
            if ($legacy !== '') {
                return $legacy;
            }
        }
        return self::LANGS[$id]['font'];
    }
}
