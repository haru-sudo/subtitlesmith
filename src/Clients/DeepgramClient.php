<?php

declare(strict_types=1);

namespace App\Clients;

use App\App;
use App\Repo\Glossary;
use App\Settings;
use App\Support\HttpClient;

/**
 * Deepgram 文字起こしクライアント（事前録音 /v1/listen, Nova-3）。
 *
 * 返り値は OpenAIClient::transcribe と同じ形:
 *   ['language' => string, 'segments' => [['start_ms'=>int,'end_ms'=>int,'text'=>string], ...]]
 *
 * Deepgram は大きなファイルもそのまま受けられるため、音声分割は不要。
 */
final class DeepgramClient
{
    private string $apiKey;

    public function __construct(?string $apiKey = null)
    {
        $this->apiKey = $apiKey ?? (string) Settings::get('deepgram_api_key', '');
    }

    private function requireKey(): void
    {
        if ($this->apiKey === '') {
            throw new \RuntimeException('Deepgram APIキーが未設定です。設定ページの「APIキー」から登録してください。');
        }
    }

    /**
     * @param string      $audioPath 音声ファイルの絶対パス（mp3想定）
     * @param string|null $lang      'en' 等。null/'auto' で自動判定
     * @param string|null $model     例 'deepgram-nova-3'（接頭辞は除去）。既定 nova-3
     * @return array{language:string, segments:array<int,array{start_ms:int,end_ms:int,text:string}>}
     */
    public function transcribe(string $audioPath, ?string $lang = null, ?string $model = null): array
    {
        $this->requireKey();
        if (!is_file($audioPath)) {
            throw new \RuntimeException('音声ファイルがありません: ' . $audioPath);
        }

        $dgModel = ($model !== null && $model !== '')
            ? (string) preg_replace('/^deepgram-/', '', $model)
            : 'nova-3';
        if ($dgModel === '') {
            $dgModel = 'nova-3';
        }

        // 文整形・句読点・発話区切り(utterances)で、時刻つきセグメントを得る
        $params = [
            'model'        => $dgModel,
            'smart_format' => 'true',
            'punctuate'    => 'true',
            'utterances'   => 'true',
        ];

        // 専門用語や固有名詞を正しく拾わせるためのキーターム（用語集＋手動指定）。
        // 例: 「Kubernetes」が別の語に化けるのを防ぐ。
        $isNova3 = str_starts_with($dgModel, 'nova-3');
        $keyterms = self::keyterms($lang);

        if ($lang && $lang !== 'auto') {
            $params['language'] = $lang;
        } elseif ($isNova3 && $keyterms) {
            // 自動判定でもキータームを効かせたい場合、Nova-3の多言語モードなら両立できる
            $params['language'] = 'multi';
        } else {
            $params['detect_language'] = 'true';
        }
        $url = 'https://api.deepgram.com/v1/listen?' . http_build_query($params);

        // キータームはクエリに繰り返しパラメータで付与する（http_build_queryは同名反復ができないため手組み）。
        if ($keyterms) {
            if ($isNova3) {
                // Nova-3: 単一言語は keyterm / language=multi は keyterms（複数言語キータームプロンプト）
                $ktName = (($params['language'] ?? '') === 'multi') ? 'keyterms' : 'keyterm';
                foreach ($keyterms as $t) {
                    $url .= '&' . $ktName . '=' . rawurlencode($t);
                }
            } else {
                // Nova-2 以前: keywords（keyterm非対応のモデル向けの後方互換）
                foreach ($keyterms as $t) {
                    $url .= '&keywords=' . rawurlencode($t);
                }
            }
        }

        $body = @file_get_contents($audioPath);
        if ($body === false) {
            throw new \RuntimeException('音声ファイルを読み込めません: ' . $audioPath);
        }

        [$status, $resp] = HttpClient::postRaw(
            $url,
            ['Authorization' => 'Token ' . $this->apiKey],
            $body,
            self::contentType($audioPath)
        );

        if ($status !== 200) {
            $msg = $resp['err_msg'] ?? ($resp['reason'] ?? ($resp['error'] ?? json_encode($resp, JSON_UNESCAPED_UNICODE)));
            throw new \RuntimeException("文字起こしAPIエラー($status): " . (is_string($msg) ? $msg : json_encode($msg, JSON_UNESCAPED_UNICODE)));
        }

        return [
            'language' => self::extractLanguage($resp, $lang),
            'segments' => self::extractSegments($resp),
        ];
    }

