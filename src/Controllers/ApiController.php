<?php

declare(strict_types=1);

namespace App\Controllers;

use App\App;
use App\Http\Request;
use App\Http\Response;
use App\Learning;
use App\Repo\Segments;
use App\Repo\Tm;
use App\Repo\VideoLangs;
use App\Repo\Videos;
use App\TargetLangs;

/**
 * JSON API。ダッシュボードのポーリングと編集画面のCRUDを提供。
 */
final class ApiController
{
    /** 一覧（ポーリング用の軽量データ） */
    public function listVideos(Request $request): void
    {
        $rows = Videos::allForListing();
        $out = array_map(static fn(array $v) => self::publicVideo($v), $rows);
        Response::json(['videos' => $out]);
    }

    /**
     * 保留中の処理を再開するためバックグラウンドワーカーを起動（冪等）。
     * 処理中状態の動画があるのにワーカーが止まっている場合の自動復旧に使う。
     * ロックで二重起動は防がれるので何度呼んでも安全。
     */
    public function spawnWorker(Request $request): void
    {
        $pending = (int) App::db()->fetchValue(
            "SELECT COUNT(*) FROM jobs WHERE status IN ('pending','running')"
        );
        if ($pending > 0) {
            \App\Worker::spawn();
        }
        Response::json(['ok' => true, 'pending' => $pending]);
    }

    public function showVideo(Request $request, array $params): void
    {
        $v = Videos::find((int) $params['id']);
        if (!$v) {
            Response::json(['error' => 'not_found'], 404);
            return;
        }
        // 編集画面のポーリングで言語タブを更新できるよう、言語の一覧も返す
        Response::json(self::publicVideo($v) + ['langs' => self::publicLangs((int) $v['id'])]);
    }

    /** @return array<int,array{lang:string,label:string,status:string,has_export:bool,export_stale:bool,primary:bool}> */
    private static function publicLangs(int $videoId): array
    {
        $primary = VideoLangs::primaryLang($videoId);
        $out = [];
        foreach (VideoLangs::forVideo($videoId) as $l) {
            $lang = (string) $l['target_lang'];
            $out[] = [
                'lang'         => $lang,
                'label'        => TargetLangs::label($lang),
                'tag'          => TargetLangs::vttTag($lang),
                'status'       => (string) $l['status'],
                'has_export'   => !empty($l['export_path']),
                'export_stale' => VideoLangs::isExportStale($videoId, $lang),
                'primary'      => ($lang === $primary),
            ];
        }
        return $out;
    }

    public function deleteVideo(Request $request, array $params): void
    {
        $v = Videos::find((int) $params['id']);
        if (!$v) {
            Response::json(['error' => 'not_found'], 404);
            return;
        }
        // 関連ファイルを削除
        foreach (['file_path', 'audio_path', 'thumbnail_path', 'export_path'] as $key) {
            if (!empty($v[$key])) {
                @unlink(App::storagePath($v[$key]));
            }
        }
        // 言語ごとの焼き込みMP4も消す（DBの行は外部キーの連鎖削除で消える）
        foreach (VideoLangs::forVideo((int) $v['id']) as $vl) {
            if (!empty($vl['export_path'])) {
                @unlink(App::storagePath((string) $vl['export_path']));
            }
        }
        // 学習用動画なら、その動画が生成したTM・用語集・候補も連動削除する
        $removed = Videos::deleteWithLearning((int) $v['id']);
        Response::json(['ok' => true, 'removed' => $removed]);
    }

    /** セグメント一覧（?lang= で言語を指定。未指定は主言語） */
    public function segments(Request $request, array $params): void
    {
        $videoId = (int) $params['id'];
        if (!Videos::find($videoId)) {
            Response::json(['error' => 'not_found'], 404);
            return;
        }
        $lang = VideoLangs::resolve($videoId, (string) $request->input('lang', ''));
        $rows = array_map([self::class, 'publicSegment'], Segments::forVideo($videoId, $lang));
        Response::json(['lang' => $lang, 'segments' => $rows]);
    }

