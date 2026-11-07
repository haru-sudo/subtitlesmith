<?php

declare(strict_types=1);

namespace App;

use App\Repo\Glossary;

/**
 * AIを使って原文(原語)と訳文のペアから専門用語＋対訳を抽出し、
 * 用語集に直接登録する（完全自動）。訳語の言語は動画の訳出先に合わせる。
 *
 * 既存の TermMiner（頻度ベースで候補を貯め人が承認）と違い、
 * こちらはAIが訳語まで確定して用語集に入れる。
 * 対象は動画の分野（設定 content_domain）の専門用語で、一般語は除外する。
 */
final class GlossaryAI
{
    /** 1動画から登録する用語の上限（暴走防止） */
    private const MAX_TERMS = 40;
    /** AIに渡す対訳ペアの最大数（トークン節約。多すぎる場合は間引く） */
    private const MAX_PAIRS = 120;

    /**
     * 対応付け済みペアからAIで用語を抽出し、用語集にupsertする。
     *
     * @param array<int,array{source_text:string,ja_text:string,confidence:float}> $pairs
     *        ja_text は訳文（キー名は互換のため ja_text のまま。中身は $targetLang の言語）
     * @return int 登録（新規/更新）した用語数
     */
    public static function extractAndRegister(
        array $pairs,
        string $lang,
        ?int $videoId = null,
        string $targetLang = \App\TargetLangs::DEFAULT
    ): int {
        $targetLang = \App\TargetLangs::normalize($targetLang);
        // 原語が不明、または原語と訳語が同じ言語なら対訳にならないので何もしない
        if ($lang === '' || $lang === 'auto' || \App\TargetLangs::sameLanguage($lang, $targetLang)) {
            return 0;
        }

        // 信頼度の高い対訳ペアだけを使う
        $usable = [];
        foreach ($pairs as $p) {
            $src = trim((string) ($p['source_text'] ?? ''));
            $ja  = trim((string) ($p['ja_text'] ?? ''));
            if ($src === '' || $ja === '' || (float) ($p['confidence'] ?? 0) < 0.6) {
                continue;
            }
            $usable[] = ['src' => $src, 'ja' => $ja];
        }
        if (count($usable) < 2) {
            return 0;
        }

        // 多すぎる場合は均等に間引く
        if (count($usable) > self::MAX_PAIRS) {
            $step = (int) ceil(count($usable) / self::MAX_PAIRS);
            $usable = array_values(array_filter(
                $usable,
                static fn($_, $i) => $i % $step === 0,
                ARRAY_FILTER_USE_BOTH
            ));
        }

        $terms = self::askAI($usable, $lang, $targetLang);
        if (!$terms) {
            return 0;
        }

        // 既存用語(source_term)を取得して重複登録を避ける（同じ訳出先の中だけで判定）
        $existing = [];
        foreach (Glossary::forTarget($targetLang) as $g) {
            if (($g['source_lang'] ?? '') === $lang) {
                $existing[mb_strtolower(trim((string) $g['source_term']))] = true;
            }
        }

        $count = 0;
        foreach ($terms as $t) {
            if ($count >= self::MAX_TERMS) {
                break;
            }
            $source = trim((string) ($t['source'] ?? ''));
            $target = trim((string) ($t['target'] ?? ''));
            if ($source === '' || $target === '') {
                continue;
            }
            // 既にあればスキップ（usage_countだけ増やす手もあるが、まずは重複回避）
            if (isset($existing[mb_strtolower($source)])) {
                continue;
            }
            Glossary::create([
                'source_term'    => $source,
                'source_lang'    => $lang,
                'target_lang'    => $targetLang,
                'target_term'    => $target,
                'part_of_speech' => (string) ($t['pos'] ?? ''),
                'note'           => (string) ($t['note'] ?? ''),
                'origin'         => 'ai',
                'source_video_id' => $videoId,
            ]);
            $existing[mb_strtolower($source)] = true;
            $count++;
        }
        return $count;
    }

    /**
     * AIに対訳ペアを渡して用語リストをJSONで受け取る。
     *
     * @param array<int,array{src:string,ja:string}> $pairs
     * @return array<int,array{source:string,target:string,pos?:string,note?:string}>
     */
    private static function askAI(array $pairs, string $lang, string $targetLang): array
    {
        $langName = \App\TargetLangs::sourceName($lang);
        $targetName = \App\TargetLangs::name($targetLang);

        $lines = [];
        foreach ($pairs as $i => $p) {
            $lines[] = ($i + 1) . '. ' . $langName . ': ' . $p['src'] . "\n   " . $targetName . ': ' . $p['ja'];
        }
        $body = implode("\n", $lines);

        $domain = trim((string) App::config('content_domain', ''));
        $role = $domain !== '' ? "{$domain}の専門翻訳者" : '専門翻訳者';
        $priority = $domain !== ''
            ? "{$domain}の分野の専門用語を優先する"
            : '動画の分野に固有の専門用語を優先する';

        $system = <<<SYS
あなたは{$role}です。{$langName}と{$targetName}の対訳ペアから、
今後の翻訳で一貫して使うべき「専門用語の対訳」を抽出してください。

抽出の基準:
- {$priority}
- 一般的な日常語、文法的な語、固有名詞でない普通名詞は除外する
- 対訳ペアの中で実際に対応している語句のみを抽出する（推測で作らない）
- 原語の表記は原文に出てくる形（基本形・単数形が望ましい）にする
- 訳語は{$targetName}として自然で、その分野で標準的な訳にする

出力は必ず次のJSON配列のみ。前後に説明文やマークダウンを付けない:
[{"source":"原語の用語","target":"{$targetName}の訳語","pos":"品詞(任意:名詞/動詞/形容詞など)","note":"短い補足(任意)"}]

専門用語が無ければ空配列 [] を返す。
SYS;

        $user = "次の対訳ペアから専門用語の対訳を抽出してください:\n\n" . $body;

        try {
            $client = new Clients\ClaudeClient();
            $resp = $client->message([
                'system'     => $system,
                'messages'   => [['role' => 'user', 'content' => $user]],
                'max_tokens' => 4000,
            ]);
            $json = Clients\ClaudeClient::textFrom($resp);
        } catch (\Throwable $e) {
            App::log('用語集AI抽出に失敗: ' . $e->getMessage(), 'learning');
            return [];
        }

        return self::parseJsonArray($json);
    }

    /** AI応答からJSON配列を頑健に取り出す */
    private static function parseJsonArray(string $text): array
    {
        $text = trim($text);
        // ```json ... ``` で囲まれている場合を除去
        $text = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $text);
        $text = trim($text);

        // 最初の [ から最後の ] までを抜き出す
        $start = strpos($text, '[');
        $end = strrpos($text, ']');
        if ($start === false || $end === false || $end <= $start) {
            return [];
        }
        $slice = substr($text, $start, $end - $start + 1);
        $data = json_decode($slice, true);
        if (!is_array($data)) {
            return [];
        }
        // 期待する形だけ通す
        $out = [];
        foreach ($data as $row) {
            if (is_array($row) && isset($row['source'], $row['target'])) {
                $out[] = $row;
            }
        }
        return $out;
    }
}
