<?php

declare(strict_types=1);

namespace App\Repo;

use App\App;

/**
 * videos テーブルのアクセサ。
 */
final class Videos
{
    /** @return array<int, array> 新しい順 */
    public static function all(): array
    {
        return App::db()->fetchAll(
            'SELECT * FROM videos ORDER BY created_at DESC, id DESC'
        );
    }

    /**
     * 一覧/ポーリング用の軽量版。必要な列だけ取得（SELECT * を避ける）。
     * created_at は 'Y-m-d H:i:s' で保存されるため辞書順=時系列順。
     * @return array<int, array>
     */
    public static function allForListing(): array
    {
        return App::db()->fetchAll(
            'SELECT id, title, status, kind, thumbnail_path, export_path,
                    error_message, progress, progress_note,
                    stage_index, stage_total, stage_label, created_at, updated_at
               FROM videos
              ORDER BY created_at DESC, id DESC'
        );
    }

    /** 指定フォルダ直下の動画（null=ルート直下） */
    public static function inFolder(?int $folderId): array
    {
        if ($folderId === null) {
            return App::db()->fetchAll(
                'SELECT * FROM videos WHERE folder_id IS NULL ORDER BY created_at DESC, id DESC'
            );
        }
        return App::db()->fetchAll(
            'SELECT * FROM videos WHERE folder_id = :f ORDER BY created_at DESC, id DESC',
            [':f' => $folderId]
        );
    }

    public static function setFolder(int $id, ?int $folderId): void
    {
        self::update($id, ['folder_id' => $folderId]);
    }

    public static function find(int $id): ?array
    {
        return App::db()->fetch('SELECT * FROM videos WHERE id = :id', [':id' => $id]);
    }

    /**
     * 焼き込み済み動画が「古い」か（最後の書き出し後に字幕が編集されたか）を判定する。
     * 書き出しが無い場合や、最新セグメント編集が書き出し時刻より後なら true。
     */
    /**
     * 焼き込み済み動画が古いか（主言語ぶん）。
     * 言語ごとの判定は Repo\VideoLangs::isExportStale() を使う。
     */
    public static function isExportStale(int $id): bool
    {
        return VideoLangs::isExportStale($id, VideoLangs::primaryLang($id));
    }

    public static function create(array $data): int
    {
        return App::db()->insert('videos', $data);
    }

    public static function update(int $id, array $data): void
    {
        $data['updated_at'] = date('Y-m-d H:i:s');
        App::db()->update('videos', $data, ['id' => $id]);
    }

    public static function setStatus(int $id, string $status, ?string $error = null): void
    {
        self::update($id, [
            'status'        => $status,
            'error_message' => $error,
        ]);
    }

    /**
     * 動画を削除する。学習用動画(kind=training_import)の場合は、
     * その動画が生成した翻訳メモリ(TM)・用語集・用語集候補も連動して削除する。
     * DB操作はトランザクションで一括実行（途中失敗時はロールバック）。
     *
     * 注意: TM/用語集は原文をキーに集約しており、複数の動画が同じ語に寄与している
     * ことがある。その場合 source_video_id は「最初(または最後)に記録した動画」を指すため、
     * 連動削除はその紐づけ単位で行われる。
     *
     * @return array{tm:int, glossary:int, candidates:int} 連動削除した件数
     */
    public static function deleteWithLearning(int $id): array
    {
        $db = App::db();
        $v = self::find($id);
        $removed = ['tm' => 0, 'glossary' => 0, 'candidates' => 0];
        if ($v === null) {
            return $removed;
        }
        $purgeLearning = (($v['kind'] ?? '') === 'training_import');

        $db->beginTransaction();
        try {
            if ($purgeLearning) {
                $removed['tm'] = $db->run(
                    'DELETE FROM translation_memory WHERE source_video_id = :id',
                    [':id' => $id]
                )->rowCount();
                $removed['glossary'] = $db->run(
                    'DELETE FROM glossary WHERE source_video_id = :id',
                    [':id' => $id]
                )->rowCount();
                $removed['candidates'] = $db->run(
                    'DELETE FROM glossary_candidates WHERE source_video_id = :id',
                    [':id' => $id]
                )->rowCount();
            }
            // segments / jobs は FK の ON DELETE CASCADE で消える
            $db->run('DELETE FROM videos WHERE id = :id', [':id' => $id]);
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
        return $removed;
    }

    /**
     * 現ステージの進捗を更新する（0-100）。pollで読まれてバー表示に使う。
     * 進捗計算自体は安価なので頻繁に呼んでよい。
     */
    public static function setProgress(int $id, int $percent, string $note = ''): void
    {
        $percent = max(0, min(100, $percent));
        App::db()->update('videos', [
            'progress'      => $percent,
            'progress_note' => $note,
            'updated_at'    => date('Y-m-d H:i:s'),
        ], ['id' => $id]);
    }

    /**
     * 処理ステージを設定する（1始まりの番号/総数/表示名）。
     * 新ステージに入る合図なので、ゲージ(progress)は0へリセットする。
     * 例: setStage($id, 1, 3, 'OCR中') → 「1/3 OCR中」ゲージ0%
     */
    public static function setStage(int $id, int $index, int $total, string $label): void
    {
        App::db()->update('videos', [
            'stage_index'   => $index,
            'stage_total'   => $total,
            'stage_label'   => $label,
            'progress'      => 0,
            'progress_note' => '',
            'updated_at'    => date('Y-m-d H:i:s'),
        ], ['id' => $id]);
    }
}
