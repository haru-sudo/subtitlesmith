<?php
/** @var array $video @var array $segments */

// 初期データ（JSで描画）
$initial = [
    'video' => [
        'id'          => (int) $video['id'],
        'title'       => $video['title'],
        'status'      => $video['status'],
        'duration_ms' => (int) $video['duration_ms'],
        'has_export'  => !empty($video['export_path']),
        'error'       => $video['error_message'],
    ],
    'segments' => array_map(static function (array $s): array {
        return [
            'id'                  => (int) $s['id'],
            'start_ms'            => (int) $s['start_ms'],
            'end_ms'              => (int) $s['end_ms'],
            'source_text'         => (string) $s['source_text'],
            'machine_translation' => $s['machine_translation'],
            'edited_translation'  => $s['edited_translation'],
            'is_approved'         => (bool) $s['is_approved'],
            'approved_by'         => $s['approved_by'] ?? null,
        ];
    }, $segments),
];
?>
<div class="editor-head">
    <div class="editor-head-left">
        <a href="<?= e(url('/')) ?>" class="back-link">&lsaquo; ダッシュボード</a>
        <h1 class="editor-title"><?= e($video['title']) ?></h1>
        <span class="badge status-<?= e($video['status']) ?>" id="statusBadge"><?= e(status_label($video['status'])) ?></span>
        <?php if (!empty($video['translate_provider'])): ?>
            <span class="badge tag" title="翻訳プロバイダ">翻訳: <?= e(\App\TranslateProviders::label((string) $video['translate_provider'])) ?></span>
        <?php endif; ?>
        <?php if (!empty($video['translate_model']) && (empty($video['translate_provider']) || $video['translate_provider'] === 'anthropic')): ?>
            <span class="badge tag" title="翻訳に使用したAIモデル">モデル: <?= e(\App\Models::label((string) $video['translate_model'])) ?></span>
        <?php endif; ?>
        <?php if (!empty($video['transcribe_model'])): ?>
            <span class="badge tag" title="文字起こしに使用したエンジン">文字起こし: <?= e(\App\TranscribeModels::label((string) $video['transcribe_model'])) ?></span>
        <?php endif; ?>
    </div>
    <div class="editor-head-right">
        <span class="save-indicator muted" id="saveIndicator"></span>
        <?php
            // A案: ボタンは常に1つ。状態で挙動が変わる。
            //  - 書き出し済みで最新 -> 既存ファイルを即DL
            //  - 未書き出し or 字幕編集済み(古い) -> 焼き込み直してからDL
            $needsExport = !$hasExport || $exportStale;
        ?>
        <button class="btn primary" id="exportBtn"
                data-needs-export="<?= $needsExport ? '1' : '0' ?>"
                data-has-export="<?= $hasExport ? '1' : '0' ?>"
                title="<?= $needsExport
                    ? '現在の字幕を映像に焼き込み、完了したらダウンロードします（数分かかることがあります）'
                    : '焼き込み済みのMP4をダウンロードします' ?>">
            <?= $needsExport ? '字幕を焼き込んでDL' : '焼き込み動画をDL' ?>
        </button>
        <a class="btn" id="vttDlBtn"
           href="<?= e(url('/videos/' . (int) $video['id'] . '/subtitles.vtt?dl=1&lang=' . rawurlencode($targetLang))) ?>"
           download
           title="One-Streamなどにアップロードできる字幕ファイル（WebVTT）をダウンロードします"
           <?= !empty($segments) ? '' : 'hidden' ?>>字幕(VTT)をDL</a>
        <a class="btn" id="xlsxDlBtn"
           href="<?= e(url('/videos/' . (int) $video['id'] . '/segments.xlsx?lang=' . rawurlencode($targetLang))) ?>"
           download
           title="AIが生成した字幕（原文・機械翻訳・編集訳）をExcelで書き出します。翻訳の一括編集に使えます。"
           <?= !empty($segments) ? '' : 'hidden' ?>>字幕(Excel)を書き出し</a>
        <button class="btn" id="xlsxImportBtn"
                title="Excel/CSVで編集した字幕を取り込みます。ID列で元の行に対応付けて更新し、ID空欄の行は追加します。"
                <?= !empty($segments) ? '' : 'hidden' ?>>Excelから取込</button>
        <label class="inline-check" id="xlsxReplaceWrap"
               title="チェックすると、取り込むシートに載っていない既存の字幕を削除して、シートの内容で完全に置き換えます。"
               <?= !empty($segments) ? '' : 'hidden' ?>>
            <input type="checkbox" id="xlsxReplaceChk"> 取込時に完全置換
        </label>
        <input type="file" id="xlsxImportFile" accept=".xlsx,.csv" hidden>
        <a class="btn" id="sourceDlBtn"
           href="<?= e(url('/videos/' . (int) $video['id'] . '/source/download')) ?>"
           download
           title="字幕を焼き込んでいないオリジナルの元動画をダウンロードします"
           <?= !empty($video['file_path']) ? '' : 'hidden' ?>>元動画をDL</a>
        <button class="btn danger" id="deleteVideoBtn" title="この動画と字幕・書き出し物をすべて削除します">動画を削除</button>
    </div>