    /**
     * セグメント更新（編集訳・原文・タイミング・承認フラグ）。
     * 訳文・確認済みは lang で指定した言語のものを更新する（原文と時刻は全言語共通）。
     */
    public function updateSegment(Request $request, array $params): void
    {
        $videoId = (int) $params['id'];
        $sid = (int) $params['sid'];
        $targetLang = VideoLangs::resolve($videoId, (string) $request->input('lang', ''));
        $seg = self::ownedSegment($videoId, $sid, $targetLang);
        if ($seg === null) {
            Response::json(['error' => 'not_found'], 404);
            return;
        }

        $data = [];   // segments（言語共通）
        $tr = [];     // segment_translations（この言語）
        if ($request->input('edited_translation') !== null) {
            $tr['edited_translation'] = (string) $request->input('edited_translation');
        }
        if ($request->input('source_text') !== null) {
            $data['source_text'] = (string) $request->input('source_text');
        }
        if ($request->input('start_ms') !== null) {
            $data['start_ms'] = max(0, (int) $request->input('start_ms'));
        }
        if ($request->input('end_ms') !== null) {
            $data['end_ms'] = max(0, (int) $request->input('end_ms'));
        }
        if ($request->input('is_approved') !== null) {
            $approved = (int) (bool) $request->input('is_approved');
            $tr['is_approved'] = $approved;
            $wasApprovedBefore = !empty($seg['is_approved']);
            // 承認者(approved_by)は「状態が変わった時」だけ更新する。
            // 既に確認済みの行を編集して is_approved=1 を再送しても、元の承認者を上書きしない。
            if ($approved && !$wasApprovedBefore) {
                $tr['approved_by'] = \App\Auth::name() ?? '';
            } elseif (!$approved) {
                $tr['approved_by'] = null;
            }
        }

        $start = $data['start_ms'] ?? (int) $seg['start_ms'];
        $end = $data['end_ms'] ?? (int) $seg['end_ms'];
        if ($end < $start) {
            Response::json(['error' => 'invalid_timing', 'message' => '終了時刻が開始より前です。'], 422);
            return;
        }

        if ($data || $tr) {
            if ($data) {
                Segments::update($sid, $data);
            }
            if ($tr) {
                Segments::saveTranslation($sid, $targetLang, $tr);
            }
            $data += $tr;   // 以降の判定は「今回変更した内容」としてまとめて見る

            $video = Videos::find($videoId);
            $isTraining = (($video['kind'] ?? '') === 'training_import');

            // 原文を変更したら、旧原文でこの動画が作ったTMは古くなるので取り消す
            $oldSource = trim((string) $seg['source_text']);
            $newSource = trim((string) ($data['source_text'] ?? $seg['source_text']));
            $sourceChanged = ($oldSource !== '' && $oldSource !== $newSource);

            // このセグメントが「確定訳」としてTMに入る対象か。
            //  - 通常動画: 確認済みのもの（今回確認済みにした／既に確認済みのものを編集した、いずれも対象）
            //  - 学習用動画: 確認済みに関わらず保存時点の訳を反映
            $wasApproved = !empty($seg['is_approved']);
            $effectiveApproved = array_key_exists('is_approved', $data)
                ? !empty($data['is_approved'])
                : $wasApproved;
            $shouldRecord = $isTraining || $effectiveApproved;

            try {
                if ($sourceChanged) {
                    Tm::deleteBySourceVideo($oldSource, $videoId, $targetLang);
                }

                if ($shouldRecord) {
                    // 現在の訳が空（消去された）なら、この原文のTMを取り消す。あれば登録/更新。
                    $cur = Segments::find($sid, $targetLang) ?? $seg;
                    $curTarget = trim((string) ($cur['edited_translation'] ?? ''));
                    if ($curTarget === '') {
                        Tm::deleteBySourceVideo($newSource, $videoId, $targetLang);
                    } elseif ($isTraining && !$effectiveApproved) {
                        // 学習用動画で未確認: 品質は承認より低めに
                        Learning::recordSegmentById($videoId, $sid, 'human_edit', 0.85, $targetLang);
                    } else {
                        // 確認済みの確定訳（通常動画・学習用動画とも高品質）
                        Learning::recordApprovedSegment($videoId, $sid, $targetLang);
                    }
                } elseif (array_key_exists('is_approved', $data) && empty($data['is_approved']) && $wasApproved) {
                    // 確認済みを解除した → この動画が作ったTM寄与を取り消す
                    Tm::deleteBySourceVideo($newSource, $videoId, $targetLang);
                }
            } catch (\Throwable $e) {
                App::log('TM反映に失敗: ' . $e->getMessage(), 'learning');
            }
        }

        Response::json(['ok' => true, 'segment' => self::publicSegment(Segments::find($sid, $targetLang) ?? $seg)]);
    }

