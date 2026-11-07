<?php

declare(strict_types=1);

namespace App;

use App\Clients\OpenAIClient;
use App\Support\Proc;

/**
 * 文字起こしの入口。設定に応じてエンジンを選ぶ:
 *  - OpenAI API (gpt-4o-transcribe / mini / whisper-1)
 *  - ローカル faster-whisper (APIコスト0)
 *
 * 返り値は OpenAIClient::transcribe と同じ形:
 *   ['language' => string, 'segments' => [['start_ms'=>int,'end_ms'=>int,'text'=>string], ...]]
 */
final class Transcriber
{
    /**
     * @param string $audioAbs     音声ファイルの絶対パス
     * @param string $lang         'auto' または言語コード
     * @param string $engine       TranscribeModels のエンジンID
     * @param array  $ocrSegments  OCRセグメント [{start_ms,end_ms}, ...]。
     *                             指定するとWhisperをOCR境界で分割文字起こしする。
     */
    public static function run(string $audioAbs, string $lang, string $engine = '', array $ocrSegments = [], ?callable $onProgress = null): array
    {
        if ($engine === '' || !TranscribeModels::isValid($engine)) {
            $engine = TranscribeModels::default();
        }

        if (TranscribeModels::isLocal($engine)) {
            return self::runLocal($audioAbs, $lang, $ocrSegments, $onProgress);
        }

        // Deepgram（大きいファイルもそのまま送れるので分割不要）
        if (TranscribeModels::kind($engine) === 'deepgram') {
            if ($onProgress !== null) {
                $onProgress(50.0);
            }
            $r = (new \App\Clients\DeepgramClient())
                ->transcribe($audioAbs, $lang === 'auto' ? null : $lang, $engine);
            if ($onProgress !== null) {
                $onProgress(100.0);
            }
            return $r;
        }

        // API（モデルIDをそのまま渡す）。
        $client = new OpenAIClient();
        $langArg = $lang === 'auto' ? null : $lang;

        // 既定では分割せず1リクエストで送る（境界での欠け・文の途中切れを避け精度を優先）。
        // ただしAPIのファイルサイズ上限(約25MB)を超える長尺は送信できないため、その場合のみ自動分割。
        // 積極的に分割したい場合は設定 transcribe_api_chunk=true で有効化できる。
        $fileBytes = is_file($audioAbs) ? (int) filesize($audioAbs) : 0;
        $maxBytes = 24 * 1024 * 1024; // 25MB上限の手前で安全マージン
        $forceChunk = ($fileBytes > $maxBytes);
        $optInChunk = (bool) App::config('transcribe_api_chunk', false);

        if ($forceChunk || $optInChunk) {
            $durationMs = Media::probeDurationMs($audioAbs) ?? 0;
            if ($durationMs > 0) {
                if ($optInChunk) {
                    $chunkMs = max(60000, (int) App::config('transcribe_api_chunk_ms', 10 * 60 * 1000));
                } else {
                    // サイズ超過のみ: 上限に収まる最大長で“最小回数”に分割（むやみに割らない）
                    $ratio = ($fileBytes > 0) ? ($maxBytes / $fileBytes) : 1.0;
                    $chunkMs = max(60000, (int) floor($durationMs * $ratio * 0.9));
                }
                if ($durationMs > $chunkMs + 5000) {
                    return self::runApiChunked($client, $audioAbs, $langArg, $engine, $durationMs, $chunkMs, $onProgress);
                }
            }
        }

        // API はストリーミング進捗が無いので軽く合図だけ。
        if ($onProgress !== null) {
            $onProgress(50.0);
        }
        $r = $client->transcribe($audioAbs, $langArg, $engine);
        if ($onProgress !== null) {
            $onProgress(100.0);
        }
        return $r;
    }

    /**
     * 長尺音声を一定長のチャンクに分割してAPI文字起こしし、時刻をオフセットして結合する。
     * 単発の長尺リクエストは動画後半で精度低下・取りこぼしが起きやすいため、これを避ける。
     */
    private static function runApiChunked(
        OpenAIClient $client,
        string $audioAbs,
        ?string $langArg,
        string $engine,
        int $durationMs,
        int $chunkMs,
        ?callable $onProgress
    ): array {
        $tmpDir = sys_get_temp_dir();
        $chunks = (int) ceil($durationMs / $chunkMs);
        $allSegments = [];
        $language = $langArg ?? 'auto';

        for ($i = 0; $i < $chunks; $i++) {
            $startMs = $i * $chunkMs;
            $thisMs = min($chunkMs, $durationMs - $startMs);
            if ($thisMs <= 0) {
                break;
            }
            $slice = $tmpDir . '/subtitlesmith_tr_' . getmypid() . '_' . $i . '_' . bin2hex(random_bytes(3)) . '.mp3';
            try {
                Media::sliceAudio($audioAbs, $slice, $startMs, $thisMs);
                // 2チャンク目以降は検出済み言語を固定して自動判定のブレを防ぐ
                $useLang = $langArg;
                if ($useLang === null && $language !== 'auto' && $language !== '') {
                    $useLang = $language;
                }
                $r = $client->transcribe($slice, $useLang, $engine);
                if (($language === 'auto' || $language === '') && !empty($r['language'])) {
                    $language = (string) $r['language'];
                }
                foreach (($r['segments'] ?? []) as $seg) {
                    $allSegments[] = [
                        'start_ms' => (int) $seg['start_ms'] + $startMs,
                        'end_ms'   => (int) $seg['end_ms'] + $startMs,
                        'text'     => (string) $seg['text'],
                    ];
                }
            } catch (\Throwable $e) {
                // 1チャンクの失敗で全体を止めない（その区間だけ欠落させて継続）
                App::log('分割文字起こしのチャンク失敗(継続): #' . $i . ' ' . $e->getMessage(), 'worker');
            } finally {
                if (is_file($slice)) {
                    @unlink($slice);
                }
            }
            if ($onProgress !== null) {
                $onProgress(min(99.0, ($i + 1) / max(1, $chunks) * 100));
            }
        }

        if ($onProgress !== null) {
            $onProgress(100.0);
        }
        return ['language' => $language, 'segments' => $allSegments];
    }

