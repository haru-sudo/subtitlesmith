<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Announcements;
use App\Http\Request;
use App\Http\Response;
use App\View;

/**
 * お知らせ（更新情報）ページ。
 */
final class NewsController
{
    public function index(Request $request): void
    {
        Response::html(View::render('news', [
            'items' => Announcements::all(),
        ], 'お知らせ'));
    }
}
