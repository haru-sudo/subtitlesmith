<?php

declare(strict_types=1);

namespace App;

use App\Support\Proc;

/**
 * 焼き込み字幕のOCR取り込み（Phase 3）。
 * Pythonスクリプトで字幕を抽出し、Whisperの原文と時刻で対応付ける。
 */
final class OcrImport
{
    /**
     * 字幕の切り替わりを画像差分で検出し、各区間の代表フレームを
     * Claude Vision で読み取って日本語字幕テキストを得る。
     * @return array<int,array{start_ms:int,end_ms:int,text:string}>
     */
    public static function runOcr(string $videoAbs, string $lang = 'ja', ?callable $onProgress = null): array
    {
        $python = (string) App::config('python', 'python');
        $script = APP_BASE_PATH . '/python/subtitle_frames.py';
        if (!is_file($script)) {
            throw new \RuntimeException('字幕抽出スクリプトがありません: ' . $script);
        }

        $interval = (string) App::config('ocr_interval', '0.5');
        // 字幕は画面下部に出るため、解析帯を下部に絞る(図版・スライド本文を除外して精度向上)。
        $cropTop    = (string) App::config('ocr_crop_top', '0.5');
        $cropBottom = (string) App::config('ocr_crop_bottom', '1.0');
        $diff     = (string) App::config('ocr_diff_threshold', '0.28');
        $minDur   = (string) App::config('ocr_min_duration_ms', '1000');
        $ffmpeg   = (string) App::config('ffmpeg', 'ffmpeg');
        $outdir   = App::storagePath('subframes/' . pathinfo($videoAbs, PATHINFO_FILENAME));

        // 1) Pythonで字幕の切り替わりを検出し、代表フレーム(PNG)を書き出す
        $procArgs = [
            $script,
            '--video', $videoAbs,
            '--outdir', $outdir,
            '--interval', $interval,
            '--crop-top', $cropTop,
            '--crop-bottom', $cropBottom,
            '--diff-threshold', $diff,
            '--min-duration-ms', $minDur,
            '--ffmpeg', $ffmpeg,
        ];
        if ($onProgress !== null) {
            [$code, $out, $err] = Proc::runWithProgress($python, $procArgs, static function (string $line) use ($onProgress): void {
                if (strncmp($line, '@PROGRESS', 9) === 0) {
                    $onProgress(max(0.0, min(100.0, (float) trim(substr($line, 9)))));
                }
            });
        } else {
            [$code, $out, $err] = Proc::run($python, $procArgs);
        }

        $data = json_decode(trim($out), true);
        if (!is_array($data) || empty($data['ok'])) {
            $hint = $err !== '' ? $err : substr($out, 0, 300);
            throw new \RuntimeException('字幕フレーム抽出に失敗: ' . ($data['error'] ?? $hint));
        }

        $frames = $data['segments'] ?? [];
        if (!$frames) {
            return [];
        }

        // 2) 各代表フレームを Claude Vision で読み取る
        $segments = [];
        foreach ($frames as $f) {
            $img = (string) ($f['image'] ?? '');
            if ($img === '' || !is_file($img)) {
                continue;
            }
            $text = self::readSubtitleWithVision($img, $lang);
            $text = trim($text);
            if ($text !== '') {
                $segments[] = [
                    'start_ms' => (int) $f['start_ms'],
                    'end_ms'   => (int) $f['end_ms'],
                    'text'     => $text,
                ];
            }
            @unlink($img); // 読み終わったPNGは削除
        }
        @rmdir($outdir);

        return $segments;
    }

