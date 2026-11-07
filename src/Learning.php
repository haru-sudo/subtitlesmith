<?php

declare(strict_types=1);

namespace App;

use App\Clients\OpenAIClient;
use App\Repo\Segments;
use App\Repo\Tm;
use App\Repo\Videos;

/**
 * 学習機構の書き込み側。確定した訳を翻訳メモリ(TM)へ蓄積する。
 * Web/ワーカーから呼ばれる。埋め込み取得に失敗しても例外を投げない（保存自体は継続）。
 */
final class Learning
{
    /** 1セグメントを記録（承認時など） */
    public static function recordSegment(
        array $seg,
        string $lang,
        string $origin = 'human_edit',
        float $quality = 1.0,
        string $targetLang = \App\TargetLangs::DEFAULT
    ): void {
        $source = trim((string) ($seg['source_text'] ?? ''));
        $target = trim((string) ($seg['edited_translation'] ?? ''));
        if ($source === '' || $target === '') {
            return;
        }
        $vec = self::embedOne($source);
        Tm::upsert(
            $source, $lang, $target, $origin,
            isset($seg['video_id']) ? (int) $seg['video_id'] : null,
            $vec, $quality, $targetLang
        );
    }

    /** 承認されたセグメントをTMへ（確定訳・高品質） */
    public static function recordApprovedSegment(int $videoId, int $sid, ?string $targetLang = null): void
    {
        self::recordSegmentById($videoId, $sid, 'human_edit', 1.0, $targetLang);
    }

    /**
     * セグメントIDを指定してTMへ記録する。
     * 学習用動画では「確認済み」にしなくても保存時にこれで反映する（品質は承認より低め）。
     * 訳(edited_translation)が空なら記録しない（呼び出し側で旧TMの削除を判断する）。
     */
    public static function recordSegmentById(
        int $videoId,
        int $sid,
        string $origin = 'human_edit',
        float $quality = 1.0,
        ?string $targetLang = null
    ): void {
        $video = Videos::find($videoId) ?? [];
        // 訳出先の指定が無ければ、その動画の主言語の訳を記録する
        $targetLang = ($targetLang !== null && $targetLang !== '')
            ? \App\TargetLangs::normalize($targetLang)
            : \App\TargetLangs::normalize($video['target_lang'] ?? null);
        $seg = Segments::find($sid, $targetLang);
        if ($seg === null || (int) $seg['video_id'] !== $videoId) {
            return;
        }
        $lang = (string) ($video['source_lang'] ?? 'auto');
        self::recordSegment($seg, $lang, $origin, $quality, $targetLang);
    }

    /**
     * 複数セグメントをまとめて記録（書き出し時・OCR取り込み時）。原文をバッチ埋め込み。
     * @param array<int,array> $segs
     */
    public static function recordSegments(
        array $segs,
        string $lang,
        string $origin = 'human_edit',
        float $quality = 0.8,
        string $targetLang = \App\TargetLangs::DEFAULT
    ): void {
        // 学習対象（原文・訳が揃うもの）に絞る
        $items = [];
        foreach ($segs as $s) {
            $source = trim((string) ($s['source_text'] ?? ''));
            $target = trim((string) ($s['edited_translation'] ?? ''));
            if ($source !== '' && $target !== '') {
                $items[] = ['source' => $source, 'target' => $target, 'video_id' => isset($s['video_id']) ? (int) $s['video_id'] : null];
            }
        }
        if (!$items) {
            return;
        }

        $vectors = self::embedMany(array_column($items, 'source'));
        foreach ($items as $i => $it) {
            Tm::upsert($it['source'], $lang, $it['target'], $origin, $it['video_id'], $vectors[$i] ?? [], $quality, $targetLang);
        }
    }

    // ---- 埋め込み（失敗時は空配列） ----

    private static function embedOne(string $text): array
    {
        $r = self::embedMany([$text]);
        return $r[0] ?? [];
    }

    /** @param array<int,string> $texts @return array<int,array<int,float>> 入力順（失敗時は各[]） */
    private static function embedMany(array $texts): array
    {
        if (!$texts) {
            return [];
        }
        try {
            $client = new OpenAIClient();
            $out = [];
            // APIの入力上限に配慮して分割
            foreach (array_chunk($texts, 200, true) as $chunk) {
                $vecs = $client->embed(array_values($chunk));
                $j = 0;
                foreach (array_keys($chunk) as $origIndex) {
                    $out[$origIndex] = $vecs[$j] ?? [];
                    $j++;
                }
            }
            ksort($out);
            return array_values($out);
        } catch (\Throwable $e) {
            App::log('埋め込み取得に失敗（TMは埋め込み無しで保存）: ' . $e->getMessage(), 'learning');
            return array_fill(0, count($texts), []);
        }
    }
}
