<?php

declare(strict_types=1);

namespace App;

/**
 * ffmpeg / ffprobe のラッパ。音声抽出・サムネ生成・字幕焼き込み・長さ取得。
 */
final class Media
{
    private static function ffmpeg(): string
    {
        return (string) App::config('ffmpeg', 'ffmpeg');
    }

    private static function ffprobe(): string
    {
        return (string) App::config('ffprobe', 'ffprobe');
    }

    /**
     * コマンドを実行し [exitCode, stdout, stderr] を返す。
     * @param array<int,string> $args
     */
    private static function run(string $bin, array $args, ?string $cwd = null): array
    {
        return \App\Support\Proc::run($bin, $args, $cwd);
    }

    /** 動画の解像度 [width, height]。取得できなければ [1280, 720]。 */
    public static function probeDimensions(string $videoPath): array
    {
        [$code, $out] = self::run(self::ffprobe(), [
            '-v', 'quiet',
            '-print_format', 'json',
            '-select_streams', 'v:0',
            '-show_streams',
            $videoPath,
        ]);
        if ($code === 0) {
            $data = json_decode($out, true);
            $s = $data['streams'][0] ?? null;
            if ($s && !empty($s['width']) && !empty($s['height'])) {
                return [(int) $s['width'], (int) $s['height']];
            }
        }
        return [1280, 720];
    }

    /** 動画の長さ(ms)。取得できなければ null。 */
    public static function probeDurationMs(string $videoPath): ?int
    {
        [$code, $out] = self::run(self::ffprobe(), [
            '-v', 'quiet',
            '-print_format', 'json',
            '-show_format',
            $videoPath,
        ]);
        if ($code !== 0) {
            return null;
        }
        $data = json_decode($out, true);
        $dur = $data['format']['duration'] ?? null;
        return $dur !== null ? (int) round(((float) $dur) * 1000) : null;
    }

    /** 音声を 16kHz mono mp3 で抽出 */
    public static function extractAudio(string $videoPath, string $outAudioPath): void
    {
        [$code, , $err] = self::run(self::ffmpeg(), [
            '-y',
            '-i', $videoPath,
            '-vn',
            '-ac', '1',
            '-ar', '16000',
            '-b:a', '64k',
            $outAudioPath,
        ]);
        if ($code !== 0 || !is_file($outAudioPath)) {
            throw new \RuntimeException('音声抽出に失敗しました: ' . self::tail($err));
        }
    }

    /**
     * 元動画をブラウザ即再生できる形（moov atom を先頭に置く faststart）へ変換し、その場で置き換える。
     * 再エンコードせずストリームコピーするので高速・無劣化。mp4/mov系のみ意味があり、
     * 失敗しても元ファイルは壊さない（変換できなければ何もしない）。
     *
     * ブラウザの <video> は moov が末尾にあるMP4だと全体DLまで再生できないため、これが無いと
     * 大きな動画が「0:00で止まる」症状になる。
     */
    public static function faststartInPlace(string $videoPath): void
    {
        if (!is_file($videoPath)) {
            return;
        }
        $ext = strtolower(pathinfo($videoPath, PATHINFO_EXTENSION));
        $tmp = $videoPath . '.faststart.mp4';

        // 映像は無劣化コピー、音声はブラウザ標準対応の AAC に変換する。
        // - -map 0:v? / 0:a? で映像・音声の全トラックを確実に拾う（音声取りこぼし防止）
        // - 音声を AAC 化することで、元動画の音声が AC-3/DTS などブラウザ非対応コーデックでも鳴るようにする
        // - 映像はコピーなので高速・無劣化（1.98GBでも数十秒程度）
        // - +faststart で moov を先頭へ（ブラウザ即再生）
        $args = [
            '-y',
            '-i', $videoPath,
            '-map', '0:v?',
            '-map', '0:a?',
            '-c:v', 'copy',
            '-c:a', 'aac',
            '-b:a', '192k',
            '-movflags', '+faststart',
            $tmp,
        ];

        [$code, , $err] = self::run(self::ffmpeg(), $args);
        if ($code !== 0 || !is_file($tmp) || filesize($tmp) === 0) {
            // 失敗時は元動画をそのまま使う（壊さない）
            if (is_file($tmp)) {
                @unlink($tmp);
            }
            App::log('faststart skipped: ' . self::tail($err), 'worker');
            return;
        }

        // 成功 → 元ファイルを置き換える
        if (!@rename($tmp, $videoPath)) {
            @unlink($tmp);
            App::log('faststart rename failed: ' . $videoPath, 'worker');
        }
    }