    /** セグメント分割（at_ms で2つに） */
    public function splitSegment(Request $request, array $params): void
    {
        $videoId = (int) $params['id'];
        $sid = (int) $params['sid'];
        $seg = self::ownedSegment($videoId, $sid);
        if ($seg === null) {
            Response::json(['error' => 'not_found'], 404);
            return;
        }
        $at = (int) $request->input('at_ms', 0);
        $start = (int) $seg['start_ms'];
        $end = (int) $seg['end_ms'];
        if ($at <= $start || $at >= $end) {
            Response::json(['error' => 'invalid_split', 'message' => '分割位置が範囲外です。'], 422);
            return;
        }

        $newId = App::db()->insert('segments', [
            'video_id'    => $videoId,
            'idx'         => (int) $seg['idx'],
            'start_ms'    => $at,
            'end_ms'      => $end,
            'source_text' => (string) $seg['source_text'],
        ]);
        // 訳文は全言語ぶん複製する（分割は言語共通の操作なので、どの言語で見ても同じ形になる）
        foreach (self::allTranslations($sid) as $lang => $t) {
            Segments::saveTranslation($newId, $lang, $t);
        }
        Segments::update($sid, ['end_ms' => $at]);

        Response::json(['ok' => true]);
    }

    /**
     * セグメントが持つ全言語の訳文を取り出す。
     * 分割・削除の取り消しで「他の言語の訳も失わない」ために使う。
     * @return array<string,array{machine_translation:?string,edited_translation:?string,is_approved:int,approved_by:?string}>
     */
    private static function allTranslations(int $segmentId): array
    {
        $out = [];
        $rows = App::db()->fetchAll(
            'SELECT target_lang, machine_translation, edited_translation, is_approved, approved_by
               FROM segment_translations WHERE segment_id = :s',
            [':s' => $segmentId]
        );
        foreach ($rows as $r) {
            $out[(string) $r['target_lang']] = [
                'machine_translation' => $r['machine_translation'],
                'edited_translation'  => $r['edited_translation'],
                'is_approved'         => (int) $r['is_approved'],
                'approved_by'         => $r['approved_by'],
            ];
        }
        return $out;
    }

    /**
     * セグメント削除。時刻・原文が消えるので、全言語の訳文もまとめて消える。
     * 編集画面は確認ダイアログを出さずに削除し、⌘Z（元に戻す）で createSegment を呼んで戻す。
     * そのため、消す直前の内容（全言語の訳文つき）を応答に含めて復元できるようにしておく。
     */
    public function deleteSegment(Request $request, array $params): void
    {
        $videoId = (int) $params['id'];
        $sid = (int) $params['sid'];
        $seg = self::ownedSegment($videoId, $sid);
        if ($seg === null) {
            Response::json(['error' => 'not_found'], 404);
            return;
        }
        $translations = self::allTranslations($sid);

        // 学習用動画は、削除した原文がこの動画で作ったTMも取り消す（言語ごと）
        $video = Videos::find($videoId);
        if (($video['kind'] ?? '') === 'training_import') {
            foreach (array_keys($translations) as $lang) {
                try {
                    Tm::deleteBySourceVideo((string) $seg['source_text'], $videoId, $lang);
                } catch (\Throwable $e) {
                    App::log('学習用動画の削除に伴うTM取り消しに失敗: ' . $e->getMessage(), 'learning');
                }
            }
        }
        App::db()->run('DELETE FROM segments WHERE id = :id', [':id' => $sid]);
        Response::json([
            'ok'      => true,
            'segment' => self::publicSegment($seg),
            'translations' => $translations,
        ]);
    }

