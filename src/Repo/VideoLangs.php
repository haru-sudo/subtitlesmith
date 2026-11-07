<?php

declare(strict_types=1);

namespace App\Repo;

use App\App;
use App\TargetLangs;

/**
 * video_langs テーブルのアクセサ。
 * 1本の動画が持つ字幕言語と、その言語ごとの状態（翻訳の進み具合・焼き込み済みMP4）を扱う。
 *
 * 主言語（videos.target_lang）は「編集画面を開いたとき最初に表示する言語」で、
 * 実体としては他の言語と同じ1行として扱う。
 */
final class VideoLangs
{
    /** @return array<int,array> 追加順（主言語が先頭に来るよう並べ替え済み） */
    public static function forVideo(int $videoId): array
    {
        $rows = App::db()->fetchAll(
            'SELECT * FROM video_langs WHERE video_id = :v ORDER BY id ASC',
            [':v' => $videoId]
        );
        $primary = self::primaryLang($videoId);
        usort($rows, static function (array $a, array $b) use ($primary): int {
            $ap = ($a['target_lang'] === $primary) ? 0 : 1;
            $bp = ($b['target_lang'] === $primary) ? 0 : 1;
            return $ap <=> $bp ?: ((int) $a['id'] <=> (int) $b['id']);
        });
        return $rows;
    }

    /** @return array<int,string> この動画が持つ言語IDの配列（主言語が先頭） */
    public static function codes(int $videoId): array
    {
        return array_map(static fn(array $r) => (string) $r['target_lang'], self::forVideo($videoId));
    }

    /**
     * 複数動画ぶんの言語をまとめて取得する（一覧画面の N+1 を避ける）。
     * @param array<int,int> $videoIds
     * @return array<int,array<int,string>> video_id => 言語IDの配列
     */
    public static function codesForVideos(array $videoIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $videoIds)));
        if (!$ids) {
            return [];
        }
        $in = implode(',', $ids);   // 整数化済みなのでそのまま埋め込める
        $out = [];
        foreach (App::db()->fetchAll(
            "SELECT video_id, target_lang FROM video_langs WHERE video_id IN ($in) ORDER BY id ASC"
        ) as $r) {
            $out[(int) $r['video_id']][] = (string) $r['target_lang'];
        }
        return $out;
    }

    public static function find(int $videoId, string $lang): ?array
    {
        return App::db()->fetch(
            'SELECT * FROM video_langs WHERE video_id = :v AND target_lang = :l',
            [':v' => $videoId, ':l' => TargetLangs::normalize($lang)]
        );
    }

    public static function has(int $videoId, string $lang): bool
    {
        return self::find($videoId, $lang) !== null;
    }

    /** 動画の主言語（videos.target_lang。未設定なら既定） */
    public static function primaryLang(int $videoId): string
    {
        $v = App::db()->fetchValue('SELECT target_lang FROM videos WHERE id = :v', [':v' => $videoId]);
        return TargetLangs::normalize($v !== null ? (string) $v : null);
    }

    /**
     * 表示・編集の対象言語を決める。
     * 指定が無い/不正/この動画が持っていない言語なら主言語にフォールバックする。
     */
    public static function resolve(int $videoId, ?string $lang): string
    {
        $lang = (string) $lang;
        if ($lang !== '' && TargetLangs::isValid($lang) && self::has($videoId, $lang)) {
            return $lang;
        }
        $primary = self::primaryLang($videoId);
        // 主言語の行がまだ無い（古いデータ）場合も、主言語を返して破綻させない
        return $primary;
    }

    /** 言語を追加（既にあれば何もしない）。追加した場合のみ true。 */
    public static function add(int $videoId, string $lang, string $status = 'pending'): bool
    {
        $lang = TargetLangs::normalize($lang);
        if (self::has($videoId, $lang)) {
            return false;
        }
        App::db()->insert('video_langs', [
            'video_id'    => $videoId,
            'target_lang' => $lang,
            'status'      => $status,
        ]);
        return true;
    }

    public static function setStatus(int $videoId, string $lang, string $status, ?string $error = null): void
    {
        App::db()->update(
            'video_langs',
            ['status' => $status, 'error_message' => $error],
            ['video_id' => $videoId, 'target_lang' => TargetLangs::normalize($lang)]
        );
    }

    public static function setExport(int $videoId, string $lang, string $exportPath): void
    {
        App::db()->update(
            'video_langs',
            ['export_path' => $exportPath, 'exported_at' => date('Y-m-d H:i:s')],
            ['video_id' => $videoId, 'target_lang' => TargetLangs::normalize($lang)]
        );
    }

    /** 言語をまるごと削除（その言語の訳文と焼き込みMP4も消す）。主言語は削除できない。 */
    public static function remove(int $videoId, string $lang): bool
    {
        $lang = TargetLangs::normalize($lang);
        $row = self::find($videoId, $lang);
        if ($row === null || $lang === self::primaryLang($videoId)) {
            return false;
        }
        $db = App::db();
        $db->run(
            'DELETE FROM segment_translations
              WHERE target_lang = :l
                AND segment_id IN (SELECT id FROM segments WHERE video_id = :v)',
            [':l' => $lang, ':v' => $videoId]
        );
        if (!empty($row['export_path'])) {
            @unlink(App::storagePath((string) $row['export_path']));
        }
        $db->run(
            'DELETE FROM video_langs WHERE video_id = :v AND target_lang = :l',
            [':v' => $videoId, ':l' => $lang]
        );
        return true;
    }

    /**
     * この言語の焼き込みMP4が古い（作り直しが必要）か。
     * 字幕の編集時刻（原文・時刻の変更＝全言語共通、その言語の訳文の変更）と比べる。
     */
    public static function isExportStale(int $videoId, string $lang): bool
    {
        $row = self::find($videoId, $lang);
        if ($row === null || empty($row['export_path'])) {
            return true; // まだ書き出していない＝最新版は未作成
        }
        $exportedAt = (string) ($row['exported_at'] ?? '');
        if ($exportedAt === '') {
            return true; // 旧データで時刻不明なら、安全側で「作り直し可」にする
        }
        $db = App::db();
        // 時刻・原文の変更（言語共通）
        $lastEdit = (string) $db->fetchValue(
            'SELECT MAX(updated_at) FROM segments WHERE video_id = :v',
            [':v' => $videoId]
        );
        // この言語の訳文の変更
        $lastTr = (string) $db->fetchValue(
            'SELECT MAX(t.updated_at) FROM segment_translations t
               JOIN segments s ON s.id = t.segment_id
              WHERE s.video_id = :v AND t.target_lang = :l',
            [':v' => $videoId, ':l' => TargetLangs::normalize($lang)]
        );
        // 字幕の最低表示時間の設定変更（焼き込みの表示時間が変わる）
        $lastCfg = '';
        try {
            $lastCfg = (string) $db->fetchValue(
                "SELECT MAX(updated_at) FROM app_settings
                  WHERE key IN ('subtitle_min_sec_multi', 'subtitle_min_sec_single')"
            );
        } catch (\Throwable $e) {
            // 設定テーブルが無い環境では無視
        }
        $last = max($lastEdit, $lastTr, $lastCfg);
        // 文字列比較で時系列比較できる（'Y-m-d H:i:s' 形式）
        return $last !== '' && $last > $exportedAt;
    }
}
