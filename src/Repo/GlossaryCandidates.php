<?php

declare(strict_types=1);

namespace App\Repo;

use App\App;

/**
 * 用語集の候補。完成動画のOCR取り込み時に自動抽出され、人の承認待ちで貯まる。
 * 自動では翻訳に使われない（承認して初めて glossary に入る）。
 */
final class GlossaryCandidates
{
    /** 承認待ちの候補を出現回数の多い順に返す */
    public static function pending(int $limit = 200): array
    {
        return App::db()->fetchAll(
            "SELECT * FROM glossary_candidates
              WHERE status = 'pending'
              ORDER BY frequency DESC, source_term COLLATE NOCASE
              LIMIT :lim",
            [':lim' => $limit]
        );
    }

    public static function pendingCount(): int
    {
        return (int) App::db()->fetchValue(
            "SELECT COUNT(*) FROM glossary_candidates WHERE status = 'pending'"
        );
    }

    public static function find(int $id): ?array
    {
        return App::db()->fetch('SELECT * FROM glossary_candidates WHERE id = :id', [':id' => $id]);
    }

    /**
     * 候補を投入。同じ (lang, source_term, target_term) があれば頻度を+1する。
     * 既に承認/却下済みのものには触れない（再提案しない）。
     * $videoId は生成元の動画ID（学習用動画の削除時に連動削除するため）。
     * 既存行がある場合は最初に記録した動画IDを保持する。
     */
    public static function upsert(string $lang, string $source, string $target, string $exampleSrc = '', string $exampleJa = '', ?int $videoId = null): void
    {
        $now = date('Y-m-d H:i:s');
        App::db()->run(
            "INSERT INTO glossary_candidates
                (source_lang, source_term, target_term, frequency, example_src, example_ja, status, source_video_id, created_at, updated_at)
             VALUES (:l, :s, :t, 1, :ex, :ej, 'pending', :vid, :c, :u)
             ON CONFLICT(source_lang, source_term, target_term) DO UPDATE SET
                frequency = frequency + 1,
                updated_at = :u2,
                example_src = COALESCE(NULLIF(example_src, ''), :ex2),
                example_ja  = COALESCE(NULLIF(example_ja, ''), :ej2),
                source_video_id = COALESCE(source_video_id, :vid2)",
            [
                ':l' => $lang, ':s' => $source, ':t' => $target,
                ':ex' => $exampleSrc, ':ej' => $exampleJa, ':vid' => $videoId, ':c' => $now, ':u' => $now,
                ':u2' => $now, ':ex2' => $exampleSrc, ':ej2' => $exampleJa, ':vid2' => $videoId,
            ]
        );
    }

    public static function setStatus(int $id, string $status): void
    {
        App::db()->update('glossary_candidates', [
            'status' => $status,
            'updated_at' => date('Y-m-d H:i:s'),
        ], ['id' => $id]);
    }

    /** 既に用語集（curated/mined）に同じ原語があるか（重複提案を避ける用） */
    public static function existsInGlossary(string $lang, string $source): bool
    {
        $n = (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM glossary WHERE source_lang = :l AND LOWER(source_term) = LOWER(:s)',
            [':l' => $lang, ':s' => $source]
        );
        return $n > 0;
    }
}