    /**
     * セグメントの新規作成（編集画面の「削除を元に戻す」= ⌘Z から呼ばれる）。
     * 削除した行と同じ内容で作り直す。IDはDBが振り直すので削除前とは変わる。
     * 確認済みだった行は、訳を確定訳として翻訳メモリへ戻す。
     */
    public function createSegment(Request $request, array $params): void
    {
        $videoId = (int) $params['id'];
        $video = Videos::find($videoId);
        if (!$video) {
            Response::json(['error' => 'not_found'], 404);
            return;
        }

        $start = max(0, (int) $request->input('start_ms', 0));
        $end   = max(0, (int) $request->input('end_ms', 0));
        if ($end < $start) {
            Response::json(['error' => 'invalid_timing', 'message' => '終了時刻が開始より前です。'], 422);
            return;
        }

        $sid = App::db()->insert('segments', [
            'video_id'    => $videoId,
            'idx'         => 0,
            'start_ms'    => $start,
            'end_ms'      => $end,
            'source_text' => (string) $request->input('source_text', ''),
        ]);

        // 訳文を戻す。translations（言語→訳文）があれば全言語ぶん、無ければ lang の1言語だけ。
        $lang = VideoLangs::resolve($videoId, (string) $request->input('lang', ''));
        $translations = $request->input('translations');
        if (!is_array($translations) || !$translations) {
            $translations = [$lang => [
                'machine_translation' => $request->input('machine_translation'),
                'edited_translation'  => $request->input('edited_translation'),
                'is_approved'         => (int) (bool) $request->input('is_approved', 0),
                'approved_by'         => (string) $request->input('approved_by', ''),
            ]];
        }

        $isTraining = (($video['kind'] ?? '') === 'training_import');
        foreach ($translations as $tl => $t) {
            if (!TargetLangs::isValid((string) $tl) || !is_array($t)) {
                continue;
            }
            $approved = (int) (bool) ($t['is_approved'] ?? 0);
            Segments::saveTranslation($sid, (string) $tl, [
                'machine_translation' => $t['machine_translation'] ?? null,
                'edited_translation'  => $t['edited_translation'] ?? null,
                'is_approved'         => $approved,
                // 承認者は元の値を引き継ぐ（無ければ復元した人）
                'approved_by'         => $approved
                    ? (trim((string) ($t['approved_by'] ?? '')) ?: (\App\Auth::name() ?? ''))
                    : null,
            ]);

            // 削除時に取り消したTMを戻す（確認済み、または学習用動画）
            if ($approved || $isTraining) {
                try {
                    if ($approved) {
                        Learning::recordApprovedSegment($videoId, $sid, (string) $tl);
                    } else {
                        Learning::recordSegmentById($videoId, $sid, 'human_edit', 0.85, (string) $tl);
                    }
                } catch (\Throwable $e) {
                    App::log('復元したセグメントのTM反映に失敗: ' . $e->getMessage(), 'learning');
                }
            }
        }

        $created = Segments::find($sid, $lang);
        if ($created === null) {
            Response::json(['error' => 'create_failed', 'message' => 'セグメントを復元できませんでした。'], 500);
            return;
        }
        Response::json(['ok' => true, 'segment' => self::publicSegment($created)], 201);
    }

