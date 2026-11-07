<?php

declare(strict_types=1);

namespace App;

/**
 * お知らせ（更新情報）の読み取り。データは config/announcements.php。
 * 日付の新しい順に並べ替えて返す。
 */
final class Announcements
{
    /** @return array<int,array{id:string,date:string,tag:string,title:string,body:array<int,string>}> */
    public static function all(): array
    {
        $file = APP_BASE_PATH . '/config/announcements.php';
        if (!is_file($file)) {
            return [];
        }
        /** @var mixed $data */
        $data = require $file;
        if (!is_array($data)) {
            return [];
        }

        $items = [];
        foreach ($data as $row) {
            if (!is_array($row)) {
                continue;
            }
            $body = $row['body'] ?? [];
            if (is_string($body)) {
                $body = [$body];
            }
            $items[] = [
                'id'    => (string) ($row['id'] ?? ($row['date'] ?? '')),
                'date'  => (string) ($row['date'] ?? ''),
                'tag'   => (string) ($row['tag'] ?? 'お知らせ'),
                'title' => (string) ($row['title'] ?? ''),
                'body'  => is_array($body) ? array_map('strval', $body) : [],
            ];
        }

        // 日付の新しい順（同日は元の並びを保持）
        usort($items, static function (array $a, array $b): int {
            return strcmp($b['date'], $a['date']);
        });

        return $items;
    }

    /** 最新のお知らせ（無ければ null） */
    public static function latest(): ?array
    {
        $all = self::all();
        return $all[0] ?? null;
    }

    /** 最新お知らせの識別キー（未読バッジの判定に使う）。無ければ空文字。 */
    public static function latestKey(): string
    {
        $l = self::latest();
        return $l !== null ? (string) $l['id'] : '';
    }
}
