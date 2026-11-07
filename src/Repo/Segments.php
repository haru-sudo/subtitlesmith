<?php

declare(strict_types=1);

namespace App\Repo;

use App\App;
use App\TargetLangs;

/**
 * segments テーブルのアクセサ。
 *
 * 時刻・原文は言語に依らず共通で segments に、訳文は言語ごとに segment_translations に入る。
 * 読み出し系は「指定言語の訳文を結合した1行」を返すので、呼び出し側からは
 * 多言語化の前と同じ形（machine_translation / edited_translation / is_approved / approved_by）に見える。
 */
final class Segments
{
    /** 訳文を結合して読むための共通SELECT。:l に言語を渡す。 */
    private const SELECT_WITH_TRANSLATION =
        'SELECT s.id, s.video_id, s.idx, s.start_ms, s.end_ms, s.source_text, s.confidence, s.updated_at,
                t.machine_translation, t.edited_translation,
                COALESCE(t.is_approved, 0) AS is_approved, t.approved_by,
                t.updated_at AS translation_updated_at
           FROM segments s
           LEFT JOIN segment_translations t
                  ON t.segment_id = s.id AND t.target_lang = :l';

    /**
     * @param string|null $lang 訳文を読む言語。null なら動画の主言語。
     * @return array<int,array> 開始時刻順
     */
    public static function forVideo(int $videoId, ?string $lang = null): array
    {
        return App::db()->fetchAll(
            self::SELECT_WITH_TRANSLATION . ' WHERE s.video_id = :v ORDER BY s.start_ms ASC, s.id ASC',
            [':v' => $videoId, ':l' => self::lang($videoId, $lang)]
        );
    }

    /** @param string|null $lang 訳文を読む言語。null ならその動画の主言語。 */
    public static function find(int $id, ?string $lang = null): ?array
    {
        $videoId = (int) App::db()->fetchValue('SELECT video_id FROM segments WHERE id = :id', [':id' => $id]);
        if ($videoId <= 0) {
            return null;
        }
        return App::db()->fetch(
            self::SELECT_WITH_TRANSLATION . ' WHERE s.id = :id',
            [':id' => $id, ':l' => self::lang($videoId, $lang)]
        );
    }

    /** 言語指定の解決（未指定なら動画の主言語） */
    private static function lang(int $videoId, ?string $lang): string
    {
        return ($lang !== null && $lang !== '')
            ? TargetLangs::normalize($lang)
            : VideoLangs::primaryLang($videoId);
    }

    public static function deleteForVideo(int $videoId): void
    {
        // segment_translations は ON DELETE CASCADE で一緒に消える
        App::db()->run('DELETE FROM segments WHERE video_id = :v', [':v' => $videoId]);
    }

