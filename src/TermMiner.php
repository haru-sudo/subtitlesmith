<?php

declare(strict_types=1);

namespace App;

use App\Repo\GlossaryCandidates;

/**
 * 完成動画のOCR取り込み時に、原文から「用語集の候補」を控えめに抽出する。
 *
 * 方針（重要）:
 * - 自動では翻訳に使わない。あくまで人が承認するための「候補」を貯めるだけ。
 * - 原語側の頻出フレーズ/専門語だけを拾う。訳語(日本語)は機械的に切り出すと
 *   OCR/文字起こしの誤りをそのまま固定化する危険があるため、自動では確定しない。
 *   代わりに出典の原文・訳文を「例」として保存し、承認時に人が訳語を入れる。
 * - 既に用語集にある語、ありふれた語(ストップワード)は候補にしない。
 */
final class TermMiner
{
    /** これ未満の出現回数の候補は捨てる（ノイズ抑制） */
    private const MIN_FREQUENCY = 2;
    /** 1動画から提案する候補の上限 */
    private const MAX_PER_VIDEO = 25;

    /** ごくありふれた語（英・独）。これらは単独では候補にしない。 */
    private const STOPWORDS = [
        // 英語
        'the','a','an','and','or','but','if','then','so','to','of','in','on','at','for','with','from',
        'by','as','is','are','was','were','be','been','being','it','its','this','that','these','those',
        'i','you','he','she','we','they','him','her','them','his','their','your','our','my','me',
        'do','does','did','have','has','had','can','could','will','would','should','may','might','must',
        'not','no','yes','here','there','now','very','just','really','okay','ok','well','also','too',
        'go','going','get','got','let','lets','want','need','make','made','like','little','bit','more','much',
        'one','two','up','down','out','into','about','over','again','what','when','where','which','how','why',
        // ドイツ語
        'der','die','das','und','oder','aber','wenn','dann','also','zu','von','in','auf','an','für','mit',
        'aus','bei','ist','sind','war','waren','sein','es','dies','dass','ich','du','er','sie','wir','ihr',
        'nicht','kein','ja','hier','jetzt','sehr','nur','auch','schon','noch','mal','ein','eine','einen','dem','den',
    ];

    /**
     * 対応付け済みペアから候補を抽出してDBへ蓄積する。
     *
     * @param array<int,array{source_text:string,ja_text:string,confidence:float}> $pairs
     * @param int|null $videoId 生成元の動画ID（学習用動画の削除時に連動削除するため）
     */
    public static function mineFromPairs(array $pairs, string $lang, ?int $videoId = null): int
    {
        if ($lang !== 'en' && $lang !== 'de') {
            return 0; // 対応言語のみ
        }

        // 候補語 => ['freq'=>n, 'src'=>例文, 'ja'=>例訳]
        $cands = [];
        foreach ($pairs as $p) {
            $conf = (float) ($p['confidence'] ?? 0);
            if ($conf < 0.6) {
                continue; // 信頼度の低い対応からは抽出しない
            }
            $src = (string) ($p['source_text'] ?? '');
            $ja  = (string) ($p['ja_text'] ?? '');
            if ($src === '' || $ja === '') {
                continue;
            }
            foreach (self::extractTerms($src) as $term) {
                if (!isset($cands[$term])) {
                    $cands[$term] = ['freq' => 0, 'src' => $src, 'ja' => $ja];
                }
                $cands[$term]['freq']++;
            }
        }

        // 頻度フィルタ＋既存用語集の除外＋上限
        $filtered = [];
        foreach ($cands as $term => $info) {
            if ($info['freq'] < self::MIN_FREQUENCY) {
                continue;
            }
            if (GlossaryCandidates::existsInGlossary($lang, $term)) {
                continue;
            }
            $filtered[$term] = $info;
        }
        uasort($filtered, static fn($a, $b) => $b['freq'] <=> $a['freq']);
        $filtered = array_slice($filtered, 0, self::MAX_PER_VIDEO, true);

        $count = 0;
        foreach ($filtered as $term => $info) {
            // target_term は空（人が承認時に入れる）。頻度分だけ加算しておく。
            for ($i = 0; $i < $info['freq']; $i++) {
                GlossaryCandidates::upsert($lang, $term, '', $info['src'], $info['ja'], $videoId);
            }
            $count++;
        }
        return $count;
    }

    /**
     * 1文から候補となる用語を取り出す。
     * - 2語の連語（バイグラム）でストップワードを含まないもの
     * - 大文字始まり/全大文字の語（固有名詞・専門略語の可能性）
     * いずれも小文字化して正規化する。
     *
     * @return array<int,string> 重複排除済みの候補語
     */
    private static function extractTerms(string $sentence): array
    {
        // 記号を空白に、トークン化
        $clean = preg_replace('/[^\p{L}\p{N}\-\' ]+/u', ' ', $sentence) ?? '';
        $rawTokens = preg_split('/\s+/u', trim($clean)) ?: [];
        $tokens = array_values(array_filter($rawTokens, static fn($t) => $t !== ''));

        $terms = [];

        // 大文字始まり/全大文字の単独語（文頭は除外しづらいので2文字超に限定）
        foreach ($tokens as $i => $tok) {
            $low = mb_strtolower($tok);
            if (in_array($low, self::STOPWORDS, true)) {
                continue;
            }
            // ハイフン付きの語（fine-tuning など）は専門語の可能性が高い → 単独でも候補
            if (mb_strpos($low, '-') !== false && mb_strlen($low) >= 4) {
                $terms[$low] = true;
                continue;
            }
            if (mb_strlen($tok) >= 3) {
                $isUpperStart = preg_match('/^\p{Lu}/u', $tok) === 1;
                $isAllCaps = preg_match('/^\p{Lu}{2,}$/u', $tok) === 1;
                // 文頭(i==0)の大文字始まりはただの文の始まりなので採用しない
                if (($isUpperStart && $i > 0) || $isAllCaps) {
                    $terms[$low] = true;
                }
            }
        }

        // バイグラム（連続2語、両方が非ストップワードで英字主体）
        for ($i = 0; $i < count($tokens) - 1; $i++) {
            $a = mb_strtolower($tokens[$i]);
            $b = mb_strtolower($tokens[$i + 1]);
            if (in_array($a, self::STOPWORDS, true) || in_array($b, self::STOPWORDS, true)) {
                continue;
            }
            if (mb_strlen($a) < 2 || mb_strlen($b) < 2) {
                continue;
            }
            if (!preg_match('/^[\p{L}\-\']+$/u', $a) || !preg_match('/^[\p{L}\-\']+$/u', $b)) {
                continue;
            }
            $terms[$a . ' ' . $b] = true;
        }

        return array_keys($terms);
    }
}