    /**
     * 動画編集（区間カット）要求。
     * 指定区間 [start_ms, end_ms) を映像・音声・字幕から削除し、後続を繰り上げる。
     * 重い再エンコードを伴うのでバックグラウンドジョブとして実行する。
     */
    public function cutVideo(Request $request, array $params): void
    {
        $videoId = (int) $params['id'];
        $v = Videos::find($videoId);
        if (!$v) {
            Response::json(['error' => 'not_found'], 404);
            return;
        }

        // 編集可能な状態のときだけ受け付ける（処理中の二重実行を防ぐ）
        $status = (string) $v['status'];
        if (!in_array($status, ['ready', 'exported'], true)) {
            Response::json([
                'error'   => 'not_ready',
                'message' => 'この動画は今カットできません（処理が完了してから実行してください）。',
            ], 422);
            return;
        }
        if (empty($v['file_path'])) {
            Response::json(['error' => 'no_file', 'message' => '動画ファイルがありません。'], 422);
            return;
        }

        $start = (int) $request->input('start_ms', -1);
        $end   = (int) $request->input('end_ms', -1);
        if ($start < 0 || $end <= $start) {
            Response::json([
                'error'   => 'invalid_range',
                'message' => 'カットの開始・終了時刻が不正です。',
            ], 422);
            return;
        }

        // 総尺が分かればクランプ・全体削除の防止を行う
        $durationMs = ($v['duration_ms'] !== null) ? (int) $v['duration_ms'] : null;
        if ($durationMs !== null && $durationMs > 0) {
            if ($start >= $durationMs) {
                Response::json(['error' => 'invalid_range', 'message' => '開始時刻が動画の終わりより後です。'], 422);
                return;
            }
            $end = min($end, $durationMs);
            if ($start <= 0 && $end >= $durationMs) {
                Response::json(['error' => 'whole_video', 'message' => '動画全体を削除することはできません。'], 422);
                return;
            }
        }
        if (($end - $start) < 50) {
            Response::json(['error' => 'too_short', 'message' => 'カット範囲が短すぎます。'], 422);
            return;
        }

        Videos::setStatus($videoId, 'trimming');
        Videos::setStage($videoId, 1, 1, '動画をカット中');
        Videos::setProgress($videoId, 0, '');
        \App\Jobs::enqueue($videoId, 'trim', ['cut_start_ms' => $start, 'cut_end_ms' => $end]);
        \App\Worker::spawn();

        Response::json(['ok' => true]);
    }

    /** 書き出し（焼き込み）要求。lang を指定するとその言語の字幕で焼き込む。 */
    public function requestExport(Request $request, array $params): void
    {
        $videoId = (int) $params['id'];
        $v = Videos::find($videoId);
        if (!$v) {
            Response::json(['error' => 'not_found'], 404);
            return;
        }
        if (Segments::countForVideo($videoId) === 0) {
            Response::json(['error' => 'no_segments', 'message' => '字幕がありません。'], 422);
            return;
        }
        $lang = VideoLangs::resolve($videoId, (string) $request->input('lang', ''));
        if (Segments::countTranslated($videoId, $lang) === 0) {
            Response::json([
                'error'   => 'not_translated',
                'message' => TargetLangs::label($lang) . 'の字幕がまだありません。',
            ], 422);
            return;
        }
        if (in_array($v['status'], ['exporting'], true)) {
            Response::json(['ok' => true, 'message' => 'すでに書き出し中です。']);
            return;
        }
        Videos::setStatus($videoId, 'exporting');
        \App\Jobs::enqueue($videoId, 'export', ['target_lang' => $lang]);
        \App\Worker::spawn(); // 即時にバックグラウンド処理を起動
        Response::json(['ok' => true, 'lang' => $lang]);
    }