    /**
     * Deepgramのキータームを組み立てる。
     *  - 設定「Deepgram キーターム」(手動, 改行/カンマ区切り)
     *  - 用語集(Glossary)  … 既定ON。音声言語に合わせて原語/訳語を選ぶ。
     *      日本語音声(ja)   → 訳語（日本語の専門用語。例: 機械学習）
     *      英/独など        → その言語の原語（例: Kubernetes / fine-tuning）
     *      自動判定(auto)   → 原語・訳語の両方を候補にする
     * 重複を無視して最大100件（Deepgramの上限目安）に丸める。
     *
     * @param string|null $lang 'en' 等。null/'auto' は自動判定
     * @return array<int,string>
     */
    private static function keyterms(?string $lang): array
    {
        $terms = [];

        // 手動指定（設定ページ）
        $manual = (string) Settings::get('deepgram_keyterms', '');
        if ($manual !== '') {
            foreach (preg_split('/[\r\n,、]+/u', $manual) ?: [] as $t) {
                $terms[] = $t;
            }
        }

        // 用語集から（既定ON。DB不調などでは黙って無視して文字起こしは続行）
        if (Settings::getBool('deepgram_keyterm_glossary', true)) {
            try {
                $isJa   = ($lang !== null && str_starts_with($lang, 'ja'));
                $langKey = ($lang === null || $lang === 'auto' || $lang === 'multi') ? null : $lang;
                // 音声が日本語なら「日本語の訳語」だけが役に立つので、その訳出先の用語に絞る
                $entries = $isJa ? Glossary::forTarget('ja') : Glossary::all();
                foreach ($entries as $g) {
                    if ($isJa) {
                        $terms[] = (string) ($g['target_term'] ?? '');
                    } elseif ($langKey !== null) {
                        $sl = (string) ($g['source_lang'] ?? '');
                        if ($sl === '' || $sl === 'auto' || $sl === $langKey) {
                            $terms[] = (string) ($g['source_term'] ?? '');
                        }
                    } else {
                        $terms[] = (string) ($g['source_term'] ?? '');
                        $terms[] = (string) ($g['target_term'] ?? '');
                    }
                }
            } catch (\Throwable $e) {
                App::log('Deepgramキーターム用の用語集取得に失敗（続行）: ' . $e->getMessage(), 'worker');
            }
        }

        // 整形: トリム・空白正規化・空/長すぎ除去・大小無視で重複排除
        $clean = [];
        foreach ($terms as $t) {
            $t = trim((string) preg_replace('/\s+/u', ' ', (string) $t));
            if ($t === '' || mb_strlen($t) > 80) {
                continue;
            }
            $clean[mb_strtolower($t)] = $t;
        }
        return array_slice(array_values($clean), 0, 100);
    }

