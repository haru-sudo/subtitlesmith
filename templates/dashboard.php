<?php
/** @var array $videos @var array $subfolders @var array $breadcrumb @var array $folderOptions @var int|null $currentFolderId */
$cur = $currentFolderId;
// 絵文字の代わりに使うインラインSVG（環境を選ばない）
$icoFolder = '<svg class="ico" viewBox="0 0 16 16" width="14" height="14" fill="currentColor" aria-hidden="true"><path d="M1.5 3.5A1.5 1.5 0 0 1 3 2h3.2l1.6 1.8H13A1.5 1.5 0 0 1 14.5 5.3v6.2A1.5 1.5 0 0 1 13 13H3a1.5 1.5 0 0 1-1.5-1.5z"/></svg>';
$icoFilm = '<svg class="ico" viewBox="0 0 24 24" width="34" height="34" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><rect x="2.5" y="4.5" width="19" height="15" rx="2"/><path d="M6.5 4.5v15M17.5 4.5v15M2.5 9h4M2.5 15h4M17.5 9h4M17.5 15h4M10 9.2v5.6l4.5-2.8z"/></svg>';
$icoTrash = '<svg class="ico" viewBox="0 0 16 16" width="13" height="13" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M2.5 4.5h11M5.5 4.5v-2h5v2M4 4.5l.8 9h6.4l.8-9M6.5 7v4M9.5 7v4"/></svg>';
?>
<div class="page-head">
    <div class="breadcrumb">
        <a href="<?= e(url('/')) ?>">ホーム</a>
        <?php foreach ($breadcrumb as $b): ?>
            <span class="sep">/</span>
            <a href="<?= e(url('/?folder=' . (int) $b['id'])) ?>"><?= e($b['name']) ?></a>
        <?php endforeach; ?>
    </div>
    <div class="head-actions">
        <button class="btn" id="newFolderBtn">＋ フォルダ</button>
        <button class="btn primary" id="newBtn">＋ 新規作成</button>
    </div>
</div>

<!-- サブフォルダ -->
<?php if (!empty($subfolders)): ?>
    <div class="folder-grid">
        <?php foreach ($subfolders as $f): ?>
            <div class="folder-tile">
                <a class="folder-link" href="<?= e(url('/?folder=' . (int) $f['id'])) ?>"><?= $icoFolder ?> <?= e($f['name']) ?></a>
                <div class="folder-actions">
                    <button type="button" class="link-btn"
                            data-rename="<?= (int) $f['id'] ?>" data-name="<?= e($f['name']) ?>">名前変更</button>
                    <form method="post" action="<?= e(url('/folders/' . (int) $f['id'] . '/delete')) ?>" class="inline"
                          onsubmit="return confirm('フォルダ「<?= e($f['name']) ?>」を削除しますか？(中の動画はルートに戻ります)');">
                        <button type="submit" class="link-btn danger">削除</button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- 動画 -->
