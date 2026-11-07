<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\Request;
use App\Http\Response;
use App\Repo\Segments;
use App\Repo\Videos;
use App\View;

final class EditorController
{
    public function show(Request $request, array $params): void
    {
        $v = Videos::find((int) $params['id']);
        if (!$v) {
            Response::notFound('動画が見つかりません');
            return;
        }
        $videoId = (int) $v['id'];
        // この動画が持つ言語（無ければ主言語ぶんを1件作る＝古いデータの保険）
        if (!\App\Repo\VideoLangs::forVideo($videoId)) {
            \App\Repo\VideoLangs::add($videoId, \App\Repo\VideoLangs::primaryLang($videoId), 'ready');
        }
        $langRows = \App\Repo\VideoLangs::forVideo($videoId);
        // 表示中の言語（?lang= 指定 → 主言語）
        $current = \App\Repo\VideoLangs::resolve($videoId, (string) $request->input('lang', ''));

        $segments = Segments::forVideo($videoId, $current);
        Response::html(View::render('editor', [
            'video'    => $v,
            'segments' => $segments,
            'targetLang'      => $current,
            'targetLangLabel' => \App\TargetLangs::label($current),
            'targetLangTag'   => \App\TargetLangs::vttTag($current),
            'targetLangs'     => \App\TargetLangs::choices(),
            // 言語タブ用（この動画が持つ言語＋言語ごとの書き出し状態）
            'videoLangs'      => array_map(static function (array $l) use ($videoId): array {
                $lang = (string) $l['target_lang'];
                return [
                    'lang'         => $lang,
                    'label'        => \App\TargetLangs::label($lang),
                    'tag'          => \App\TargetLangs::vttTag($lang),
                    'status'       => (string) $l['status'],
                    'has_export'   => !empty($l['export_path']),
                    'export_stale' => \App\Repo\VideoLangs::isExportStale($videoId, $lang),
                    'primary'      => ($lang === \App\Repo\VideoLangs::primaryLang($videoId)),
                ];
            }, $langRows),
            // まだ追加していない言語（「言語を追加」の選択肢）
            'addableLangs'    => array_values(array_filter(
                \App\TargetLangs::choices(),
                static fn(array $c) => !\App\Repo\VideoLangs::has($videoId, $c['id'])
            )),
            // 焼き込み時に自動改行される1行の上限文字数（全角換算）。編集画面の警告に使う。
            'maxLineChars'    => self::maxLineChars($v),
            'minDisplaySec'   => \App\Subtitles::minDisplaySec(),
            'models'       => \App\Models::list(),
            'defaultModel' => (!empty($v['translate_model']) && \App\Models::isValid((string) $v['translate_model']))
                ? (string) $v['translate_model'] : \App\Models::default(),
            'transcribeModels'  => \App\TranscribeModels::list(),
            'defaultTranscribe' => (!empty($v['transcribe_model']) && \App\TranscribeModels::isValid((string) $v['transcribe_model']))
                ? (string) $v['transcribe_model'] : \App\TranscribeModels::default(),
            'providers'         => \App\TranslateProviders::choices(),
            'currentProvider'   => (!empty($v['translate_provider']) && \App\TranslateProviders::isValid((string) $v['translate_provider']))
                ? (string) $v['translate_provider'] : \App\TranslateProviders::current(),
            'modelChoices'      => \App\TranslateProviders::modelChoices(),
            'defaultGeminiModel' => (string) \App\Settings::get('gemini_model', 'gemini-2.5-flash'),
            'defaultCompatModel' => (string) \App\Settings::get('openai_compat_model', ''),
            'currentTargetLang'  => \App\TargetLangs::normalize($v['target_lang'] ?? null),
            // 表示中の言語の書き出し状態（言語を切り替えるとJS側で更新する）
            'hasExport'    => !empty(\App\Repo\VideoLangs::find($videoId, $current)['export_path'] ?? ''),
            'exportStale'  => \App\Repo\VideoLangs::isExportStale($videoId, $current),
            'isLocalTranscribe' => \App\TranscribeModels::isLocal(
                (!empty($v['transcribe_model']) && \App\TranscribeModels::isValid((string) $v['transcribe_model']))
                    ? (string) $v['transcribe_model'] : \App\TranscribeModels::default()
            ),
            'isLocalTranslate' => self::isLocalTranslate($v),
        ], $v['title'], 'container editor'));
    }

    /**
     * この動画を焼き込んだときに1行へ収まる文字数（全角換算）。
     * 映像サイズは取り込み時に控えてあるが、古い動画には無いので、その場合だけ
     * ffprobeで測って保存する（次回以降は再計測しない）。取れなければ既定サイズで計算する。
     */
    private static function maxLineChars(array $v): int
    {
        $w = (int) ($v['video_width'] ?? 0);
        $h = (int) ($v['video_height'] ?? 0);

        if (($w <= 0 || $h <= 0) && !empty($v['file_path'])) {
            $abs = \App\App::storagePath((string) $v['file_path']);
            if (is_file($abs)) {
                [$w, $h] = \App\Media::probeDimensions($abs);
                Videos::update((int) $v['id'], ['video_width' => $w, 'video_height' => $h]);
            }
        }
        if ($w <= 0 || $h <= 0) {
            return \App\Subtitles::maxLineChars();   // 既定(1280x720)で概算
        }
        return \App\Subtitles::maxLineChars($w, $h);
    }

    /**
     * 翻訳がサーバー上のローカル処理か判定（負荷パネルの表示用）。
     *  - プロバイダ=ローカルLLM → true（いずれもサーバー上で実行）
     */
    private static function isLocalTranslate(array $v): bool
    {
        $prov = (!empty($v['translate_provider']) && \App\TranslateProviders::isValid((string) $v['translate_provider']))
            ? (string) $v['translate_provider'] : \App\TranslateProviders::current();
        return $prov === \App\TranslateProviders::LOCAL_LLM;
    }

    /** メタ情報（タイトル・工程・状態・内容など）の更新 */
    public function updateMeta(Request $request, array $params): void
    {
        $id = (int) $params['id'];
        if (!Videos::find($id)) {
            Response::notFound();
            return;
        }
        $data = [];
        foreach (['title', 'stage', 'status_note', 'description', 'period', 'format_label', 'duration_label'] as $f) {
            $val = $request->input($f);
            if ($val !== null) {
                $data[$f] = trim((string) $val);
            }
        }
        if (isset($data['title']) && $data['title'] === '') {
            unset($data['title']); // タイトル空は無視
        }
        if ($data) {
            Videos::update($id, $data);
        }
        Response::redirect('/videos/' . $id);
    }
}