    /**
     * セグメントには単語ごとの時刻(words)も添える。
     * 後段の Segmenter が文の区切り直しをするとき、セグメントの[start,end]を
     * 文字数比で按分すると話速や間の分だけ境界がずれるため、実際の単語時刻を使わせる。
     *
     * @return array<int,array{start_ms:int,end_ms:int,text:string,words:array<int,array{text:string,start_ms:int,end_ms:int}>}>
     */
    private static function extractSegments(array $resp): array
    {
        $segments = [];

        // 1) utterances（発話区切り）を優先
        foreach (($resp['results']['utterances'] ?? []) as $u) {
            $text = trim((string) ($u['transcript'] ?? ''));
            if ($text === '') {
                continue;
            }
            $segments[] = [
                'start_ms' => (int) round(((float) ($u['start'] ?? 0)) * 1000),
                'end_ms'   => (int) round(((float) ($u['end'] ?? 0)) * 1000),
                'text'     => $text,
                'words'    => self::words($u['words'] ?? []),
            ];
        }
        if ($segments) {
            return $segments;
        }

        $alt = $resp['results']['channels'][0]['alternatives'][0] ?? [];
        // paragraphs には単語が入らないので、チャンネル全体の単語列から時間で切り出す
        $allWords = self::words($alt['words'] ?? []);

        // 2) paragraphs.sentences
        foreach (($alt['paragraphs']['paragraphs'] ?? []) as $p) {
            foreach (($p['sentences'] ?? []) as $s) {
                $text = trim((string) ($s['text'] ?? ''));
                if ($text === '') {
                    continue;
                }
                $st = (int) round(((float) ($s['start'] ?? 0)) * 1000);
                $en = (int) round(((float) ($s['end'] ?? 0)) * 1000);
                $segments[] = [
                    'start_ms' => $st,
                    'end_ms'   => $en,
                    'text'     => $text,
                    'words'    => self::wordsInRange($allWords, $st, $en),
                ];
            }
        }
        if ($segments) {
            return $segments;
        }

        // 3) 最終フォールバック: 全文を1セグメントに（時刻は単語の端から）
        $transcript = trim((string) ($alt['transcript'] ?? ''));
        if ($transcript !== '') {
            $start = $allWords ? $allWords[0]['start_ms'] : 0;
            $end = $allWords ? $allWords[count($allWords) - 1]['end_ms'] : $start + 1000;
            $segments[] = [
                'start_ms' => $start,
                'end_ms'   => $end,
                'text'     => $transcript,
                'words'    => $allWords,
            ];
        }
        return $segments;
    }

    /**
     * Deepgramの単語配列を [{text,start_ms,end_ms}, ...] に正規化する。
     * 表記は punctuated_word（句読点・大文字を反映した形）を優先し、transcript と一致させる。
     *
     * @param mixed $words
     * @return array<int,array{text:string,start_ms:int,end_ms:int}>
     */
    private static function words(mixed $words): array
    {
        if (!is_array($words)) {
            return [];
        }
        $out = [];
        foreach ($words as $w) {
            if (!is_array($w)) {
                continue;
            }
            $t = trim((string) ($w['punctuated_word'] ?? $w['word'] ?? ''));
            if ($t === '') {
                continue;
            }
            $s = (int) round(((float) ($w['start'] ?? 0)) * 1000);
            $e = (int) round(((float) ($w['end'] ?? 0)) * 1000);
            $out[] = ['text' => $t, 'start_ms' => $s, 'end_ms' => max($s, $e)];
        }
        return $out;
    }

    /**
     * 単語列から [startMs, endMs] に収まるものを取り出す（paragraphs用）。
     * @param array<int,array{text:string,start_ms:int,end_ms:int}> $words
     * @return array<int,array{text:string,start_ms:int,end_ms:int}>
     */
    private static function wordsInRange(array $words, int $startMs, int $endMs): array
    {
        $out = [];
        foreach ($words as $w) {
            // 境界の丸め誤差を吸収するため中心位置で判定する
            $mid = intdiv($w['start_ms'] + $w['end_ms'], 2);
            if ($mid >= $startMs && $mid <= $endMs) {
                $out[] = $w;
            }
        }
        return $out;
    }

    private static function extractLanguage(array $resp, ?string $lang): string
    {
        $detected = $resp['results']['channels'][0]['detected_language']
            ?? ($resp['results']['channels'][0]['alternatives'][0]['languages'][0] ?? '');
        $detected = is_string($detected) ? trim($detected) : '';
        if ($detected !== '') {
            return $detected;
        }
        return ($lang && $lang !== 'auto') ? $lang : 'auto';
    }

    private static function contentType(string $path): string
    {
        $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        return match ($ext) {
            'wav' => 'audio/wav',
            'm4a', 'mp4' => 'audio/mp4',
            'ogg', 'opus' => 'audio/ogg',
            'flac' => 'audio/flac',
            'webm' => 'audio/webm',
            default => 'audio/mpeg', // mp3 等
        };
    }
}