    /** 字幕帯のPNGをClaude Visionに送り、焼き込まれている字幕テキストだけを返す */
    private static function readSubtitleWithVision(string $pngPath, string $lang = 'ja'): string
    {
        $bytes = @file_get_contents($pngPath);
        if ($bytes === false) {
            return '';
        }
        $b64 = base64_encode($bytes);
        $langName = \App\TargetLangs::name($lang);

        $system = '画像は動画の焼き込み字幕の一部です。'
            . "画像に写っている{$langName}字幕の文字だけを、改行や句読点も含めてそのまま正確に書き起こしてください。"
            . '説明・前置き・引用符は一切付けず、字幕本文のみを出力します。'
            . '字幕が読み取れない場合は空文字を返します。';

        try {
            $client = new Clients\ClaudeClient();
            $resp = $client->message([
                // 字幕読み取りは難度が低いので既定で安価なHaikuを使う（configで上書き可）
                'model'      => (string) App::config('ocr_vision_model', 'claude-haiku-4-5'),
                'max_tokens' => 1000,
                'system'     => $system,
                'messages'   => [[
                    'role'    => 'user',
                    'content' => [
                        ['type' => 'image', 'source' => [
                            'type'       => 'base64',
                            'media_type' => 'image/png',
                            'data'       => $b64,
                        ]],
                        ['type' => 'text', 'text' => "この画像の{$langName}字幕を書き起こして。"],
                    ],
                ]],
            ]);
            $text = Clients\ClaudeClient::textFrom($resp);
            // 改行は半角スペースに（1セグメント1行運用）
            return trim(preg_replace('/\s*\n\s*/u', ' ', $text));
        } catch (\Throwable $e) {
            App::log('Vision字幕読み取りに失敗: ' . $e->getMessage(), 'learning');
            return '';
        }
    }

    /**
     * OCR字幕(ja)とWhisper原文を時刻の重なりで対応付ける。
     * @param array<int,array{start_ms:int,end_ms:int,text:string}> $ocr   日本語字幕
     * @param array<int,array{start_ms:int,end_ms:int,text:string}> $src   原語(文字起こし)
     * @return array<int,array{start_ms:int,end_ms:int,source_text:string,ja_text:string,confidence:float}>
     */
    public static function align(array $ocr, array $src): array
    {
        // start_ms をキーにした索引（OCR区間でWhisperを呼ぶ現行方式では
        // OCRとWhisperのタイムスタンプが一致するため、直接対応付けできる）
        $srcByStart = [];
        foreach ($src as $s) {
            $srcByStart[(int) $s['start_ms']] = trim((string) $s['text']);
        }

        $out = [];
        foreach ($ocr as $o) {
            $os = (int) $o['start_ms'];
            $oe = (int) $o['end_ms'];
            $odur = max(1, $oe - $os);

            // ① 完全一致（現行方式）: start_ms が一致すればそのテキストを使う
            $sourceText = '';
            $conf = 0.0;
            if (isset($srcByStart[$os]) && $srcByStart[$os] !== '') {
                $sourceText = $srcByStart[$os];
                $conf = 1.0;
            } else {
                // ② フォールバック（旧方式・時刻がズレている場合）: 時刻の重なりで対応付け
                $overlapTotal = 0;
                $pieces = [];
                foreach ($src as $s) {
                    $ss = (int) $s['start_ms'];
                    $se = (int) $s['end_ms'];
                    $ov = min($oe, $se) - max($os, $ss);
                    if ($ov <= 0) {
                        continue;
                    }
                    $overlapTotal += $ov;
                    $text = trim((string) $s['text']);
                    $sdur = max(1, $se - $ss);

                    // Whisperセグメントの一部しか重ならない場合は重なり比率でトリム
                    $ratio = $ov / $sdur;
                    if ($ratio < 0.85 && $text !== '') {
                        $words = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);
                        $count = count($words);
                        if ($count > 0) {
                            $take = max(1, (int) round($count * $ratio));
                            $overlapCenter = max($os, $ss) + $ov / 2;
                            $segCenter = ($ss + $se) / 2;
                            $words = ($overlapCenter <= $segCenter)
                                ? array_slice($words, 0, $take)
                                : array_slice($words, $count - $take);
                            $text = implode(' ', $words);
                        }
                    }
                    if ($text !== '') {
                        $pieces[] = ['start' => $ss, 'text' => $text];
                    }
                }
                usort($pieces, static fn($a, $b) => $a['start'] <=> $b['start']);
                $sourceText = trim(implode(' ', array_filter(array_column($pieces, 'text'))));
                $conf = min(1.0, $overlapTotal / $odur);
            }

            $out[] = [
                'start_ms'    => $os,
                'end_ms'      => $oe,
                'source_text' => $sourceText,
                'ja_text'     => trim((string) $o['text']),
                'confidence'  => round($conf, 3),
            ];
        }
        return $out;
    }
}