</div>

<?php $isCatalog = ($video['status'] === 'catalog'); ?>
<?php if (!empty($_GET['error'])): ?>
    <p class="alert"><?= e($_GET['error']) ?></p>
<?php endif; ?>

<!-- メタ情報の編集（動画リスト管理） -->
<details class="meta-edit" <?= $isCatalog ? 'open' : '' ?>>
    <summary>動画情報を編集</summary>
    <form method="post" action="<?= e(url('/videos/' . (int) $video['id'] . '/meta')) ?>" class="meta-form">
        <label>タイトル
            <input type="text" name="title" value="<?= e($video['title']) ?>">
        </label>
        <div class="meta-row">
            <label>工程
                <select name="stage">
                    <?php foreach (['' => '(なし)', '完成品' => '完成品', '仕掛品' => '仕掛品', '翻訳素材' => '翻訳素材'] as $val => $lbl): ?>
                        <option value="<?= e($val) ?>" <?= ((string) $video['stage'] === $val) ? 'selected' : '' ?>><?= e($lbl) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>形式 <input type="text" name="format_label" value="<?= e($video['format_label']) ?>"></label>
            <label>年月 <input type="text" name="period" value="<?= e($video['period']) ?>"></label>
            <label>分数 <input type="text" name="duration_label" value="<?= e($video['duration_label']) ?>"></label>
        </div>
        <label>状態メモ
            <input type="text" name="status_note" value="<?= e($video['status_note']) ?>">
        </label>
        <label>内容
            <textarea name="description" rows="2"><?= e($video['description']) ?></textarea>
        </label>
        <?php if (!empty($video['source_file_name'])): ?>
            <p class="muted">元ファイル名: <?= e($video['source_file_name']) ?></p>
        <?php endif; ?>
        <div><button type="submit" class="btn">情報を保存</button></div>
    </form>
</details>

<!-- カタログ(ファイル未添付): 動画をアップロードして字幕生成を開始 -->
<div id="catalogPanel" class="catalog-panel" <?= $isCatalog ? '' : 'hidden' ?>>
    <h2>動画ファイルを追加</h2>
    <p class="muted">この動画はまだファイルが未添付です。ファイルをアップロードすると文字起こしと翻訳が始まります。</p>
    <form method="post" action="<?= e(url('/videos/' . (int) $video['id'] . '/attach')) ?>" enctype="multipart/form-data" class="stack">
        <input type="file" name="video" accept="video/*" required>
        <?= \App\View::renderPartial('partials/target_lang_select', [
            'targetLangs' => $targetLangs,
            'currentTargetLang' => $currentTargetLang,
        ]) ?>
        <?= \App\View::renderPartial('partials/provider_select', [
            'providers' => $providers,
            'currentProvider' => $currentProvider,
            'modelChoices' => $modelChoices,
            'defaultModel' => $defaultModel,
            'defaultGeminiModel' => $defaultGeminiModel,
            'defaultCompatModel' => $defaultCompatModel,
            'uid' => 'attach',
        ]) ?>
        <?= \App\View::renderPartial('partials/transcribe_select', [
            'transcribeModels' => $transcribeModels,
            'defaultTranscribe' => $defaultTranscribe,
        ]) ?>
        <div><button type="submit" class="btn primary">アップロードして開始</button></div>
    </form>
</div>

