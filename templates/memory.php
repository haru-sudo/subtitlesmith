<?php /** @var array $entries @var int $total @var int $page @var int $lastPage */ ?>
<div class="page-head">
    <h1>翻訳メモリ</h1>
    <span class="muted"><?= (int) $total ?> 件</span>
</div>
<p class="muted">
    字幕を「確認済」にした時、または動画を書き出した時に、原文と確定訳が自動で蓄積されます。
    類似した原文が来たとき、過去の訳を参考にして翻訳の一貫性・精度が上がります。
</p>

<div class="tm-list">
    <?php if (empty($entries)): ?>
        <p class="empty-state muted">まだ蓄積された訳はありません。編集画面で「確認済」にするか、書き出すと貯まります。</p>
    <?php else: ?>
        <?php foreach ($entries as $t): ?>
            <div class="tm-item">
                <div class="tm-texts">
                    <div class="tm-source"><?= e($t['source_text']) ?></div>
                    <div class="tm-target"><?= e($t['target_text']) ?></div>
                </div>
                <div class="tm-meta muted">
                    <span class="badge tag" title="原語 → 訳語の言語">
                        <?= e($t['source_lang']) ?> → <?= e(\App\TargetLangs::label($t['target_lang'] ?? null)) ?>
                    </span>
                    <span><?= e($t['origin'] === 'import_ocr' ? 'OCR取込' : '人手') ?></span>
                    <span><?= e(substr((string) $t['created_at'], 0, 16)) ?></span>
                    <form method="post" action="<?= e(url('/memory/' . (int) $t['id'] . '/delete')) ?>"
                          onsubmit="return confirm('削除しますか？');">
                        <button class="btn danger small" type="submit">削除</button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php if ($lastPage > 1): ?>
    <div class="pager">
        <?php if ($page > 1): ?><a class="btn" href="<?= e(url('/memory?page=' . ($page - 1))) ?>">← 前へ</a><?php endif; ?>
        <span class="muted"><?= $page ?> / <?= $lastPage ?></span>
        <?php if ($page < $lastPage): ?><a class="btn" href="<?= e(url('/memory?page=' . ($page + 1))) ?>">次へ →</a><?php endif; ?>
    </div>
<?php endif; ?>