    /**
     * 音声の一部区間を 16kHz mono mp3 として切り出す（長尺APIの分割文字起こし用）。
     * @param int $startMs 開始ミリ秒
     * @param int $durMs   長さミリ秒
     */
    public static function sliceAudio(string $inAudioPath, string $outAudioPath, int $startMs, int $durMs): void
    {
        [$code, , $err] = self::run(self::ffmpeg(), [
            '-y',
            '-ss', sprintf('%.3f', max(0, $startMs) / 1000),
            '-t', sprintf('%.3f', max(0, $durMs) / 1000),
            '-i', $inAudioPath,
            '-vn',
            '-ac', '1',
            '-ar', '16000',
            '-b:a', '64k',
            $outAudioPath,
        ]);
        if ($code !== 0 || !is_file($outAudioPath)) {
            throw new \RuntimeException('音声の区間切り出しに失敗しました: ' . self::tail($err));
        }
    }

    /** サムネイル(jpg)を生成。atSec 秒の1フレーム。 */
    public static function thumbnail(string $videoPath, string $outImagePath, float $atSec = 1.0): void
    {
        $try = function (float $ss) use ($videoPath, $outImagePath): int {
            [$code] = self::run(self::ffmpeg(), [
                '-y',
                '-ss', (string) $ss,
                '-i', $videoPath,
                '-frames:v', '1',
                '-vf', 'scale=480:-2',
                $outImagePath,
            ]);
            return $code;
        };

        $code = $try($atSec);
        if (($code !== 0 || !is_file($outImagePath)) && $atSec > 0) {
            // 短い動画向けに先頭フレームで再試行
            $code = $try(0.0);
        }
        if ($code !== 0 || !is_file($outImagePath)) {
            throw new \RuntimeException('サムネイル生成に失敗しました。');
        }
    }

    /**
     * 字幕(ASS)を焼き込んだMP4を生成。
     * Windowsのドライブコロン等を避けるため、字幕ディレクトリをcwdにしてbasename参照する。
     */
    public static function burnSubtitles(string $videoPath, string $assPath, string $outPath): void
    {
        $subDir = dirname($assPath);
        $subName = basename($assPath);

        // subtitlesフィルタ内の特殊文字をエスケープ
        $escaped = str_replace(['\\', "'"], ['\\\\', "\\'"], $subName);
        $vf = "ass='" . $escaped . "'";

        [$code, , $err] = self::run(self::ffmpeg(), [
            '-y',
            '-i', $videoPath,
            '-vf', $vf,
            '-c:v', 'libx264',
            '-preset', 'veryfast',
            '-crf', '20',
            '-pix_fmt', 'yuv420p',
            '-c:a', 'aac',
            '-b:a', '128k',
            '-movflags', '+faststart',
            $outPath,
        ], $subDir);

        if ($code !== 0 || !is_file($outPath)) {
            throw new \RuntimeException('字幕焼き込みに失敗しました: ' . self::tail($err));
        }
    }

    /** 動画に音声トラックがあるか（区間カットのフィルタ組み立てに使う）。 */
    public static function hasAudioStream(string $videoPath): bool
    {
        [$code, $out] = self::run(self::ffprobe(), [
            '-v', 'quiet',
            '-print_format', 'json',
            '-select_streams', 'a:0',
            '-show_streams',
            $videoPath,
        ]);
        if ($code !== 0) {
            return false;
        }
        $data = json_decode($out, true);
        return !empty($data['streams'][0]);
    }