<!-- 処理中/エラー パネル -->
<div id="processingPanel" class="processing-panel" hidden>
    <div class="spinner"></div>
    <p id="processingText">処理中です…</p>
    <p class="muted" id="processingSub"></p>

    <!-- 進行バー（段階＋パーセント） -->
    <div id="progressWrap" class="progress-wrap" hidden>
        <div class="progress-track"><div id="progressBar" class="progress-bar" style="width:0%"></div></div>
        <p class="muted progress-label" id="progressLabel"></p>
    </div>

    <!-- ローカル文字起こし/翻訳時のサーバー負荷 -->
    <div id="loadWrap" class="load-wrap" hidden>
        <p class="load-title muted" id="loadTitle">サーバー負荷（ローカル処理中）</p>
        <div class="load-row">
            <span class="load-name">CPU</span>
            <div class="progress-track sm"><div id="cpuBar" class="progress-bar cpu" style="width:0%"></div></div>
            <span class="load-val" id="cpuVal">—</span>
        </div>
        <div class="load-row">
            <span class="load-name">メモリ</span>
            <div class="progress-track sm"><div id="memBar" class="progress-bar mem" style="width:0%"></div></div>
            <span class="load-val" id="memVal">—</span>
        </div>
        <p class="muted load-extra" id="loadExtra"></p>
        <p class="muted proc-status idle" id="procStatus"></p>
    </div>

    <p class="muted">アップロード後、自動で文字起こしと翻訳が進みます。完了すると編集できるようになります。</p>
</div>

