<?php

declare(strict_types=1);

namespace App;

/**
 * 翻訳に使えるAIモデルの一覧（コスト・精度・推奨の表示用メタ付き）。
 * 料金は100万トークンあたりの米ドル（入力/出力）。2026年時点の公開価格。
 */
final class Models
{
    /**
     * @return array<int,array{
     *   id:string, name:string, in:float, out:float,
     *   quality:string, speed:string, recommended:bool, note:string
     * }>
     */
    public static function list(): array
    {
        return [
            [
                'id' => 'claude-opus-5-5',
                'name' => 'Claude Opus 5.5',
                'in' => 5.0, 'out' => 25.0,
                'quality' => '最高', 'speed' => '標準',
                'recommended' => true,
                'note' => '最新の最上位モデル。難しい専門表現・長い文脈に最も強い。',
            ],
        ];
    }

    /** 既定モデルID（設定があればそれ、無ければOpus 5.5） */
    public static function default(): string
    {
        $fromSettings = (string) Settings::get('translate_model', '');
        if ($fromSettings !== '' && self::isValid($fromSettings)) {
            return $fromSettings;
        }
        return 'claude-opus-5-5';
    }

    public static function isValid(string $id): bool
    {
        foreach (self::list() as $m) {
            if ($m['id'] === $id) {
                return true;
            }
        }
        return false;
    }

    /** 表示名（不明IDはそのまま返す） */
    public static function label(string $id): string
    {
        foreach (self::list() as $m) {
            if ($m['id'] === $id) {
                return $m['name'];
            }
        }
        return $id;
    }
}
