<?php

declare(strict_types=1);

/**
 * フロントコントローラ（全リクエストの入口）。
 */

// src/bootstrap.php の場所を構成に依らず解決する:
//  - 通常配置: index.php は public/ 内 → ../src/bootstrap.php
//  - フラット配置: index.php と src/ が同じフォルダ → ./src/bootstrap.php
$__bootstrap = null;
foreach ([dirname(__DIR__) . '/src/bootstrap.php', __DIR__ . '/src/bootstrap.php'] as $__cand) {
    if (is_file($__cand)) {
        $__bootstrap = $__cand;
        break;
    }
}
if ($__bootstrap === null) {
    http_response_code(500);
    exit('src/bootstrap.php が見つかりません。配置（src フォルダの場所）を確認してください。');
}
require $__bootstrap;

use App\Auth;
use App\Http\Request;
use App\Router;
use App\Controllers\AuthController;
use App\Controllers\DashboardController;
use App\Controllers\UploadController;
use App\Controllers\MediaController;
use App\Controllers\EditorController;
use App\Controllers\SegmentIoController;
use App\Controllers\ApiController;
use App\Controllers\GlossaryController;
use App\Controllers\TmController;
use App\Controllers\FolderController;
use App\Controllers\HealthController;
use App\Http\Response;

Auth::startSession();

// 検索エンジンにインデックスさせない
header('X-Robots-Tag: noindex, nofollow');

$request = Request::capture();
$router  = new Router();

// ---- 認証（OpenID Connect / パスワード + 許可リスト） ----
$auth = new AuthController();
$router->get('/login', [$auth, 'showLogin'], auth: false);
$router->post('/login', [$auth, 'passwordLogin'], auth: false);
$router->get('/auth/oidc/{provider}', [$auth, 'oidcStart'], auth: false);
$router->get('/auth/oidc/{provider}/callback', [$auth, 'oidcCallback'], auth: false);
$router->get('/auth/google', [$auth, 'googleStart'], auth: false);                 // 旧URL互換
$router->get('/auth/google/callback', [$auth, 'googleCallback'], auth: false);     // 旧URL互換
$router->post('/logout', [$auth, 'logout'], auth: false);
$router->get('/logout', [$auth, 'logout'], auth: false);

// ---- ダッシュボード / 公開ホームページ ----
$dashboard = new DashboardController();
$router->get('/', [$dashboard, 'index'], auth: false); // 未ログインは公開LP, ログイン済みはダッシュボード

// 公開ページ（プライバシーポリシー）
$router->get('/privacy', function (Request $request): void {
    Response::html(\App\View::render('privacy', [], 'プライバシーポリシー'));
}, auth: false);

// ---- アップロード / 動画状態 ----
$upload = new UploadController();
$router->post('/videos', [$upload, 'store']);                 // 新規作成（単発アップロード）
$router->post('/videos/chunk', [$upload, 'storeChunk']);      // 新規作成（分割アップロード受領）

$api = new ApiController();
$router->get('/api/videos', [$api, 'listVideos']);            // 一覧（ポーリング用）
$router->post('/api/spawn-worker', [$api, 'spawnWorker']);    // 保留処理の自動再開（冪等）
$router->get('/api/system-load', [$api, 'systemLoad']);       // サーバー負荷（ローカル文字起こし監視）
$router->get('/api/videos/{id}', [$api, 'showVideo']);        // 1件の状態
$router->get('/api/videos/{id}/segments', [$api, 'segments']);
$router->post('/api/videos/{id}/segments', [$api, 'createSegment']);   // 削除の取り消し(⌘Z)で作り直す
$router->put('/api/videos/{id}/segments/{sid}', [$api, 'updateSegment']);
$router->post('/api/videos/{id}/segments/{sid}/split', [$api, 'splitSegment']);
$router->delete('/api/videos/{id}/segments/{sid}', [$api, 'deleteSegment']);
$router->post('/api/videos/{id}/langs', [$api, 'addLang']);            // 字幕言語を追加して翻訳
$router->delete('/api/videos/{id}/langs/{lang}', [$api, 'deleteLang']); // 字幕言語を削除
$router->post('/api/videos/{id}/export', [$api, 'requestExport']);
$router->post('/api/videos/{id}/cut', [$api, 'cutVideo']);          // 動画編集（区間カット）
$router->post('/api/videos/{id}/glossary-ai', [$api, 'buildGlossary']); // 編集済み字幕から用語集をAI登録
$router->put('/api/videos/{id}/folder', [$api, 'moveVideo']);
$router->delete('/api/videos/{id}', [$api, 'deleteVideo']);

// ---- フォルダ ----
$folder = new FolderController();
$router->post('/folders', [$folder, 'store']);
$router->post('/folders/{id}/rename', [$folder, 'rename']);
$router->post('/folders/{id}/delete', [$folder, 'destroy']);

// ---- メディア配信（認証付き・storageは非公開） ----
$media = new MediaController();
$router->get('/videos/{id}/thumbnail', [$media, 'thumbnail']);
$router->get('/videos/{id}/stream', [$media, 'stream']);
$router->get('/videos/{id}/subtitles.vtt', [$media, 'vtt']);
$router->get('/videos/{id}/source/download', [$media, 'source']); // 字幕なしの元動画
$router->get('/videos/{id}/download', [$media, 'download']);  // 焼き込み済みMP4

// ---- 字幕の Excel 書き出し / 取り込み ----
$segio = new SegmentIoController();
$router->get('/videos/{id}/segments.xlsx', [$segio, 'exportXlsx']);   // AI字幕をExcelで書き出し
$router->post('/videos/{id}/segments/import', [$segio, 'importXlsx']); // 編集したExcel/CSVを取り込み

// ---- 編集画面 / 動画メタ・添付 ----
$editor = new EditorController();
$router->get('/videos/{id}', [$editor, 'show']);
$router->post('/videos/{id}/meta', [$editor, 'updateMeta']);     // メタ情報の更新
$router->post('/videos/{id}/attach', [$upload, 'attach']);       // カタログにファイル添付して開始

// ---- 用語集 / 翻訳メモリ（学習データ管理） ----
$glossary = new GlossaryController();
$router->get('/glossary', [$glossary, 'index']);
$router->post('/glossary', [$glossary, 'store']);
$router->post('/glossary/candidates/{id}/approve', [$glossary, 'approveCandidate']);
$router->post('/glossary/candidates/{id}/reject', [$glossary, 'rejectCandidate']);
$router->post('/glossary/{id}', [$glossary, 'update']);
$router->post('/glossary/{id}/delete', [$glossary, 'destroy']);

$tm = new TmController();
$router->get('/memory', [$tm, 'index']);
$router->post('/memory/{id}/delete', [$tm, 'destroy']);

// ---- お知らせ（更新情報） ----
$news = new \App\Controllers\NewsController();
$router->get('/news', [$news, 'index']);

// ---- 環境チェック ----
$health = new HealthController();
$router->get('/health', [$health, 'index']);

// ---- 設定（APIキー・既定モデル） ----
$settings = new \App\Controllers\SettingsController();
$router->get('/settings', [$settings, 'index']);
$router->post('/settings', [$settings, 'save']);

$router->dispatch($request);
