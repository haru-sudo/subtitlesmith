<?php

declare(strict_types=1);

namespace App;

use App\Repo\Segments;
use App\Repo\Videos;

/**
 * 文字起こし（と訳文）の内容から、動画の「内容（説明文）」をAIで自動生成する。
 * 説明文の言語は config description_lang（未設定なら既定の訳出先）。
 *
 * 用途: アップロード時に手で説明を書かなくても、一覧カードに出る短い概要を自動で用意する。
 * 既に説明が入っている動画は上書きしない。失敗してもパイプラインは止めない。
 */
final class DescribeAI
{
    /** AIへ渡す本文の最大文字数（トークン節約のため長尺は先頭を中心に間引く） */
    private const MAX_CHARS = 6000;
    /** 生成される説明文のおおよその上限文字数（日本語） */
    private const TARGET_CHARS = 160;

    /**
     * 指定動画の説明文をAIで生成して保存する。
     * - auto_describe が無効、または既に説明があるなら何もしない。
     *
     * @return bool 生成して保存したら true
     */
    public static function generateForVideo(int $videoId): bool
    {
        $v = Videos::find($videoId);
        if ($v === null) {
            return false;
        }
        if ((int) ($v['auto_describe'] ?? 0) !== 1) {
            return false; // 自動生成オフ
        }
        if (trim((string) ($v['description'] ?? '')) !== '') {
            return false; // 既に説明あり（手入力を尊重）
        }

        $segments = Segments::forVideo($videoId);
        $transcript = self::buildTranscript($segments);
        if ($transcript === '') {
            return false;
        }

        $desc = self::askAI($transcript, (string) $v['title']);
        if ($desc === '') {
            return false;
        }

        Videos::update($videoId, ['description' => $desc]);
        return true;
    }

    /**
     * セグメントから要約用テキストを組み立てる。
     * 文字起こし（原文）を主軸にしつつ、日本語訳があれば併記して文脈を補う。
     */
    private static function buildTranscript(array $segments): string
    {
        $lines = [];
        foreach ($segments as $s) {
            $src = trim((string) ($s['source_text'] ?? ''));
            $ja = trim((string) ($s['edited_translation'] ?? ''));
            if ($ja === '') {
                $ja = trim((string) ($s['machine_translation'] ?? ''));
            }
            // 訳文があれば訳文を優先（説明文は訳出先の言語で書くことが多いため）
            $line = $ja !== '' ? $ja : $src;
            if ($line !== '') {
                $lines[] = $line;
            }
        }
        if (!$lines) {
            return '';
        }
        $text = implode("\n", $lines);
        if (mb_strlen($text) <= self::MAX_CHARS) {
            return $text;
        }
        // 長尺は先頭・中盤・末尾から均等に抜き出して全体像を保つ
        $head = mb_substr($text, 0, (int) (self::MAX_CHARS * 0.5));
        $mid = mb_substr($text, (int) (mb_strlen($text) / 2), (int) (self::MAX_CHARS * 0.25));
        $tail = mb_substr($text, -((int) (self::MAX_CHARS * 0.25)));
        return $head . "\n…\n" . $mid . "\n…\n" . $tail;
    }

    /** AIに要約を依頼し、説明文（プレーンテキスト）を返す。失敗時は空文字。 */
    private static function askAI(string $transcript, string $title): string
    {
        // 説明文の言語（config description_lang。未設定なら既定の訳出先の言語）
        $lang = TargetLangs::normalize((string) App::config('description_lang', TargetLangs::current()));
        $langName = TargetLangs::name($lang);
        // 全角の言語は字数、ラテン文字などはその倍の文字数を目安にする
        $target = TargetLangs::isWide($lang) ? self::TARGET_CHARS : self::TARGET_CHARS * 2;
        $max = TargetLangs::isWide($lang) ? 200 : 400;
        $domain = trim((string) App::config('content_domain', ''));
        $system = ($domain !== '' ? "あなたは{$domain}の動画を整理する編集者です。\n" : "あなたは動画を整理する編集者です。\n")
            . "動画の文字起こし（訳文があれば訳文）を読み、一覧で内容が一目で分かる短い説明文を{$langName}で書いてください。\n\n"
            . "ルール:\n"
            . "- {$langName}で、全体で{$target}文字程度（最大でも{$max}文字）に収める\n"
            . "- 何についての動画かが分かるように、主題・扱う技術や場面・対象を簡潔にまとめる\n"
            . "- 「この動画は」などの前置きや、視聴者への呼びかけは書かない\n"
            . "- 推測で具体的な数値や固有名詞を補わない。文字起こしに無い情報は足さない\n"
            . "- 箇条書きや記号、マークダウンは使わず、説明文の本文だけを出力する";

        $user = "タイトル: " . ($title !== '' ? $title : '(未設定)') . "\n\n"
            . "文字起こし（抜粋）:\n" . $transcript . "\n\n"
            . "上記の内容を表す説明文を書いてください。";

        try {
            $client = new Clients\ClaudeClient();
            $resp = $client->message([
                'system'     => $system,
                'messages'   => [['role' => 'user', 'content' => $user]],
                'max_tokens' => 600,
            ]);
            $text = Clients\ClaudeClient::textFrom($resp);
        } catch (\Throwable $e) {
            App::log('内容のAI自動生成に失敗: ' . $e->getMessage(), 'learning');
            return '';
        }

        return self::clean($text);
    }

    /** 余計な前置き・記号・改行を取り除いて1段落の説明文にする。 */
    private static function clean(string $text): string
    {
        $text = trim($text);
        // ```で囲まれた出力を除去
        $text = preg_replace('/^```[a-zA-Z]*\s*|\s*```$/m', '', $text) ?? $text;
        // 先頭の箇条書き記号や引用符を除去
        $text = preg_replace('/^[\s>・*\-「『]+/u', '', trim($text)) ?? $text;
        // 改行は読点的に詰める（カードでは1行表示のため）
        $text = preg_replace('/\s*\n\s*/u', ' ', trim($text)) ?? $text;
        $text = trim($text);
        // 念のため長すぎる場合は丸める
        if (mb_strlen($text) > 240) {
            $text = mb_substr($text, 0, 238) . '…';
        }
        return $text;
    }
}
