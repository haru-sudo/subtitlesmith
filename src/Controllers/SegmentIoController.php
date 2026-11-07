<?php

declare(strict_types=1);

namespace App\Controllers;

use App\App;
use App\Auth;
use App\Http\Request;
use App\Http\Response;
use App\Repo\Segments;
use App\Repo\VideoLangs;
use App\Repo\Videos;
use App\SegmentXlsx;

/**
 * 字幕（AI生成＋編集）の Excel 書き出し / 取り込み。
 *  - GET  /videos/{id}/segments.xlsx     … 全セグメントを .xlsx で書き出す
 *  - POST /videos/{id}/segments/import   … 編集した .xlsx / .csv を取り込む
 */
final class SegmentIoController
{
    /** 取り込みファイルの上限（20MB） */
    private const MAX_UPLOAD = 20 * 1024 * 1024;

    /** 字幕を Excel(.xlsx) で書き出す */
    public function exportXlsx(Request $request, array $params): void
    {
        $v = Videos::find((int) $params['id']);
        if (!$v) {
            Response::notFound('動画が見つかりません');
            return;
        }

        // ?lang= で書き出す言語を選ぶ（未指定は主言語）
        $lang = VideoLangs::resolve((int) $v['id'], (string) $request->input('lang', ''));
        $segments = Segments::forVideo((int) $v['id'], $lang);
        try {
            $bytes = SegmentXlsx::build($segments);
        } catch (\Throwable $e) {
            Response::error($e->getMessage(), 500);
            return;
        }

        $name = self::baseName($v) . '_字幕_' . \App\TargetLangs::label($lang) . '.xlsx';

        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        http_response_code(200);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: ' . Response::contentDisposition($name));
        header('Content-Length: ' . strlen($bytes));
        header('Cache-Control: no-store');
        echo $bytes;
    }

    /** 編集した Excel/CSV を取り込み、セグメントへ反映する */
    public function importXlsx(Request $request, array $params): void
    {
        $videoId = (int) $params['id'];
        $v = Videos::find($videoId);
        if (!$v) {
            Response::json(['error' => 'not_found'], 404);
            return;
        }

        $file = $request->file('file');
        if (!$file || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || empty($file['tmp_name'])) {
            Response::json([
                'error'   => 'no_file',
                'message' => 'ファイルが選択されていないか、アップロードに失敗しました。',
            ], 422);
            return;
        }
        if ((int) ($file['size'] ?? 0) > self::MAX_UPLOAD) {
            Response::json([
                'error'   => 'too_large',
                'message' => 'ファイルが大きすぎます（上限20MB）。',
            ], 422);
            return;
        }

        // 「シートに無い字幕を削除して完全に置き換える」フラグ（既定=追加・更新のみ）
        $replace = self::truthy((string) $request->input('replace', ''));

        try {
            $rows = SegmentXlsx::parse((string) $file['tmp_name'], (string) ($file['name'] ?? ''));
        } catch (\Throwable $e) {
            Response::json(['error' => 'parse_failed', 'message' => $e->getMessage()], 422);
            return;
        }
        if (!$rows) {
            Response::json(['error' => 'empty', 'message' => '取り込めるデータがありませんでした。'], 422);
            return;
        }

        try {
            $lang = VideoLangs::resolve($videoId, (string) $request->input('lang', ''));
            $res = Segments::applyImport($videoId, $rows, $replace, Auth::name() ?? '', $lang);
        } catch (\Throwable $e) {
            App::log('字幕インポート失敗: ' . $e->getMessage(), 'error');
            Response::json([
                'error'   => 'apply_failed',
                'message' => '取り込みの反映中にエラーが発生しました。',
            ], 500);
            return;
        }

        $parts = [];
        if ($res['updated'] > 0)  { $parts[] = "更新 {$res['updated']}件"; }
        if ($res['inserted'] > 0) { $parts[] = "追加 {$res['inserted']}件"; }
        if ($res['deleted'] > 0)  { $parts[] = "削除 {$res['deleted']}件"; }
        if ($res['skipped'] > 0)  { $parts[] = "スキップ {$res['skipped']}件"; }
        $summary = $parts ? implode(' / ', $parts) : '変更はありませんでした';

        Response::json([
            'ok'      => true,
            'updated' => $res['updated'],
            'inserted' => $res['inserted'],
            'deleted' => $res['deleted'],
            'skipped' => $res['skipped'],
            'message' => '取り込みました（' . $summary . '）。',
        ]);
    }

    private static function truthy(string $v): bool
    {
        $v = strtolower(trim($v));
        return $v !== '' && $v !== '0' && $v !== 'false' && $v !== 'off' && $v !== 'no';
    }

    /** ダウンロード名のベース（タイトル > 元ファイル名 > video_{id}） */
    private static function baseName(array $v): string
    {
        $base = trim((string) ($v['title'] ?? ''));
        if ($base === '') {
            $base = pathinfo((string) ($v['source_file_name'] ?? ''), PATHINFO_FILENAME);
        }
        if ($base === '') {
            $base = 'video_' . (int) ($v['id'] ?? 0);
        }
        return $base;
    }
}