    /** ローカル faster-whisper を Python 経由で実行 */
    private static function runLocal(string $audioAbs, string $lang, array $ocrSegments = [], ?callable $onProgress = null): array
    {
        // ローカル文字起こしは Python 3 必須。
        //  優先順: config 'python_whisper' → 'python3' → 'python'
        //  （共有サーバー等で 'python' が Python 2 を指す場合に備える）
        $python = (string) App::config('python_whisper', '');
        if ($python === '') {
            $python = (string) App::config('python3', '');
        }
        if ($python === '') {
            $python = (string) App::config('python', 'python3');
        }
        $script = APP_BASE_PATH . '/python/transcribe_local.py';
        if (!is_file($script)) {
            throw new \RuntimeException('ローカル文字起こしスクリプトがありません: ' . $script);
        }

        $model = (string) App::config('local_whisper_model', 'large-v3');
        $compute = (string) App::config('local_whisper_compute', 'float32');
        $device = (string) App::config('local_whisper_device', 'cpu');
        $threads = (int) App::config('local_whisper_cpu_threads', 0);
        $beam = (int) App::config('local_whisper_beam', 8);
        // セグメント並列のプロセス数（多コアサーバーの高速化。既定1=従来動作）
        $workers = (int) App::config('local_whisper_workers', 1);
        $batched = App::config('local_whisper_batched', false);
        $batchSize = (int) App::config('local_whisper_batch_size', 16);

        $args = [
            $script,
            '--audio', $audioAbs,
            '--lang', $lang,
            '--model', $model,
            '--compute-type', $compute,
            '--device', $device,
            '--beam-size', (string) max(1, $beam),
            // 直前テキスト条件付け: 既定オフ(長尺の後半崩れ防止)。設定で有効化可。
            '--condition-previous', App::config('local_whisper_condition_previous', false) ? '1' : '0',
        ];
        if ($threads > 0) {
            $args[] = '--cpu-threads';
            $args[] = (string) $threads;
        }
        if ($workers > 1) {
            $args[] = '--workers';
            $args[] = (string) $workers;
        }
        if ($batched) {
            $args[] = '--batched';
            $args[] = '--batch-size';
            $args[] = (string) max(1, $batchSize);
        }
        if (!empty($ocrSegments)) {
            $args[] = '--ocr-segments';
            $args[] = json_encode($ocrSegments, JSON_UNESCAPED_UNICODE);
            // バックグラウンド実行ではPATHが通らないため ffmpeg は絶対パスで渡す
            $args[] = '--ffmpeg';
            $args[] = (string) App::config('ffmpeg', 'ffmpeg');
        }

        if ($onProgress !== null) {
            [$code, $out, $err] = Proc::runWithProgress($python, $args, static function (string $line) use ($onProgress): void {
                if (strncmp($line, '@PROGRESS', 9) === 0) {
                    $pct = (float) trim(substr($line, 9));
                    $onProgress(max(0.0, min(100.0, $pct)));
                }
            });
        } else {
            [$code, $out, $err] = Proc::run($python, $args);
        }

        $data = json_decode(trim($out), true);
        if (!is_array($data)) {
            $hint = $err !== '' ? $err : substr($out, 0, 300);
            throw new \RuntimeException('ローカル文字起こしの出力を解析できません（faster-whisperの設定を確認）: ' . $hint);
        }
        if (empty($data['ok'])) {
            throw new \RuntimeException('ローカル文字起こし失敗: ' . ($data['error'] ?? 'unknown'));
        }

        $segments = [];
        foreach (($data['segments'] ?? []) as $s) {
            $text = trim((string) ($s['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $segments[] = [
                'start_ms' => (int) ($s['start_ms'] ?? 0),
                'end_ms'   => (int) ($s['end_ms'] ?? 0),
                'text'     => $text,
            ];
        }

        return [
            'language' => (string) ($data['language'] ?? $lang),
            'segments' => $segments,
        ];
    }
}
