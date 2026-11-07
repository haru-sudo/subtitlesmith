<?php

declare(strict_types=1);

namespace App\Controllers;

use App\App;
use App\Auth;
use App\Http\Request;
use App\Http\Response;
use App\Repo\Folders;
use App\Repo\Videos;
use App\View;

final class DashboardController
{
    public function index(Request $request): void
    {
        // 未ログインは公開ホームページ（アプリ説明）を表示（OAuth審査要件）
        if (!Auth::check()) {
            Response::html(View::render('landing', [], (string) App::config('app_name')));
            return;
        }

        $folderId = $request->input('folder');
        $folderId = ($folderId === null || $folderId === '' || (int) $folderId === 0) ? null : (int) $folderId;

        // 指定フォルダが存在しなければルートへ
        if ($folderId !== null && !Folders::find($folderId)) {
            Response::redirect('/');
            return;
        }

        $videos = Videos::inFolder($folderId);

        Response::html(View::render('dashboard', [
            'currentFolderId' => $folderId,
            'breadcrumb'      => Folders::breadcrumb($folderId),
            'subfolders'      => Folders::children($folderId),
            'videos'          => $videos,
            // 一覧のカードに「この動画が持つ言語」を出すためのマップ（video_id => [言語ID]）
            'videoLangMap'    => \App\Repo\VideoLangs::codesForVideos(array_column($videos, 'id')),
            'folderOptions'   => Folders::optionList(),
            'models'          => \App\Models::list(),
            'defaultModel'    => \App\Models::default(),
            'transcribeModels'  => \App\TranscribeModels::list(),
            'defaultTranscribe' => \App\TranscribeModels::default(),
            'providers'         => \App\TranslateProviders::choices(),
            'currentProvider'   => \App\TranslateProviders::current(),
            'modelChoices'      => \App\TranslateProviders::modelChoices(),
            'defaultGeminiModel' => (string) \App\Settings::get('gemini_model', 'gemini-2.5-flash'),
            'defaultCompatModel' => (string) \App\Settings::get('openai_compat_model', ''),
            'targetLangs'        => \App\TargetLangs::choices(),
            'currentTargetLang'  => \App\TargetLangs::current(),
        ], 'ダッシュボード'));
    }
}
