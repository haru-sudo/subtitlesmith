<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\Request;
use App\Http\Response;
use App\Repo\Tm;
use App\View;

final class TmController
{
    private const PER_PAGE = 100;

    public function index(Request $request): void
    {
        $page = max(1, (int) $request->input('page', 1));
        $offset = ($page - 1) * self::PER_PAGE;
        $total = Tm::count();
        Response::html(View::render('memory', [
            'entries'  => Tm::page(self::PER_PAGE, $offset),
            'total'    => $total,
            'page'     => $page,
            'perPage'  => self::PER_PAGE,
            'lastPage' => max(1, (int) ceil($total / self::PER_PAGE)),
        ], '翻訳メモリ'));
    }

    public function destroy(Request $request, array $params): void
    {
        Tm::delete((int) $params['id']);
        Response::redirect('/memory');
    }
}
