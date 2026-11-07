<?php

declare(strict_types=1);

namespace App;

use App\Repo\Segments;
use App\Repo\VideoLangs;
use App\Repo\Videos;
use App\TargetLangs;

/**
 * cronワーカー本体。保留ジョブを順に処理する。
 * 多重起動はロックファイル(flock)で防止。
 */
final class Worker
{
    /**
     * 時間予算内で保留ジョブを処理する。
     * 既に実行中の別ワーカーがいれば何もしない。
     * 注: 1件の処理（書き出し等）が予算を超えても、その処理は最後まで完了させる。
     */
    public static function run(int $maxSeconds = 50): void
    {
        $lockPath = App::storagePath('worker.lock');
        $lock = fopen($lockPath, 'c');
        if ($lock === false) {
            App::log('ロックファイルを開けません: ' . $lockPath, 'worker');
            return;
        }
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            // 既に別ワーカーが実行中
            fclose($lock);
            return;
        }

        try {
            self::recoverStuck();
            $deadline = time() + $maxSeconds;
            while (time() < $deadline) {
                $job = Jobs::claimNext();
                if ($job === null) {
                    break;
                }
                self::handle($job);
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * 保留ジョブを処理するバックグラウンドプロセスを起動して即座に戻る。
     * アップロード/添付/書き出し要求の直後に呼ぶ（cron不要）。
     * 既にワーカーが動いていればロックで弾かれるので二重起動は安全。
     */
    public static function spawn(): void
    {
        $php = self::phpBinary();
        $runner = APP_BASE_PATH . '/runner.php';
        if (!is_file($runner)) {
            // フォールバック: 同一リクエスト内で短時間だけ処理（最低限の前進）
            self::run(20);
            return;
        }

        $cmd = escapeshellarg($php) . ' ' . escapeshellarg($runner);

        if (DIRECTORY_SEPARATOR === '\\') {
            // Windows: バックグラウンド起動
            @pclose(@popen('start /B ' . $cmd, 'r'));
            return;
        }
        // Unix: 完全にデタッチして起動（リクエスト終了後も継続）
        @exec($cmd . ' > /dev/null 2>&1 &');
    }

    /** 利用するPHP実行ファイルを推定 */
    private static function phpBinary(): string
    {
        $configured = (string) App::config('php_binary', '');
        if ($configured !== '') {
            return $configured;
        }
        if (defined('PHP_BINARY') && PHP_BINARY !== '' && PHP_SAPI === 'cli') {
            return PHP_BINARY;
        }
        // Web(php-fpm)からは PHP_BINARY が fpm を指すため、一般的なCLI名にフォールバック
        return DIRECTORY_SEPARATOR === '\\' ? 'php' : 'php';
    }

    /**
     * 異常終了などで running のまま放置されたジョブと、
     * 「ずっと書き出し中/処理中」になった動画を復旧する。
     */
    private static function recoverStuck(): void
    {
        $db = App::db();
        // 10分以上 running のジョブは pending に戻す（再試行させる）
        try {
            $db->run(
                "UPDATE jobs SET status = 'pending', started_at = NULL
                  WHERE status = 'running'
                    AND started_at IS NOT NULL
                    AND (strftime('%s','now') - strftime('%s', started_at)) > 600"
            );
        } catch (\Throwable $e) {
            // SQLite時刻計算が環境差で失敗しても致命ではない
        }
    }

    private static function handle(array $job): void
    {
        $stage = (string) $job['stage'];
        try {
            match ($stage) {
                'extract'    => self::extract($job),
                'transcribe' => self::transcribe($job),
                'translate'  => self::translate($job),
                'export'     => self::export($job),
                'trim'       => self::trim($job),
                'import_ocr' => self::importOcr($job),
                default      => throw new \RuntimeException('未知のステージ: ' . $stage),
            };
            Jobs::complete((int) $job['id']);
        } catch (\Throwable $e) {
            $willRetry = Jobs::fail($job, $e->getMessage());
            if (!$willRetry && !empty($job['video_id'])) {
                // 追加言語の翻訳だけが失敗した場合は、動画全体をエラーにしない。
                // （既にある言語の字幕は編集できるままにし、失敗はその言語のタブに出す）
                //
                // カット失敗も同様にエラーにしない。カットは書き出しの段階で失敗することが
                // ほとんどで元の動画・字幕はそのまま残るのに、error にすると再カットも書き出しも
                // 受け付けなくなり手詰まりになる（失敗の内容は jobs とログに残る）。
                if ($stage === 'trim' || ($stage === 'translate' && self::isExtraLangJob($job))) {
                    Videos::setStatus((int) $job['video_id'], 'ready');
                } else {
                    Videos::setStatus((int) $job['video_id'], 'error', $e->getMessage());
                }
            }
            App::log(
                "job#{$job['id']} stage={$stage} 失敗"
                . ($willRetry ? '(再試行予定)' : '(打ち切り)')
                . ': ' . $e->getMessage(),
                'worker'
            );
        }
    }

    // ---- 各ステージ ----

    /** 音声抽出・サムネ・長さ取得 */
    private static function extract(array $job): void
    {
        $v = self::video($job);
        $vid = (int) $v['id'];
        $training = (($v['kind'] ?? 'subtitle') === 'training_import');
        // ステージ1/3。学習用は次がOCR、通常は本ステージが音声抽出。
        Videos::setStage($vid, 1, 3, $training ? '準備中' : '音声抽出中');
        Videos::setStatus($vid, 'extracting');
        Videos::setProgress($vid, 10, '');

        $videoAbs = App::storagePath((string) $v['file_path']);
        if (!is_file($videoAbs)) {
            throw new \RuntimeException('動画ファイルがありません: ' . $videoAbs);
        }
        $base = pathinfo((string) $v['file_path'], PATHINFO_FILENAME);

        // 元動画をブラウザ即再生できる形（moov atomを先頭へ）に整える。
        // これが無いと大きな動画が編集画面で「0:00」のまま再生できない。
        Videos::setProgress($vid, 15, '再生最適化中');
        Media::faststartInPlace($videoAbs);

        $durationMs = Media::probeDurationMs($videoAbs);

        $audioRel = 'audio/' . $base . '.mp3';
        Media::extractAudio($videoAbs, App::storagePath($audioRel));

        $thumbRel = 'thumbs/' . $base . '.jpg';
        $atSec = $durationMs ? min(1.0, ($durationMs / 1000) / 2) : 1.0;
        Media::thumbnail($videoAbs, App::storagePath($thumbRel), $atSec);

        // 映像サイズは焼き込み時の字幕折り返し幅（＝編集画面の1行文字数チェック）に使う
        [$vw, $vh] = Media::probeDimensions($videoAbs);

        Videos::update($vid, [
            'duration_ms'    => $durationMs,
            'audio_path'     => $audioRel,
            'thumbnail_path' => $thumbRel,
            'video_width'    => $vw,
            'video_height'   => $vh,
        ]);
        Videos::setProgress($vid, 100, '');

        // 学習用(焼き込み済み完成動画)は OCR取り込みへ、通常動画は文字起こしへ
        if ($training) {
            Jobs::enqueue($vid, 'import_ocr');
        } else {
            Jobs::enqueue($vid, 'transcribe');
        }
    }

    /** Whisperで文字起こし → segments投入 */
    private static function transcribe(array $job): void
    {
        $v = self::video($job);
        $vid = (int) $v['id'];
        Videos::setStage($vid, 2, 3, 'Whisper文字起こし中');
        Videos::setStatus($vid, 'transcribing');

        $audioAbs = App::storagePath((string) $v['audio_path']);
        $lang = (string) $v['source_lang'];

        $result = Transcriber::run(
            $audioAbs, $lang, (string) ($v['transcribe_model'] ?? ''), [],
            static fn(float $pct) => Videos::setProgress($vid, (int) $pct, 'Whisper文字起こし中')
        );

        if (empty($result['segments'])) {
            throw new \RuntimeException('文字起こし結果が空でした。');
        }

        // 文字起こしの境界を文単位に整える（文の途中で切れる断片を結合・長文は分割）。
        // 文字起こしエンジン非依存。設定 resegment=false で無効化可能。
        if (App::config('resegment', true)) {
            $rlang = ($lang !== 'auto') ? $lang : (string) ($result['language'] ?? '');
            $result['segments'] = Segmenter::resegment($result['segments'], $rlang);
        }

        Segments::replaceForVideo($vid, $result['segments']);

        // 言語自動判定だった場合は検出言語で更新
        $update = [];
        if ($lang === 'auto' && !empty($result['language']) && $result['language'] !== 'auto') {
            $update['source_lang'] = $result['language'];
        }
        if ($update) {
            Videos::update($vid, $update);
        }

        // この動画が持つ言語ごとに翻訳ジョブを積む（主言語が先）
        $langs = VideoLangs::codes($vid);
        if (!$langs) {
            VideoLangs::add($vid, VideoLangs::primaryLang($vid));
            $langs = VideoLangs::codes($vid);
        }
        foreach ($langs as $tl) {
            Jobs::enqueue($vid, 'translate', ['target_lang' => $tl]);
        }
    }

    /**
     * ローカル/クラウド翻訳（1言語分）。
     * 多言語の動画では言語ごとにこのジョブが積まれ、全部終わったら ready にする。
     */
    private static function translate(array $job): void
    {
        $v = self::video($job);
        $vid = (int) $v['id'];
        $target = TargetLangs::normalize(Jobs::payload($job)['target_lang'] ?? ($v['target_lang'] ?? null));
        $langs = VideoLangs::codes($vid);
        $pos = array_search($target, $langs, true);
        $total = max(1, count($langs));
        $label = TargetLangs::label($target) . 'へ翻訳中'
            . ($total > 1 ? '（' . (($pos === false ? 0 : (int) $pos) + 1) . '/' . $total . '言語）' : '');

        Videos::setStage($vid, 3, 3, $label);
        Videos::setStatus($vid, 'translating');
        VideoLangs::add($vid, $target, 'translating');
        VideoLangs::setStatus($vid, $target, 'translating');

        try {
            Translate::translateVideo($vid, $target);
        } catch (\Throwable $e) {
            VideoLangs::setStatus($vid, $target, 'error', $e->getMessage());
            throw $e;
        }

        // 翻訳結果が全空の場合はエラーとして扱う（ローカル翻訳の静かな失敗を検出）
        $segs = Segments::forVideo($vid, $target);
        $nonEmpty = array_filter($segs, static fn($s) => trim((string) ($s['edited_translation'] ?? '')) !== '');
        if ($segs && !$nonEmpty) {
            $msg = '翻訳結果が空でした。ローカル翻訳の場合は依存のセットアップを確認してください（pip install ctranslate2 transformers sentencepiece）。';
            VideoLangs::setStatus($vid, $target, 'error', $msg);
            throw new \RuntimeException($msg);
        }

        VideoLangs::setStatus($vid, $target, 'ready');

        // 他の言語の翻訳がまだ残っていれば、この動画はまだ「翻訳中」のまま
        if (self::hasPendingTranslateJobs($vid, (int) $job['id'])) {
            return;
        }

        Videos::setStatus($vid, 'ready');

        // 文字起こし内容から「内容（説明文）」をAIで自動生成（auto_describe=1 かつ未入力のとき）
        self::maybeDescribe($vid);
    }

    /**
     * 主言語ではない言語の翻訳ジョブか。
     * 主言語がまだ翻訳されていない段階（初回の生成）は、失敗＝動画全体の失敗として扱う。
     */
    private static function isExtraLangJob(array $job): bool
    {
        $vid = (int) $job['video_id'];
        $target = (string) (Jobs::payload($job)['target_lang'] ?? '');
        if ($target === '' || $vid <= 0) {
            return false;
        }
        $primary = VideoLangs::primaryLang($vid);
        return $target !== $primary && Segments::countTranslated($vid, $primary) > 0;
    }

    /** この動画に、まだ実行されていない翻訳ジョブが残っているか（自分自身は除く） */
    private static function hasPendingTranslateJobs(int $videoId, int $exceptJobId): bool
    {
        return (int) App::db()->fetchValue(
            "SELECT COUNT(*) FROM jobs
              WHERE video_id = :v AND stage = 'translate'
                AND status IN ('pending','running') AND id <> :j",
            [':v' => $videoId, ':j' => $exceptJobId]
        ) > 0;
    }

    /** 字幕焼き込み（Task #7 で実装） */
    private static function export(array $job): void
    {
        Exporter::run($job);
    }

    /** 動画の区間カット（映像・音声を切り、字幕も繰り上げる） */
    private static function trim(array $job): void
    {
        VideoEditor::run($job);
    }

    /** 焼き込み字幕のOCR取り込み: OCR(訳文)＋Whisper(原文)を時刻対応付けし、学習データ化 */
    private static function importOcr(array $job): void
    {
        $v = self::video($job);
        $vid = (int) $v['id'];
        // 焼き込まれている字幕の言語＝この動画の主言語（既存の学習用動画は日本語）
        $targetLang = VideoLangs::primaryLang($vid);
        VideoLangs::add($vid, $targetLang, 'translating');
        Videos::setStatus($vid, 'transcribing');

        $videoAbs = App::storagePath((string) $v['file_path']);
        if (!is_file($videoAbs)) {
            throw new \RuntimeException('動画ファイルがありません: ' . $videoAbs);
        }

        // ステージ1/3: 焼き込み字幕（訳文）をOCR抽出
        Videos::setStage($vid, 1, 3, 'OCR中');
        $ocrSegs = OcrImport::runOcr($videoAbs, $targetLang,
            static fn(float $pct) => Videos::setProgress($vid, (int) $pct, 'OCR中'));
        if (!$ocrSegs) {
            throw new \RuntimeException('字幕を検出できませんでした（字幕領域や言語設定を確認）。');
        }

        // ステージ2/3: OCRセグメントの時刻境界でWhisper文字起こし（原語）
        Videos::setStage($vid, 2, 3, 'Whisper文字起こし中');
        $audioAbs = App::storagePath((string) $v['audio_path']);
        $lang = (string) $v['source_lang'];
        $ocrTimestamps = array_map(static fn($s) => [
            'start_ms' => (int) $s['start_ms'],
            'end_ms'   => (int) $s['end_ms'],
        ], $ocrSegs);
        $tr = Transcriber::run(
            $audioAbs, $lang, (string) ($v['transcribe_model'] ?? ''), $ocrTimestamps,
            static fn(float $pct) => Videos::setProgress($vid, (int) $pct, 'Whisper文字起こし中')
        );
        $srcSegs = $tr['segments'] ?? [];
        if ($lang === 'auto' && !empty($tr['language']) && $tr['language'] !== 'auto') {
            $lang = $tr['language'];
            Videos::update($vid, ['source_lang' => $lang]);
        }

        // ステージ3/3: 対応付け・セグメント化・学習データ生成
        Videos::setStage($vid, 3, 3, '学習データ生成中');
        Videos::setProgress($vid, 10, '対応付け');

        // 3) 時刻の対応付け（OCRセグメント境界でWhisperを呼んでいるので一致）
        $pairs = OcrImport::align($ocrSegs, $srcSegs);

        // 4) セグメント化（低信頼は未承認・confidenceで可視化）
        $rows = [];
        foreach ($pairs as $p) {
            $rows[] = [
                'start_ms'           => $p['start_ms'],
                'end_ms'             => $p['end_ms'],
                'source_text'        => $p['source_text'],
                'edited_translation' => $p['ja_text'],
                'confidence'         => $p['confidence'],
                'is_approved'        => 0,
            ];
        }
        Segments::replaceForVideoDetailed($vid, $rows, $targetLang);
        Videos::setProgress($vid, 50, '学習メモリへ反映');

        // 5) 信頼度の高い対のみ翻訳メモリ(TM)へ蓄積（学習）
        $tmSegs = [];
        foreach ($pairs as $p) {
            if ($p['confidence'] >= 0.5 && $p['source_text'] !== '' && $p['ja_text'] !== '') {
                $tmSegs[] = [
                    'source_text'        => $p['source_text'],
                    'edited_translation' => $p['ja_text'],
                    'video_id'           => $vid,
                ];
            }
        }
        if ($tmSegs) {
            try {
                Learning::recordSegments($tmSegs, $lang, 'import_ocr', 0.6, $targetLang);
            } catch (\Throwable $e) {
                App::log('OCR取り込みのTM記録に失敗: ' . $e->getMessage(), 'learning');
            }
        }
        Videos::setProgress($vid, 75, '用語集の生成');

        // 6) 用語集をAIで自動抽出・自動登録（アップロード時にチェックした動画のみ。APIコスト発生）
        if ((int) ($v['build_glossary'] ?? 0) === 1) {
            try {
                $n = GlossaryAI::extractAndRegister($pairs, $lang, $vid, $targetLang);
                if ($n > 0) {
                    App::log("用語集にAIで{$n}件を自動登録: video #{$v['id']}", 'learning');
                }
            } catch (\Throwable $e) {
                App::log('用語集のAI自動登録に失敗: ' . $e->getMessage(), 'learning');
            }
        }

        Videos::setProgress($vid, 100, '');
        VideoLangs::setStatus($vid, $targetLang, 'ready');
        Videos::setStatus($vid, 'ready');

        // 文字起こし内容から「内容（説明文）」をAIで自動生成
        self::maybeDescribe($vid);
    }

    /**
     * 文字起こし内容から動画の「内容（説明文）」をAIで自動生成する。
     * 失敗してもパイプラインは止めない（説明は空のままにする）。
     */
    private static function maybeDescribe(int $videoId): void
    {
        try {
            if (DescribeAI::generateForVideo($videoId)) {
                App::log("内容をAIで自動生成: video #{$videoId}", 'learning');
            }
        } catch (\Throwable $e) {
            App::log('内容のAI自動生成に失敗: ' . $e->getMessage(), 'learning');
        }
    }

    private static function video(array $job): array
    {
        $v = Videos::find((int) $job['video_id']);
        if ($v === null) {
            throw new \RuntimeException('動画レコードがありません: video_id=' . $job['video_id']);
        }
        return $v;
    }
}
