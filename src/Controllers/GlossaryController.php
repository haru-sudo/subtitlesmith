<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\Request;
use App\Http\Response;
use App\Repo\Glossary;
use App\Repo\GlossaryCandidates;
use App\View;

final class GlossaryController
{

    public function index(Request $request): void
    {
        Response::html(View::render('glossary', [
            'entries'     => Glossary::all(),
            'candidates'  => GlossaryCandidates::pending(),
            'targetLangs' => \App\TargetLangs::choices(),
            'defaultTargetLang' => \App\TargetLangs::current(),
        ], '用語集'));
    }

    /** 訳語の言語（未指定/不正は設定の既定） */
    private static function targetLang(Request $request, ?string $fallback = null): string
    {
        $t = (string) $request->input('target_lang', '');
        if (\App\TargetLangs::isValid($t)) {
            return $t;
        }
        return $fallback !== null ? \App\TargetLangs::normalize($fallback) : \App\TargetLangs::current();
    }

    public function store(Request $request): void
    {
        $source = trim((string) $request->input('source_term', ''));
        $target = trim((string) $request->input('target_term', ''));
        $lang = (string) $request->input('source_lang', 'en');
        if ($source === '' || $target === '') {
            Response::redirect('/glossary');
            return;
        }
        Glossary::create([
            'source_term' => $source,
            'source_lang' => \App\TargetLangs::isSourceValid($lang) ? $lang : 'auto',
            'target_lang' => self::targetLang($request),
            'target_term' => $target,
            'note'        => trim((string) $request->input('note', '')),
            'origin'      => 'curated',
        ]);
        Response::redirect('/glossary');
    }

    public function update(Request $request, array $params): void
    {
        $id = (int) $params['id'];
        if (!Glossary::find($id)) {
            Response::notFound();
            return;
        }
        $source = trim((string) $request->input('source_term', ''));
        $target = trim((string) $request->input('target_term', ''));
        $lang = (string) $request->input('source_lang', 'en');
        if ($source === '' || $target === '') {
            Response::redirect('/glossary');
            return;
        }
        Glossary::update($id, [
            'source_term' => $source,
            'source_lang' => \App\TargetLangs::isSourceValid($lang) ? $lang : 'auto',
            'target_lang' => self::targetLang($request),
            'target_term' => $target,
            'note'        => trim((string) $request->input('note', '')),
        ]);
        Response::redirect('/glossary');
    }

    public function destroy(Request $request, array $params): void
    {
        Glossary::delete((int) $params['id']);
        Response::redirect('/glossary');
    }

    /** 候補を承認 → 用語集に curated として登録し、候補を accepted にする。 */
    public function approveCandidate(Request $request, array $params): void
    {
        $id = (int) $params['id'];
        $cand = GlossaryCandidates::find($id);
        if (!$cand) {
            Response::notFound();
            return;
        }
        // 承認時は人が入力した訳語を採用（候補の target_term は空のことが多い）
        $source = trim((string) $request->input('source_term', (string) $cand['source_term']));
        $target = trim((string) $request->input('target_term', ''));
        $lang = (string) $request->input('source_lang', (string) $cand['source_lang']);
        if ($source === '' || $target === '') {
            // 訳語未入力では昇格しない（候補は残す）
            Response::redirect('/glossary');
            return;
        }
        Glossary::create([
            'source_term' => $source,
            'source_lang' => \App\TargetLangs::isSourceValid($lang) ? $lang : 'auto',
            'target_lang' => self::targetLang($request),
            'target_term' => $target,
            'note'        => trim((string) $request->input('note', '')),
            'origin'      => 'mined',
        ]);
        GlossaryCandidates::setStatus($id, 'accepted');
        Response::redirect('/glossary');
    }

    /** 候補を却下（今後の再提案もしない）。 */
    public function rejectCandidate(Request $request, array $params): void
    {
        $id = (int) $params['id'];
        if (!GlossaryCandidates::find($id)) {
            Response::notFound();
            return;
        }
        GlossaryCandidates::setStatus($id, 'rejected');
        Response::redirect('/glossary');
    }
}
