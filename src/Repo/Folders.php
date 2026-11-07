<?php

declare(strict_types=1);

namespace App\Repo;

use App\App;

/**
 * フォルダ（入れ子可）。
 */
final class Folders
{
    public static function find(int $id): ?array
    {
        return App::db()->fetch('SELECT * FROM folders WHERE id = :id', [':id' => $id]);
    }

    public static function all(): array
    {
        return App::db()->fetchAll('SELECT * FROM folders ORDER BY name COLLATE NOCASE');
    }

    /** 指定フォルダ直下の子フォルダ（null=ルート直下） */
    public static function children(?int $parentId): array
    {
        if ($parentId === null) {
            return App::db()->fetchAll(
                'SELECT * FROM folders WHERE parent_id IS NULL ORDER BY name COLLATE NOCASE'
            );
        }
        return App::db()->fetchAll(
            'SELECT * FROM folders WHERE parent_id = :p ORDER BY name COLLATE NOCASE',
            [':p' => $parentId]
        );
    }

    public static function create(string $name, ?int $parentId): int
    {
        return App::db()->insert('folders', [
            'name'      => $name !== '' ? $name : '新しいフォルダ',
            'parent_id' => $parentId,
        ]);
    }

    public static function rename(int $id, string $name): void
    {
        if ($name === '') {
            return;
        }
        App::db()->update('folders', ['name' => $name], ['id' => $id]);
    }

    /**
     * フォルダ削除。配下(子孫含む)の動画はルートへ戻す。子孫フォルダはFKカスケードで削除。
     */
    public static function delete(int $id): void
    {
        $ids = self::descendantIds($id); // 自身＋子孫
        $db = App::db();
        $db->beginTransaction();
        try {
            $place = implode(',', array_fill(0, count($ids), '?'));
            $db->run("UPDATE videos SET folder_id = NULL WHERE folder_id IN ($place)", $ids);
            // parent_id の ON DELETE CASCADE で子孫フォルダも削除される
            $db->run('DELETE FROM folders WHERE id = :id', [':id' => $id]);
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    /** 自身＋全子孫のID */
    public static function descendantIds(int $id): array
    {
        $ids = [$id];
        $queue = [$id];
        while ($queue) {
            $current = array_shift($queue);
            foreach (self::children($current) as $child) {
                $ids[] = (int) $child['id'];
                $queue[] = (int) $child['id'];
            }
        }
        return $ids;
    }

    /** ルート→指定フォルダ のパンくず（配列） */
    public static function breadcrumb(?int $folderId): array
    {
        $crumbs = [];
        $id = $folderId;
        $guard = 0;
        while ($id !== null && $guard++ < 100) {
            $f = self::find($id);
            if (!$f) {
                break;
            }
            array_unshift($crumbs, ['id' => (int) $f['id'], 'name' => (string) $f['name']]);
            $id = $f['parent_id'] !== null ? (int) $f['parent_id'] : null;
        }
        return $crumbs;
    }

    /** 移動先ドロップダウン用: [{id, label(フルパス)}] をラベル順で返す */
    public static function optionList(): array
    {
        $all = self::all();
        $byId = [];
        foreach ($all as $f) {
            $byId[(int) $f['id']] = $f;
        }
        $opts = [];
        foreach ($all as $f) {
            $names = [];
            $cur = $f;
            $guard = 0;
            while ($cur && $guard++ < 100) {
                array_unshift($names, (string) $cur['name']);
                $pid = $cur['parent_id'] !== null ? (int) $cur['parent_id'] : null;
                $cur = $pid !== null ? ($byId[$pid] ?? null) : null;
            }
            $opts[] = ['id' => (int) $f['id'], 'label' => implode(' / ', $names)];
        }
        usort($opts, static fn($a, $b) => strcmp($a['label'], $b['label']));
        return $opts;
    }

    /** フォルダ内(子孫含む)の動画件数 */
    public static function videoCount(int $id): int
    {
        $ids = self::descendantIds($id);
        $place = implode(',', array_fill(0, count($ids), '?'));
        return (int) App::db()->fetchValue(
            "SELECT COUNT(*) FROM videos WHERE folder_id IN ($place)",
            $ids
        );
    }
}