<!-- 編集本体 -->
<div id="editorMain" class="editor-main" hidden>
    <div class="video-panel">
        <video id="player" controls preload="metadata" playsinline>
            <source src="<?= e(url('/storage/' . ltrim((string) $video['file_path'], '/'))) ?>">
            <track id="subTrack" kind="subtitles" srclang="<?= e($targetLangTag) ?>" label="<?= e($targetLangLabel) ?>" default
                   src="<?= e(url('/videos/' . (int) $video['id'] . '/subtitles.vtt')) ?>">
        </video>
        <div class="player-hint muted">行をクリックするとその位置へ移動します。</div>

        <!-- 動画編集（区間カット） -->
        <details class="cut-panel" id="cutPanel">
            <summary>動画をカット（区間削除）</summary>
            <p class="muted cut-help">
                指定した区間の<strong>映像・音声・字幕をまとめて削除</strong>します。削除後は、その先のセグメントの時刻が削除した分だけ繰り上がります。
                再生位置から「開始」「終了」をセットできます。<strong>元に戻せません。</strong>
            </p>
            <div class="cut-row">
                <label class="cut-field">
                    <span>開始</span>
                    <input type="text" id="cutStart" class="time-input" placeholder="00:00.000" value="00:00.000">
                </label>
                <button type="button" class="btn sm" id="cutSetStart" title="今の再生位置を開始にセット">現在位置を開始に</button>
                <span class="time-arrow">→</span>
                <label class="cut-field">
                    <span>終了</span>
                    <input type="text" id="cutEnd" class="time-input" placeholder="00:00.000" value="00:00.000">
                </label>
                <button type="button" class="btn sm" id="cutSetEnd" title="今の再生位置を終了にセット">現在位置を終了に</button>
            </div>
            <div class="cut-actions">
                <button type="button" class="btn sm" id="cutPreview" title="カットする区間だけを再生して確認します">区間を試聴</button>
                <button type="button" class="btn danger" id="cutRun" title="この区間を映像・字幕から削除します（数分かかることがあります）">この区間を削除</button>
                <span class="muted" id="cutInfo"></span>
            </div>
        </details>
    </div>

    <div class="segments-panel">
        <!-- 字幕言語の切り替え（同じ動画で複数言語の字幕を持てる） -->
        <div class="lang-tabs" id="langTabs" data-current="<?= e($targetLang) ?>">
            <?php foreach ($videoLangs as $l): ?>
                <button type="button"
                        class="lang-tab <?= $l['lang'] === $targetLang ? 'active' : '' ?>"
                        data-lang="<?= e($l['lang']) ?>"
                        data-status="<?= e($l['status']) ?>"
                        title="<?= $l['primary'] ? '主言語（最初に作った言語）' : 'この言語の字幕に切り替えます' ?>">
                    <?= e($l['label']) ?>
                    <?php if ($l['status'] === 'translating'): ?><span class="lang-tab-note">翻訳中…</span>
                    <?php elseif ($l['status'] === 'error'): ?><span class="lang-tab-note err">失敗</span>
                    <?php elseif ($l['status'] === 'pending'): ?><span class="lang-tab-note">未翻訳</span><?php endif; ?>
                </button>
            <?php endforeach; ?>
            <?php if (!empty($addableLangs)): ?>
                <div class="lang-add">
                    <select id="addLangSelect" title="この動画に追加する言語">
                        <option value="">＋ 言語を追加</option>
                        <?php foreach ($addableLangs as $c): ?>
                            <option value="<?= e($c['id']) ?>"><?= e($c['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" class="btn sm" id="addLangBtn"
                            title="選んだ言語へ、この動画の字幕を翻訳します（文字起こしは再利用します。翻訳のAPIコストが言語ぶんかかります）">追加</button>
                </div>
            <?php endif; ?>
            <?php
            // 主言語は削除できない（この動画の基準になる言語なので）
            $curIsPrimary = true;
            foreach ($videoLangs as $l) {
                if ($l['lang'] === $targetLang) {
                    $curIsPrimary = !empty($l['primary']);
                }
            }
            $canRemove = (count($videoLangs) > 1 && !$curIsPrimary);
            ?>
            <button type="button" class="btn sm danger lang-del" id="removeLangBtn"
                    title="表示中の言語の字幕を削除します" <?= $canRemove ? '' : 'hidden' ?>>この言語を削除</button>
        </div>
        <?php if (count($videoLangs) > 1): ?>
            <p class="muted lang-note">原文と時刻はすべての言語で共通です。ここを直すと他の言語の字幕にも反映されます（訳文と確認済みは言語ごと）。</p>
        <?php endif; ?>

        <div class="segments-toolbar">
            <span class="muted" id="segCount"></span>
            <span class="muted line-limit-hint"
                  title="この動画に焼き込むと、1行がこの長さを超えたところで自動的に改行されます（全角=1文字・半角=0.5文字換算）">
                1行の上限: 全角<?= (int) $maxLineChars ?>文字（半角なら<?= (int) $maxLineChars * 2 ?>文字）
            </span>
            <span class="muted kbd-hint" id="kbdHint" title="キーボードショートカット"></span>
            <?php if (!in_array((string) ($video['source_lang'] ?? ''), ['', 'auto'], true)): ?>
                <button class="btn" id="glossaryAiBtn"
                        title="編集済みの字幕（原文と訳）からAIで専門用語を抽出し、用語集に登録します（APIコストがかかります）">
                    用語集にAIで登録
                </button>
            <?php endif; ?>
        </div>
        <div id="segmentList" class="segment-list"></div>
    </div>
</div>

<script type="application/json" id="initialData"><?= json_encode($initial, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<script>
window.APP_VIDEO_ID = <?= (int) $video['id'] ?>;
window.APP_LOCAL_TRANSCRIBE = <?= !empty($isLocalTranscribe) ? 'true' : 'false' ?>;
window.APP_LOCAL_TRANSLATE = <?= !empty($isLocalTranslate) ? 'true' : 'false' ?>;
/* 字幕の出力言語と、焼き込み時に自動改行される1行の上限文字数（全角換算） */
window.APP_TARGET_LANG = <?= json_encode($targetLang, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
window.APP_TARGET_LANG_LABEL = <?= json_encode($targetLangLabel, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
window.APP_TARGET_LANG_TAG = <?= json_encode($targetLangTag, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
window.APP_MAX_LINE_CHARS = <?= (int) $maxLineChars ?>;
/* 字幕の最低表示時間（秒）[2行以上, 1行]・次の字幕とのすき間(ms)。Subtitles::timedCues と同じ規則で警告する */
window.APP_MIN_DISPLAY_SEC = <?= json_encode(array_values($minDisplaySec ?? [5.0, 2.5])) ?>;
window.APP_MIN_GAP_MS = <?= (int) \App\Subtitles::MIN_GAP_MS ?>;
window.APP_VIDEO_LANGS = <?= json_encode($videoLangs, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
</script>
<script src="<?= e(asset('js/editor.js')) ?>"></script>
<script>
/* 添付パネルのプロバイダ/モデル選択（共通処理を利用） */
window.APP_COMMON.setupProviderSelect('attach');
window.APP_COMMON.setupRadioHighlights(document.getElementById('catalogPanel'));
</script>