    /**
     * この動画に字幕言語を追加し、その言語への翻訳を開始する。
     * 文字起こし済みの原文をそのまま使うので、追加分は翻訳のみ（APIコストは言語ごとにかかる）。
     */
    public function addLang(Request $request, array $params): void
    {
        $videoId = (int) $params['id'];
        $v = Videos::find($videoId);
        if (!$v) {
            Response::json(['error' => 'not_found'], 404);
            return;
        }
        $lang = (string) $request->input('target_lang', '');
        if (!TargetLangs::isValid($lang)) {
            Response::json(['error' => 'bad_lang', 'message' => '対応していない言語です。'], 422);
            return;
        }
        if (VideoLangs::has($videoId, $lang)) {
            Response::json(['error' => 'exists', 'message' => 'その言語はすでにあります。'], 422);
            return;
        }
        if (!in_array((string) $v['status'], ['ready', 'exported'], true)) {
            Response::json([
                'error'   => 'not_ready',
                'message' => '処理が完了してから言語を追加してください。',
            ], 422);
            return;
        }
        if (Segments::countForVideo($videoId) === 0) {
            Response::json(['error' => 'no_segments', 'message' => '字幕がありません。'], 422);
            return;
        }

        VideoLangs::add($videoId, $lang, 'translating');
        Videos::setStatus($videoId, 'translating');
        Videos::setStage($videoId, 3, 3, TargetLangs::label($lang) . 'へ翻訳中');
        Videos::setProgress($videoId, 0, '');
        \App\Jobs::enqueue($videoId, 'translate', ['target_lang' => $lang]);
        \App\Worker::spawn();

        Response::json(['ok' => true, 'lang' => $lang]);
    }

    /** 字幕言語を削除（その言語の訳文と焼き込みMP4を消す）。主言語は削除できない。 */
    public function deleteLang(Request $request, array $params): void
    {
        $videoId = (int) $params['id'];
        $lang = (string) ($params['lang'] ?? '');
        if (!Videos::find($videoId)) {
            Response::json(['error' => 'not_found'], 404);
            return;
        }
        if (!VideoLangs::has($videoId, $lang)) {
            Response::json(['error' => 'not_found', 'message' => 'その言語はありません。'], 404);
            return;
        }
        if ($lang === VideoLangs::primaryLang($videoId)) {
            Response::json([
                'error'   => 'primary',
                'message' => '主言語（最初に作った言語）は削除できません。',
            ], 422);
            return;
        }
        VideoLangs::remove($videoId, $lang);
        Response::json(['ok' => true]);
    }

    /**
     * 編集済みの字幕（原文＋確定訳）からAIで専門用語を抽出し、用語集に登録する。
     * 文字起こし時に自動登録しなかった通常動画でも、編集後に手動で実行できる。
     */
    public function buildGlossary(Request $request, array $params): void
    {
        $videoId = (int) $params['id'];
        $v = Videos::find($videoId);
        if (!$v) {
            Response::json(['error' => 'not_found'], 404);
            return;
        }

        $lang = (string) ($v['source_lang'] ?? '');

        // 用語を抽出する対象の言語（編集画面で表示中の言語。未指定は主言語）
        $targetLang = VideoLangs::resolve($videoId, (string) $request->input('lang', ''));

        if ($lang === '' || $lang === 'auto' || \App\TargetLangs::sameLanguage($lang, $targetLang)) {
            Response::json([
                'error'   => 'unsupported_lang',
                'message' => '用語集のAI登録には、原語が判定済みで訳語と異なる言語の動画が必要です（この動画の原語: '
                    . ($lang !== '' ? \App\TargetLangs::sourceName($lang) : '不明') . '）。',
            ], 422);
            return;
        }

        // 編集済みの確定訳を優先してペアを作る。確認済み＞編集訳の順で信頼度を付ける。
        $pairs = [];
        foreach (Segments::forVideo($videoId, $targetLang) as $s) {
            $src = trim((string) ($s['source_text'] ?? ''));
            $ja  = trim((string) ($s['edited_translation'] ?? ''));
            if ($ja === '') {
                $ja = trim((string) ($s['machine_translation'] ?? ''));
            }
            if ($src === '' || $ja === '') {
                continue;
            }
            $confidence = !empty($s['is_approved'])
                ? 1.0
                : (trim((string) ($s['edited_translation'] ?? '')) !== '' ? 0.8 : 0.6);
            $pairs[] = [
                'source_text' => $src,
                'ja_text'     => $ja,
                'confidence'  => $confidence,
            ];
        }

        if (count($pairs) < 2) {
            Response::json([
                'error'   => 'no_pairs',
                'message' => '対象になる字幕（原文と訳のペア）が足りません。',
            ], 422);
            return;
        }

        try {
            $count = \App\GlossaryAI::extractAndRegister($pairs, $lang, $videoId, $targetLang);
        } catch (\Throwable $e) {
            App::log('用語集の手動AI登録に失敗: ' . $e->getMessage(), 'learning');
            Response::json([
                'error'   => 'ai_failed',
                'message' => 'AIによる用語抽出に失敗しました（APIキー設定や通信状況を確認してください）。',
            ], 500);
            return;
        }

        App::log("用語集にAIで{$count}件を登録(手動): video #{$videoId}", 'learning');
        Response::json([
            'ok'      => true,
            'count'   => $count,
            'message' => $count > 0
                ? "用語集に{$count}件を登録しました。"
                : '新たに登録できる専門用語は見つかりませんでした（既に登録済みの可能性もあります）。',
        ]);
    }

