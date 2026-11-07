<?php

declare(strict_types=1);

namespace App\Repo;

use App\App;

/**
 * 翻訳メモリ(TM)。原文→確定訳の対を埋め込み付きで保存し、類似検索で再利用する。
 * 埋め込みは float32 little-endian の BLOB で保存。
 */
final class Tm
{
    /** @param array<int,float> $vec */
    public static function packVec(array $vec): string
    {
        return $vec ? pack('g*', ...$vec) : '';
    }

    /** @return array<int,float> */
    public static function unpackVec(?string $blob): array
    {
        if ($blob === null || $blob === '') {
            return [];
        }
        $arr = unpack('g*', $blob);
        return $arr ? array_values($arr) : [];
    }

    /** コサイン類似度 */
    public static function cosine(array $a, array $b): float
    {
        $n = min(count($a), count($b));
        if ($n === 0) {
            return 0.0;
        }
        $dot = 0.0;
        $na = 0.0;
        $nb = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $dot += $a[$i] * $b[$i];
            $na += $a[$i] * $a[$i];
            $nb += $b[$i] * $b[$i];
        }
        if ($na <= 0 || $nb <= 0) {
            return 0.0;
        }
        return $dot / (sqrt($na) * sqrt($nb));
    }

    /**
     * 「原文 × 訳出先言語」をキーに upsert（同じ組があれば訳・埋め込みを更新）。
     * 訳出先ごとに別レコードになるので、日本語訳と中国語訳が上書きし合うことはない。
     * @param array<int,float> $vec
     */
    public static function upsert(
        string $sourceText,
        string $sourceLang,
        string $targetText,
        string $origin = 'human_edit',
        ?int $videoId = null,
        array $vec = [],
        float $quality = 1.0,
        string $targetLang = \App\TargetLangs::DEFAULT
    ): void {
        $sourceText = trim($sourceText);
        $targetText = trim($targetText);
        if ($sourceText === '' || $targetText === '') {
            return;
        }
        $targetLang = \App\TargetLangs::normalize($targetLang);
        $db = App::db();
        $existing = $db->fetchValue(
            'SELECT id FROM translation_memory WHERE source_text = :s AND target_lang = :t LIMIT 1',
            [':s' => $sourceText, ':t' => $targetLang]
        );
        $data = [
            'source_lang'     => $sourceLang,
            'target_lang'     => $targetLang,
            'target_text'     => $targetText,
            'origin'          => $origin,
            'source_video_id' => $videoId,
            'quality'         => $quality,
            'embedding'       => $vec ? self::packVec($vec) : null,
        ];
        if ($existing !== null) {
            $db->update('translation_memory', $data, ['id' => (int) $existing]);
        } else {
            $data['source_text'] = $sourceText;
            $db->insert('translation_memory', $data);
        }
    }

    /**
     * 原文が完全一致する確定訳を一括取得する（正規化して比較）。
     * 人が確定した訳（origin in human_edit/import_ocr 等）を優先採用するために使う。
     * APIを呼ばずに済むため、コスト削減と精度向上を同時に達成できる。
     *
     * @param array<int,string> $sources 原文の配列
     * @param string $lang       原語
     * @param string $targetLang 訳出先（この言語の確定訳だけを対象にする）
     * @return array<string,string> 正規化原文 => 確定訳
     */
    public static function exactMatches(array $sources, string $lang, string $targetLang = \App\TargetLangs::DEFAULT): array
    {
        if (!$sources) {
            return [];
        }
        $sql = 'SELECT source_text, target_text FROM translation_memory WHERE target_lang = :t';
        $params = [':t' => \App\TargetLangs::normalize($targetLang)];
        if ($lang && $lang !== 'auto') {
            $sql .= ' AND (source_lang = :l OR source_lang = \'auto\')';
            $params[':l'] = $lang;
        }
        $map = [];
        foreach (App::db()->fetchAll($sql, $params) as $r) {
            $key = self::normalizeKey((string) $r['source_text']);
            $val = trim((string) $r['target_text']);
            // 同一原文が複数あれば最初の非空を採用（upsertで実質1件）
            if ($key !== '' && $val !== '' && !isset($map[$key])) {
                $map[$key] = $val;
            }
        }
        // 必要な原文だけに絞る
        $out = [];
        foreach ($sources as $s) {
            $k = self::normalizeKey($s);
            if (isset($map[$k])) {
                $out[$k] = $map[$k];
            }
        }
        return $out;
    }

    /** 完全一致判定用の正規化（前後空白除去・連続空白圧縮・小文字化） */
    public static function normalizeKey(string $s): string
    {
        $s = trim($s);
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
        return mb_strtolower($s);
    }

    /**
     * 埋め込みを持つ全エントリを索引として読み込む。
     * @return array<int,array{id:int,source_text:string,target_text:string,source_lang:string,vec:array<int,float>}>
     */
    public static function loadIndex(?string $lang = null, string $targetLang = \App\TargetLangs::DEFAULT): array
    {
        $sql = 'SELECT id, source_text, target_text, source_lang, embedding FROM translation_memory
                 WHERE embedding IS NOT NULL AND target_lang = :t';
        $params = [':t' => \App\TargetLangs::normalize($targetLang)];
        if ($lang && $lang !== 'auto') {
            $sql .= ' AND (source_lang = :l OR source_lang = \'auto\')';
            $params[':l'] = $lang;
        }
        $out = [];
        foreach (App::db()->fetchAll($sql, $params) as $r) {
            $out[] = [
                'id'          => (int) $r['id'],
                'source_text' => (string) $r['source_text'],
                'target_text' => (string) $r['target_text'],
                'source_lang' => (string) $r['source_lang'],
                'vec'         => self::unpackVec($r['embedding']),
            ];
        }
        return $out;
    }

    /**
     * クエリベクトルに最も近い top-k を索引から返す。
     * @param array<int,float> $queryVec
     * @param array<int,array> $index loadIndex() の結果
     * @return array<int,array{source_text:string,target_text:string,score:float}>
     */
    public static function searchInIndex(array $queryVec, array $index, int $k = 5, float $minScore = 0.55): array
    {
        if (!$queryVec || !$index) {
            return [];
        }
        $scored = [];
        foreach ($index as $row) {
            $score = self::cosine($queryVec, $row['vec']);
            if ($score >= $minScore) {
                $scored[] = ['source_text' => $row['source_text'], 'target_text' => $row['target_text'], 'score' => $score];
            }
        }
        usort($scored, static fn($a, $b) => $b['score'] <=> $a['score']);
        return array_slice($scored, 0, $k);
    }

    // ---- 管理画面用 ----

    public static function count(): int
    {
        return (int) App::db()->fetchValue('SELECT COUNT(*) FROM translation_memory');
    }

    public static function page(int $limit = 50, int $offset = 0): array
    {
        return App::db()->fetchAll(
            'SELECT id, source_text, source_lang, target_lang, target_text, origin, created_at
               FROM translation_memory ORDER BY id DESC LIMIT :lim OFFSET :off',
            [':lim' => $limit, ':off' => $offset]
        );
    }

    public static function delete(int $id): void
    {
        App::db()->run('DELETE FROM translation_memory WHERE id = :id', [':id' => $id]);
    }

    /**
     * 指定の原文かつ指定動画が書き込んだTM行を削除する。
     * 学習用動画でセグメントを削除/原文変更したときに、その動画が作ったTMを取り消すのに使う。
     * 「原文×訳出先」をキーに集約されるため、別動画が同じ組を上書きしている場合は対象外
     * （その動画が最後の書き込み元のときだけ消える）。
     * $targetLang を渡すと、その訳出先の行だけを消す（他言語の訳は残す）。
     */
    public static function deleteBySourceVideo(string $sourceText, int $videoId, ?string $targetLang = null): int
    {
        $sourceText = trim($sourceText);
        if ($sourceText === '') {
            return 0;
        }
        $sql = 'DELETE FROM translation_memory WHERE source_text = :s AND source_video_id = :v';
        $params = [':s' => $sourceText, ':v' => $videoId];
        if ($targetLang !== null) {
            $sql .= ' AND target_lang = :t';
            $params[':t'] = \App\TargetLangs::normalize($targetLang);
        }
        return App::db()->run($sql, $params)->rowCount();
    }
}