    /**
     * 動画から区間 [cutStartMs, cutEndMs) を取り除いた新しいMP4を生成する。
     * カット前の部分（先頭）とカット後の部分（末尾）を結合するので、
     * その部分より後ろの映像・音声は自動的に繰り上がる。
     *
     * フレーム精度で任意位置を切るため再エンコードする（焼き込みと同等の負荷）。
     * 先頭のみ／末尾のみ残るケース（動画の端をカット）にも対応する。
     *
     * @param int|null $durationMs 総尺（ミリ秒）。渡すと末尾判定に使う。null なら ffprobe。
     */
    public static function cutOutRange(string $videoPath, string $outPath, int $cutStartMs, int $cutEndMs, ?int $durationMs = null): void
    {
        $a = max(0, $cutStartMs) / 1000.0;
        $b = max(0, $cutEndMs) / 1000.0;
        if ($b <= $a) {
            throw new \RuntimeException('カット範囲が不正です。');
        }
        $durMs = $durationMs ?? self::probeDurationMs($videoPath);
        $durSec = $durMs !== null ? $durMs / 1000.0 : null;

        $hasHead = $a > 0.02;
        // 総尺不明のときは安全側で「末尾は残る」とみなす（trim=start=b が空でも concat 前提を崩さない）
        $hasTail = ($durSec === null) ? true : ($b < $durSec - 0.02);
        if (!$hasHead && !$hasTail) {
            throw new \RuntimeException('動画全体を削除することはできません。');
        }

        $hasAudio = self::hasAudioStream($videoPath);
        $as = sprintf('%.3f', $a);
        $bs = sprintf('%.3f', $b);

        // filter_complex を組み立て、最終ラベルは [outv]（音声ありなら [outa] も）に統一する
        if ($hasHead && $hasTail) {
            $fv = "[0:v]trim=start=0:end={$as},setpts=PTS-STARTPTS[v0];"
                . "[0:v]trim=start={$bs},setpts=PTS-STARTPTS[v1];";
            if ($hasAudio) {
                $fa = "[0:a]atrim=start=0:end={$as},asetpts=PTS-STARTPTS[a0];"
                    . "[0:a]atrim=start={$bs},asetpts=PTS-STARTPTS[a1];";
                $filter = $fv . $fa . "[v0][a0][v1][a1]concat=n=2:v=1:a=1[outv][outa]";
            } else {
                $filter = $fv . "[v0][v1]concat=n=2:v=1:a=0[outv]";
            }
        } elseif ($hasHead) {
            // 末尾をカット（先頭だけ残す）
            $filter = "[0:v]trim=start=0:end={$as},setpts=PTS-STARTPTS[outv]";
            if ($hasAudio) {
                $filter .= ";[0:a]atrim=start=0:end={$as},asetpts=PTS-STARTPTS[outa]";
            }
        } else {
            // 先頭をカット（末尾だけ残す）
            $filter = "[0:v]trim=start={$bs},setpts=PTS-STARTPTS[outv]";
            if ($hasAudio) {
                $filter .= ";[0:a]atrim=start={$bs},asetpts=PTS-STARTPTS[outa]";
            }
        }

        $args = [
            '-y',
            '-i', $videoPath,
            '-filter_complex', $filter,
            '-map', '[outv]',
        ];
        if ($hasAudio) {
            $args[] = '-map';
            $args[] = '[outa]';
        }
        array_push(
            $args,
            '-c:v', 'libx264',
            '-preset', 'veryfast',
            '-crf', '20',
            '-pix_fmt', 'yuv420p'
        );
        if ($hasAudio) {
            array_push($args, '-c:a', 'aac', '-b:a', '128k');
        }
        array_push($args, '-movflags', '+faststart', $outPath);

        [$code, , $err] = self::run(self::ffmpeg(), $args);
        if ($code !== 0 || !is_file($outPath) || filesize($outPath) === 0) {
            if (is_file($outPath)) {
                @unlink($outPath);
            }
            throw new \RuntimeException('動画のカットに失敗しました: ' . self::tail($err));
        }
    }

    private static function tail(string $s, int $len = 400): string
    {
        $s = trim($s);
        return strlen($s) > $len ? '...' . substr($s, -$len) : $s;
    }
}