    /**
     * 文字起こし結果をまとめて投入（既存は置き換え）。訳文はまだ無いので原文だけ。
     * @param array<int,array{start_ms:int,end_ms:int,text:string}> $segments
     */
    public static function replaceForVideo(int $videoId, array $segments): void
    {
        $db = App::db();
        $db->beginTransaction();
        try {
            self::deleteForVideo($videoId);
            $idx = 0;
            foreach ($segments as $s) {
                $db->insert('segments', [
                    'video_id'   => $videoId,
                    'idx'        => $idx++,
                    'start_ms'   => (int) $s['start_ms'],
                    'end_ms'     => (int) $s['end_ms'],
                    'source_text' => (string) $s['text'],
                ]);
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    /**
     * 詳細フィールド付きで一括置換（OCR取り込み用）。訳文は $lang の言語として入れる。
     * @param array<int,array{start_ms:int,end_ms:int,source_text:string,edited_translation:string,confidence:float,is_approved?:int}> $rows
     */
    public static function replaceForVideoDetailed(int $videoId, array $rows, ?string $lang = null): void
    {
        $db = App::db();
        $lang = self::lang($videoId, $lang);
        $db->beginTransaction();
        try {
            self::deleteForVideo($videoId);
            $idx = 0;
            foreach ($rows as $r) {
                $sid = $db->insert('segments', [
                    'video_id'    => $videoId,
                    'idx'         => $idx++,
                    'start_ms'    => (int) $r['start_ms'],
                    'end_ms'      => (int) $r['end_ms'],
                    'source_text' => (string) ($r['source_text'] ?? ''),
                    'confidence'  => $r['confidence'] ?? null,
                ]);
                self::saveTranslation($sid, $lang, [
                    'edited_translation' => (string) ($r['edited_translation'] ?? ''),
                    'is_approved'        => (int) ($r['is_approved'] ?? 0),
                ]);
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    /** 時刻・原文など、言語に依らない列の更新 */
    public static function update(int $id, array $data): void
    {
        if (!$data) {
            return;
        }
        $data['updated_at'] = date('Y-m-d H:i:s');
        App::db()->update('segments', $data, ['id' => $id]);
    }

    /**
     * 訳文（言語ごと）の作成/更新。渡した列だけを書き換える。
     * @param array{machine_translation?:?string,edited_translation?:?string,is_approved?:int,approved_by?:?string} $data
     */
    public static function saveTranslation(int $segmentId, string $lang, array $data): void
    {
        $lang = TargetLangs::normalize($lang);
        $db = App::db();
        $allowed = ['machine_translation', 'edited_translation', 'is_approved', 'approved_by'];
        $set = array_intersect_key($data, array_flip($allowed));
        $set['updated_at'] = date('Y-m-d H:i:s');

        $existing = $db->fetchValue(
            'SELECT id FROM segment_translations WHERE segment_id = :s AND target_lang = :l',
            [':s' => $segmentId, ':l' => $lang]
        );
        if ($existing !== null) {
            $db->update('segment_translations', $set, ['id' => (int) $existing]);
            return;
        }
        $set['segment_id'] = $segmentId;
        $set['target_lang'] = $lang;
        $db->insert('segment_translations', $set);
    }

    /** 機械翻訳を保存し、編集訳が空なら同じ値で初期化（言語ごと） */
    public static function setMachineTranslation(int $id, string $translation, string $lang): void
    {
        $lang = TargetLangs::normalize($lang);
        $db = App::db();
        $cur = $db->fetch(
            'SELECT id, edited_translation FROM segment_translations WHERE segment_id = :s AND target_lang = :l',
            [':s' => $id, ':l' => $lang]
        );
        if ($cur === null) {
            $db->insert('segment_translations', [
                'segment_id'          => $id,
                'target_lang'         => $lang,
                'machine_translation' => $translation,
                'edited_translation'  => $translation,
                'updated_at'          => date('Y-m-d H:i:s'),
            ]);
            return;
        }
        $data = ['machine_translation' => $translation, 'updated_at' => date('Y-m-d H:i:s')];
        if (trim((string) ($cur['edited_translation'] ?? '')) === '') {
            $data['edited_translation'] = $translation;
        }
        $db->update('segment_translations', $data, ['id' => (int) $cur['id']]);
    }

    public static function countForVideo(int $videoId): int
    {
        return (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM segments WHERE video_id = :v',
            [':v' => $videoId]
        );
    }

    /** この言語の訳文が1件でも入っているか（言語追加直後の未翻訳判定に使う） */
    public static function countTranslated(int $videoId, string $lang): int
    {
        return (int) App::db()->fetchValue(
            "SELECT COUNT(*) FROM segment_translations t
               JOIN segments s ON s.id = t.segment_id
              WHERE s.video_id = :v AND t.target_lang = :l
                AND COALESCE(TRIM(t.edited_translation), '') <> ''",
            [':v' => $videoId, ':l' => TargetLangs::normalize($lang)]
        );
    }

    /**
     * 動画の区間 [cutStart, cutEnd) をカット（削除）したときのセグメント側の反映。
     * 映像側の実カットは Media::cutOutRange が行い、こちらはDBの時刻を辻褄合わせする。
     * 時刻は全言語共通なので、言語によらず一度だけ適用する。
     *
     * ルール（dur = cutEnd - cutStart）:
     *  - 終了 <= cutStart（カットより前）      : 変更なし
     *  - 開始 >= cutEnd （カットより後）        : 開始・終了を dur だけ繰り上げ
     *  - カット区間に完全に含まれる            : 削除
     *  - カットをまたぐ/一部重なる            : 残る部分だけになるよう時刻を詰める
     *      * 前後にまたぐ (s<cutStart, e>cutEnd) : [s, e-dur]
     *      * 前だけ残る  (s<cutStart, e<=cutEnd) : [s, cutStart]
     *      * 後だけ残る  (s>=cutStart, e>cutEnd) : [cutStart, e-dur]
     *  - 詰めた結果、長さが 0 以下になった行は削除
     * 反映後、開始時刻順で idx を振り直す。
     *
     * @return array{deleted:int, shifted:int, clipped:int}
     */
    public static function applyCut(int $videoId, int $cutStart, int $cutEnd): array
    {
        $cutStart = max(0, $cutStart);
        $cutEnd   = max(0, $cutEnd);
        $dur = $cutEnd - $cutStart;
        $result = ['deleted' => 0, 'shifted' => 0, 'clipped' => 0];
        if ($dur <= 0) {
            return $result;
        }

        $db = App::db();
        $now = date('Y-m-d H:i:s');
        $db->beginTransaction();
        try {
            $rows = $db->fetchAll(
                'SELECT id, start_ms, end_ms FROM segments WHERE video_id = :v ORDER BY start_ms ASC, id ASC',
                [':v' => $videoId]
            );
            foreach ($rows as $r) {
                $id = (int) $r['id'];
                $s  = (int) $r['start_ms'];
                $e  = (int) $r['end_ms'];

                // カットより前 → そのまま
                if ($e <= $cutStart) {
                    continue;
                }
                // カットより後 → 繰り上げ
                if ($s >= $cutEnd) {
                    $db->update('segments', [
                        'start_ms'   => $s - $dur,
                        'end_ms'     => $e - $dur,
                        'updated_at' => $now,
                    ], ['id' => $id]);
                    $result['shifted']++;
                    continue;
                }

                // ここに来る行はカット区間と重なる
                if ($s >= $cutStart && $e <= $cutEnd) {
                    // 完全にカット区間内 → 削除
                    $db->run('DELETE FROM segments WHERE id = :id', [':id' => $id]);
                    $result['deleted']++;
                    continue;
                }

                if ($s < $cutStart && $e > $cutEnd) {
                    $newS = $s;           // 前後にまたぐ
                    $newE = $e - $dur;
                } elseif ($s < $cutStart) {
                    $newS = $s;           // 前だけ残る（e は cutEnd 以下）
                    $newE = $cutStart;
                } else {
                    $newS = $cutStart;    // 後だけ残る（s は cutStart 以上, e は cutEnd 超）
                    $newE = $e - $dur;
                }

                if ($newE <= $newS) {
                    // 詰めた結果ゼロ長 → 削除
                    $db->run('DELETE FROM segments WHERE id = :id', [':id' => $id]);
                    $result['deleted']++;
                    continue;
                }

                $db->update('segments', [
                    'start_ms'   => $newS,
                    'end_ms'     => $newE,
                    'updated_at' => $now,
                ], ['id' => $id]);
                $result['clipped']++;
            }

            self::reindex($db, $videoId);
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        return $result;
    }

    /**
     * Excel/CSV 取り込み結果をセグメントへ反映する（訳文は $lang の言語に入る）。
     * - ID が既存行と一致 → その行を更新（指定された列のみ）
     * - ID が空 → 新規追加（開始/終了msが必要）
     * - $replace=true → シートに無かった既存行は削除（完全置換。他言語の訳も消えるので注意）
     * 反映後、開始時刻順で idx を振り直す。
     *
     * @param array<int,array{id:?int,start_ms:?int,end_ms:?int,source_text:?string,
     *   machine_translation:?string,edited_translation:?string,is_approved:?int,approved_by:?string}> $rows
     * @return array{updated:int,inserted:int,deleted:int,skipped:int}
     */
    public static function applyImport(
        int $videoId,
        array $rows,
        bool $replace,
        ?string $approver = null,
        ?string $lang = null
    ): array {
        $db = App::db();
        $lang = self::lang($videoId, $lang);
        $existing = [];
        foreach (self::forVideo($videoId, $lang) as $row) {
            $existing[(int) $row['id']] = $row;
        }

        $updated = $inserted = $deleted = $skipped = 0;
        $seen = [];

        $db->beginTransaction();
        try {
            foreach ($rows as $r) {
                $id = isset($r['id']) ? (int) $r['id'] : 0;

                if ($id > 0 && isset($existing[$id])) {
                    // ---- 既存行を更新 ----
                    $cur = $existing[$id];
                    $base = [];   // segments（言語共通）
                    $tr = [];     // segment_translations（この言語）
                    if (array_key_exists('source_text', $r) && $r['source_text'] !== null) {
                        $base['source_text'] = (string) $r['source_text'];
                    }
                    foreach (['machine_translation', 'edited_translation'] as $f) {
                        if (array_key_exists($f, $r) && $r[$f] !== null) {
                            $tr[$f] = (string) $r[$f];
                        }
                    }
                    if (($r['start_ms'] ?? null) !== null) {
                        $base['start_ms'] = max(0, (int) $r['start_ms']);
                    }
                    if (($r['end_ms'] ?? null) !== null) {
                        $base['end_ms'] = max(0, (int) $r['end_ms']);
                    }
                    // 終了<開始は不整合なので時刻更新を破棄（テキスト等は反映）
                    $start = $base['start_ms'] ?? (int) $cur['start_ms'];
                    $end   = $base['end_ms'] ?? (int) $cur['end_ms'];
                    if ($end < $start) {
                        unset($base['start_ms'], $base['end_ms']);
                    }
                    if (($r['is_approved'] ?? null) !== null) {
                        $approved = (int) (bool) $r['is_approved'];
                        $tr['is_approved'] = $approved;
                        $wasApproved = !empty($cur['is_approved']);
                        if ($approved && !$wasApproved) {
                            $tr['approved_by'] = $approver ?? ($cur['approved_by'] ?? '');
                        } elseif (!$approved) {
                            $tr['approved_by'] = null;
                        }
                    }
                    if ($base || $tr) {
                        if ($base) {
                            $base['updated_at'] = date('Y-m-d H:i:s');
                            $db->update('segments', $base, ['id' => $id]);
                        }
                        if ($tr) {
                            self::saveTranslation($id, $lang, $tr);
                        }
                        $updated++;
                    }
                    $seen[$id] = true;
                } else {
                    // ---- 新規追加 ----
                    $start = ($r['start_ms'] ?? null) !== null ? max(0, (int) $r['start_ms']) : null;
                    $end   = ($r['end_ms'] ?? null) !== null ? max(0, (int) $r['end_ms']) : null;
                    // 時刻が両方とも取れない行は追加できない（既存IDにも該当しない）
                    if ($start === null && $end === null) {
                        $skipped++;
                        continue;
                    }
                    $start ??= 0;
                    $end ??= $start;
                    if ($end < $start) {
                        $end = $start;
                    }
                    $approved = (int) (bool) ($r['is_approved'] ?? 0);
                    $sid = $db->insert('segments', [
                        'video_id'    => $videoId,
                        'idx'         => 0,
                        'start_ms'    => $start,
                        'end_ms'      => $end,
                        'source_text' => (string) ($r['source_text'] ?? ''),
                    ]);
                    self::saveTranslation($sid, $lang, [
                        'machine_translation' => $r['machine_translation'] ?? null,
                        'edited_translation'  => $r['edited_translation'] ?? null,
                        'is_approved'         => $approved,
                        'approved_by'         => $approved ? ($approver ?? '') : null,
                    ]);
                    $inserted++;
                }
            }

            if ($replace) {
                foreach ($existing as $id => $_row) {
                    if (empty($seen[$id])) {
                        $db->run('DELETE FROM segments WHERE id = :id', [':id' => $id]);
                        $deleted++;
                    }
                }
            }

            self::reindex($db, $videoId);
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        return ['updated' => $updated, 'inserted' => $inserted, 'deleted' => $deleted, 'skipped' => $skipped];
    }

    /** idx を開始時刻順で振り直す */
    private static function reindex(\App\Database $db, int $videoId): void
    {
        $idx = 0;
        foreach ($db->fetchAll(
            'SELECT id FROM segments WHERE video_id = :v ORDER BY start_ms ASC, id ASC',
            [':v' => $videoId]
        ) as $row) {
            $db->update('segments', ['idx' => $idx++], ['id' => (int) $row['id']]);
        }
    }
}
