<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\Request;
use App\Http\Response;
use App\Repo\Folders;

final class FolderController
{
    public function store(Request $request): void
    {
        $name = trim((string) $request->input('name', ''));
        $parentId = self::nullableInt($request->input('parent_id'));
        if ($parentId !== null && !Folders::find($parentId)) {
            $parentId = null;
        }
        Folders::create($name, $parentId);
        Response::redirect(self::backTo($parentId));
    }

    public function rename(Request $request, array $params): void
    {
        $id = (int) $params['id'];
        $f = Folders::find($id);
        if (!$f) {
            Response::notFound();
            return;
        }
        Folders::rename($id, trim((string) $request->input('name', '')));
        Response::redirect(self::backTo($f['parent_id'] !== null ? (int) $f['parent_id'] : null));
    }

    public function destroy(Request $request, array $params): void
    {
        $id = (int) $params['id'];
        $f = Folders::find($id);
        if (!$f) {
            Response::notFound();
            return;
        }
        $parent = $f['parent_id'] !== null ? (int) $f['parent_id'] : null;
        Folders::delete($id);
        Response::redirect(self::backTo($parent));
    }

    private static function backTo(?int $folderId): string
    {
        return '/' . ($folderId !== null ? '?folder=' . $folderId : '');
    }

    private static function nullableInt(mixed $v): ?int
    {
        if ($v === null || $v === '' || $v === '0') {
            return null;
        }
        return (int) $v;
    }
}
