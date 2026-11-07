<?php

declare(strict_types=1);

namespace App\Repo;

use App\App;

/**
 * 用語集(Glossary)。専門用語の対訳辞書。翻訳プロンプトに用語制約として注入する。
 */
final class Glossary
{
    public static function all(): array
    {
        return App::db()->fetchAll(
            'SELECT * FROM glossary ORDER BY target_lang, source_lang, source_term COLLATE NOCASE'
        );
    }

    /** 指定の訳出先言語のエントリだけ（未設定の古い行は日本語として扱う） */
    public static function forTarget(string $targetLang): array
    {
        $targetLang = \App\TargetLangs::normalize($targetLang);
        return array_values(array_filter(
            self::all(),
            static fn(array $g) => \App\TargetLangs::normalize($g['target_lang'] ?? null) === $targetLang
        ));
    }

    public static function find(int $id): ?array
    {
        return App::db()->fetch('SELECT * FROM glossary WHERE id = :id', [':id' => $id]);
    }

    public static function create(array $data): int
    {
        return App::db()->insert('glossary', $data);
    }

    public static function update(int $id, array $data): void
    {
        App::db()->update('glossary', $data, ['id' => $id]);
    }

    public static function delete(int $id): void
    {
        App::db()->run('DELETE FROM glossary WHERE id = :id', [':id' => $id]);
    }

    public static function count(): int
    {
        return (int) App::db()->fetchValue('SELECT COUNT(*) FROM glossary');
    }

    /**
     * 複数テキストの中に出現する用語集エントリを返す（大文字小文字を無視）。
     * 訳語の言語が一致するものだけを返すので、日本語の訳語が中国語訳に混ざることはない。
     * @param array<int,string> $texts
     * @param string $lang       原語 en|de|auto
     * @param string $targetLang 訳出先（App\TargetLangs のID）
     * @return array<int,array{source_term:string,target_term:string,note:?string}>
     */
    public static function matchInTexts(array $texts, string $lang, string $targetLang = \App\TargetLangs::DEFAULT): array
    {
        $entries = self::forTarget($targetLang);
        if (!$entries) {
            return [];
        }
        $haystack = mb_strtolower(implode("\n", $texts));
        $matched = [];
        foreach ($entries as $g) {
            if ($lang !== 'auto' && $g['source_lang'] !== 'auto' && $g['source_lang'] !== $lang) {
                continue;
            }
            $term = mb_strtolower(trim((string) $g['source_term']));
            if ($term === '') {
                continue;
            }
            if (self::termOccurs($haystack, $term)) {
                $matched[] = [
                    'source_term' => (string) $g['source_term'],
                    'target_term' => (string) $g['target_term'],
                    'note'        => $g['note'] !== '' ? $g['note'] : null,
                ];
            }
        }
        return $matched;
    }

    /**
     * 用語が本文に出現するかを、語形のゆれも含めて判定する。
     * - 単純な部分一致（複数形 leg→legs などはこれで拾える）
     * - ハイフン/スペースのゆれ（fine-tuning ↔ fine tuning）
     */
    private static function termOccurs(string $haystack, string $term): bool
    {
        if (mb_strpos($haystack, $term) !== false) {
            return true;
        }
        // ハイフンとスペースを相互に置き換えた表記ゆれも許容
        $variants = [];
        if (mb_strpos($term, '-') !== false) {
            $variants[] = str_replace('-', ' ', $term);
        }
        if (mb_strpos($term, ' ') !== false) {
            $variants[] = str_replace(' ', '-', $term);
        }
        foreach ($variants as $v) {
            if ($v !== '' && mb_strpos($haystack, $v) !== false) {
                return true;
            }
        }
        return false;
    }
}