    /** 動画をフォルダへ移動（folder_id=null/0でルート） */
    public function moveVideo(Request $request, array $params): void
    {
        $videoId = (int) $params['id'];
        if (!Videos::find($videoId)) {
            Response::json(['error' => 'not_found'], 404);
            return;
        }
        $fid = $request->input('folder_id');
        $fid = ($fid === null || $fid === '' || (int) $fid === 0) ? null : (int) $fid;
        if ($fid !== null && !\App\Repo\Folders::find($fid)) {
            Response::json(['error' => 'bad_folder'], 422);
            return;
        }
        Videos::setFolder($videoId, $fid);
        Response::json(['ok' => true]);
    }

    /** 指定動画に属するセグメントを取得（無ければnull）。訳文は $lang の言語で読む。 */
    private static function ownedSegment(int $videoId, int $sid, ?string $lang = null): ?array
    {
        $seg = Segments::find($sid, $lang);
        if ($seg === null || (int) $seg['video_id'] !== $videoId) {
            return null;
        }
        return $seg;
    }

    private static function publicSegment(array $s): array
    {
        return [
            'id'                  => (int) $s['id'],
            'start_ms'            => (int) $s['start_ms'],
            'end_ms'              => (int) $s['end_ms'],
            'source_text'         => (string) $s['source_text'],
            'machine_translation' => $s['machine_translation'],
            'edited_translation'  => $s['edited_translation'],
            'is_approved'         => (bool) $s['is_approved'],
            'approved_by'         => $s['approved_by'] ?? null,
        ];
    }

    /** 一覧/状態APIで返す公開用の形 */
    /** サーバー全体の負荷（CPU/メモリ/ロード平均）。ローカル文字起こし中の可視化用。 */
    public function systemLoad(Request $request): void
    {
        Response::json(\App\SystemLoad::snapshot());
    }

    private static function publicVideo(array $v): array
    {
        return [
            'id'           => (int) $v['id'],
            'title'        => $v['title'],
            'status'       => $v['status'],
            'status_label' => status_label($v['status']),
            'kind'         => $v['kind'],
            'has_thumbnail' => !empty($v['thumbnail_path']),
            'has_export'   => !empty($v['export_path']),
            'created_at'   => $v['created_at'],
            'updated_at'   => $v['updated_at'],
            'error'        => $v['error_message'],
            'progress'     => isset($v['progress']) && $v['progress'] !== null ? (int) $v['progress'] : null,
            'progress_note' => $v['progress_note'] ?? null,
            'stage_index'  => isset($v['stage_index']) && $v['stage_index'] !== null ? (int) $v['stage_index'] : null,
            'stage_total'  => isset($v['stage_total']) && $v['stage_total'] !== null ? (int) $v['stage_total'] : null,
            'stage_label'  => $v['stage_label'] ?? null,
        ];
    }
}