<div id="videoGrid" class="video-grid">
    <?php if (empty($videos) && empty($subfolders)): ?>
        <p class="empty-state muted">ここには何もありません。「＋ 新規作成」で動画を追加、「＋ フォルダ」で整理できます。</p>
    <?php elseif (empty($videos)): ?>
        <p class="empty-state muted">このフォルダに動画はありません。</p>
    <?php else: ?>
        <?php foreach ($videos as $v): ?>
            <div class="card" data-video-id="<?= (int) $v['id'] ?>">
                <a class="card-link" href="<?= e(url('/videos/' . (int) $v['id'])) ?>">
                    <div class="thumb">
                        <?php if (!empty($v['thumbnail_path'])): ?>
                            <img src="<?= e(url('/videos/' . (int) $v['id'] . '/thumbnail')) ?>" alt="" loading="lazy" decoding="async">
                        <?php else: ?>
                            <span class="thumb-placeholder"><?= $icoFilm ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="card-body">
                        <div class="card-title"><?= e($v['title']) ?></div>
                        <div class="card-meta">
                            <span class="badge status-<?= e($v['status']) ?>" data-status>
                                <?= e(status_label($v['status'])) ?>
                            </span>
                            <?php if (!empty($v['stage'])): ?>
                                <span class="badge tag"><?= e($v['stage']) ?></span>
                            <?php endif; ?>
                            <?php if ($v['kind'] === 'training_import'): ?>
                                <span class="badge tag">学習用</span>
                            <?php endif; ?>
                            <?php
                            // この動画が持つ字幕言語。既定の言語1つだけなら省略し、
                            // 複数ある/既定と違う場合だけ表示する（どの言語版か分かるように）。
                            $vLangs = $videoLangMap[(int) $v['id']]
                                ?? [\App\TargetLangs::normalize($v['target_lang'] ?? null)];
                            if (count($vLangs) > 1 || ($vLangs[0] ?? '') !== $currentTargetLang):
                                foreach ($vLangs as $vl): ?>
                                    <span class="badge tag" title="字幕の出力言語"><?= e(\App\TargetLangs::label($vl)) ?></span>
                                <?php endforeach;
                            endif; ?>
                        </div>
                        <?php
                        $meta = array_filter([$v['period'] ?? '', $v['format_label'] ?? '', $v['duration_label'] ?? '']);
                        ?>
                        <?php if ($meta): ?>
                            <div class="card-info muted"><?= e(implode(' ・ ', $meta)) ?></div>
                        <?php endif; ?>
                        <?php if (!empty($v['status_note'])): ?>
                            <div class="card-info muted">状態: <?= e($v['status_note']) ?></div>
                        <?php endif; ?>
                        <?php if (!empty($v['description'])): ?>
                            <div class="card-desc muted"><?= e(mb_strimwidth((string) $v['description'], 0, 48, '…')) ?></div>
                        <?php endif; ?>
                    </div>
                </a>
                <div class="card-foot">
                    <label class="move-label"><?= $icoFolder ?>
                        <select class="move-select" data-video-id="<?= (int) $v['id'] ?>">
                            <option value="">(ルート)</option>
                            <?php foreach ($folderOptions as $opt): ?>
                                <option value="<?= (int) $opt['id'] ?>"
                                    <?= ((int) ($v['folder_id'] ?? 0) === (int) $opt['id']) ? 'selected' : '' ?>>
                                    <?= e($opt['label']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <button type="button" class="icon-btn card-del" data-video-id="<?= (int) $v['id'] ?>"
                            data-title="<?= e($v['title']) ?>" title="この動画を削除" aria-label="この動画を削除"><?= $icoTrash ?></button>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- 新規フォルダ（隠しフォーム + JSでプロンプト） -->
<form id="newFolderForm" method="post" action="<?= e(url('/folders')) ?>" hidden>
    <input type="hidden" name="name" value="">
    <input type="hidden" name="parent_id" value="<?= $cur !== null ? (int) $cur : '' ?>">
</form>
<form id="renameForm" method="post" action="" hidden>
    <input type="hidden" name="name" value="">
</form>

<!-- アップロードモーダル -->
<div class="modal-backdrop" id="uploadModal" hidden>
    <div class="modal">
        <h2>新規作成</h2>
        <form id="uploadForm" class="stack">
            <input type="hidden" name="folder_id" value="<?= $cur !== null ? (int) $cur : '' ?>">
            <label>
                タイトル
                <input type="text" name="title" placeholder="例: 製品紹介 第1回（複数選択時は各ファイル名を使用）">
            </label>
            <label>
                動画ファイル
                <input type="file" name="video" accept="video/*" multiple required>
                <span class="muted field-hint">複数選択できます。複数のときはファイル名がタイトルになります。</span>
            </label>
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
                'uid' => 'upload',
            ]) ?>
            <?= \App\View::renderPartial('partials/transcribe_select', [
                'transcribeModels' => $transcribeModels,
                'defaultTranscribe' => $defaultTranscribe,
            ]) ?>
            <label class="inline-check">
                <input type="checkbox" name="auto_describe" value="1" checked>
                内容（説明文）を文字起こしからAIで自動生成する
            </label>
            <label class="inline-check">
                <input type="checkbox" name="kind" value="training_import">
                学習用に取り込む（焼き込み済み字幕付き完成動画）
            </label>
            <label class="inline-check">
                <input type="checkbox" name="build_glossary" value="1">
                用語集をAIで自動登録する（APIコストがかかります・学習用のみ有効）
            </label>
            <p class="muted field-hint" id="uploadLimitNotice">
                大きな動画は自動で分割してアップロードします（512MBずつ送信）。<br>
                数GBの動画もそのまま選択できます。回線速度により時間がかかる場合があります。
            </p>
            <div class="progress" id="uploadProgress" hidden>
                <div class="bar" id="uploadBar"></div>
            </div>
            <p class="muted upload-count" id="uploadCount" hidden></p>
            <p class="alert" id="uploadError" hidden></p>
            <div class="modal-actions">
                <button type="button" class="btn" id="cancelUpload">キャンセル</button>
                <button type="submit" class="btn primary" id="submitUpload">アップロードして開始</button>
            </div>
        </form>
    </div>
</div>

<script src="<?= e(asset('js/dashboard.js')) ?>"></script>
