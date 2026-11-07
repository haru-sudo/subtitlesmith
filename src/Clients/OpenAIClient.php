<?php

declare(strict_types=1);

namespace App\Clients;

use App\App;
use App\Support\HttpClient;

/**
 * OpenAI API クライアント（文字起こし=Whisper, 埋め込み=Embeddings）。
 */
final class OpenAIClient
{
    private string $apiKey;

    public function __construct(?string $apiKey = null)
    {
        $this->apiKey = $apiKey ?? (string) \App\Settings::get('openai_api_key', '');
    }

    private function requireKey(): void
    {
        if ($this->apiKey === '') {
            throw new \RuntimeException('OpenAI APIキーが未設定です。設定ページの「APIキー」から登録してください。');
        }
    }

    /**
     * 音声を文字起こし（Whisper, verbose_json）。
     *
     * @param string      $audioPath 音声ファイルの絶対パス
     * @param string|null $lang      'en'|'de'|'ja' 等。null/'auto' で自動判定
     * @return array{language:string, segments:array<int,array{start_ms:int,end_ms:int,text:string}>}
     */
    public function transcribe(string $audioPath, ?string $lang = null, ?string $model = null): array
    {
        $this->requireKey();
        if (!is_file($audioPath)) {
            throw new \RuntimeException('音声ファイルがありません: ' . $audioPath);
        }

        $useModel = $model !== null && $model !== ''
            ? $model
            : (string) App::config('transcribe_model', 'whisper-1');

        // セグメント時刻を返せるのは whisper-1 の verbose_json だけ。
        // gpt-4o-transcribe / mini は response_format が json|text のみで、
        // timestamp_granularities も verbose_json 専用のため時刻が一切返らない。
        // 時刻の無い結果を字幕に使うと文字数比で時刻を推定するしかなく、
        // 誤差が積み上がって動画の後半ほど音声とずれる。字幕用途では時刻が必須なので、
        // gpt-4o系が指定されたときは whisper-1 に切り替えて文字起こしする。
        if (strpos($useModel, 'gpt-4o') === 0) {
            App::log(
                'gpt-4o系はセグメント時刻を返さないため whisper-1 で文字起こしします（指定: ' . $useModel . '）',
                'worker'
            );
            $useModel = 'whisper-1';
        }

        $fields = [
            'model'           => $useModel,
            'file'            => new \CURLFile($audioPath),
            'response_format' => 'verbose_json',
        ];
        if ($lang && $lang !== 'auto') {
            $fields['language'] = $lang;
        }

        [$status, $body] = HttpClient::postMultipart(
            'https://api.openai.com/v1/audio/transcriptions',
            ['Authorization' => 'Bearer ' . $this->apiKey],
            $fields
        );

        if ($status !== 200) {
            $msg = $body['error']['message'] ?? json_encode($body, JSON_UNESCAPED_UNICODE);
            throw new \RuntimeException("文字起こしAPIエラー($status): $msg");
        }

        $segments = [];
        foreach (($body['segments'] ?? []) as $i => $seg) {
            $text = trim((string) ($seg['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $segments[] = [
                'start_ms' => (int) round(((float) ($seg['start'] ?? 0)) * 1000),
                'end_ms'   => (int) round(((float) ($seg['end'] ?? 0)) * 1000),
                'text'     => $text,
            ];
        }

        // segments が空でも text 全体があれば、文単位に分割して概算の時刻を割り当てる（最後の手段）。
        // 実音声の総尺に収めるので「後半ほど際限なくずれる」ことは無いが、あくまで按分の概算。
        // 気づかず使い続けないよう、発生したことをログに残す。
        if (!$segments && !empty($body['text'])) {
            $totalMs = (int) round(((float) ($body['duration'] ?? 0)) * 1000);
            if ($totalMs <= 0) {
                $totalMs = \App\Media::probeDurationMs($audioPath) ?? 0;
            }
            App::log(
                '文字起こしAPIがセグメント時刻を返しませんでした（' . $useModel . '）。'
                . '時刻を文字数比で概算します（音声とずれる可能性あり）。',
                'worker'
            );
            $segments = self::splitTextToSegments(trim((string) $body['text']), $totalMs);
        }

        return [
            'language' => self::normalizeLang((string) ($body['language'] ?? '')),
            'segments' => $segments,
        ];
    }

    /**
     * タイムスタンプの無い全文テキストを、文区切りで擬似セグメント化する。
     * 時刻は文字数比で概算（後段の編集で微調整できる前提）。
     * @return array<int,array{start_ms:int,end_ms:int,text:string}>
     */
    private static function splitTextToSegments(string $text, int $totalMs = 0): array
    {
        if ($text === '') {
            return [];
        }
        // 文末（. ! ? 。！？ 改行）で分割
        $parts = preg_split('/(?<=[\.!\?。！？\n])\s+/u', $text) ?: [$text];
        $parts = array_values(array_filter(array_map('trim', $parts), static fn($s) => $s !== ''));
        if (!$parts) {
            return [];
        }

        // 総時間が不明なら、1文字あたり約60ms（日本語/英語混在の概算）で見積もる
        $totalChars = array_sum(array_map('mb_strlen', $parts));
        if ($totalMs <= 0) {
            $totalMs = max(1000, (int) ($totalChars * 60));
        }

        $segments = [];
        $cursor = 0;
        foreach ($parts as $p) {
            $share = $totalChars > 0 ? mb_strlen($p) / $totalChars : 1.0 / count($parts);
            $dur = max(500, (int) round($totalMs * $share));
            $segments[] = [
                'start_ms' => $cursor,
                'end_ms'   => $cursor + $dur,
                'text'     => $p,
            ];
            $cursor += $dur;
        }
        return $segments;
    }

    /**
     * テキスト配列を埋め込みベクトルに変換。
     *
     * @param array<int,string> $texts
     * @return array<int, array<int,float>> 入力順のベクトル配列
     */
    public function embed(array $texts): array
    {
        $this->requireKey();
        if (!$texts) {
            return [];
        }

        $payload = [
            'model' => (string) App::config('embedding_model', 'text-embedding-3-small'),
            'input' => array_values($texts),
        ];
        $dim = (int) App::config('embedding_dim', 0);
        if ($dim > 0) {
            $payload['dimensions'] = $dim;
        }

        [$status, $body] = HttpClient::requestJson(
            'POST',
            'https://api.openai.com/v1/embeddings',
            ['Authorization' => 'Bearer ' . $this->apiKey],
            $payload
        );

        if ($status !== 200) {
            $msg = $body['error']['message'] ?? json_encode($body, JSON_UNESCAPED_UNICODE);
            throw new \RuntimeException("埋め込みAPIエラー($status): $msg");
        }

        $rows = $body['data'] ?? [];
        // index順に整列
        usort($rows, static fn($a, $b) => ($a['index'] ?? 0) <=> ($b['index'] ?? 0));
        return array_map(static fn($r) => array_map('floatval', $r['embedding'] ?? []), $rows);
    }

    /** Whisperの言語名(english/german/japanese) を ISOコードへ寄せる */
    private static function normalizeLang(string $lang): string
    {
        $l = strtolower(trim($lang));
        return match ($l) {
            'english', 'en' => 'en',
            'german', 'de'  => 'de',
            'japanese', 'ja' => 'ja',
            default => $l !== '' ? $l : 'auto',
        };
    }
}
