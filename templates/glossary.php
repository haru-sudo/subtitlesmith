<?php /** @var array $entries @var array $candidates @var array $targetLangs @var string $defaultTargetLang */ ?>
<div class="page-head">
    <h1>用語集</h1>
    <span class="muted"><?= count($entries) ?> 語</span>
</div>
<p class="muted">ここに登録した訳語は、翻訳時に「必ずこの訳を使う」制約としてプロンプトに注入されます。
    訳語は出力言語ごとに分かれていて、翻訳ではその動画の出力言語と一致するものだけが使われます。</p>

<?php if (!empty($candidates)): ?>
<section class="cand-section">
    <h2 class="cand-title">用語候補 <span class="badge tag"><?= count($candidates) ?></span></h2>
    <p class="muted">完成動画の取り込み時に原文から自動抽出された候補です。訳語は自動では決めていません。
        正しい訳語を入力して「承認」すると用語集に登録されます（自動では翻訳に使われません）。</p>
    <div class="cand-list">
        <?php foreach ($candidates as $c): ?>
            <div class="cand-item">
                <div class="cand-head">
                    <span class="badge tag"><?= e(\App\TargetLangs::sourceName((string) $c['source_lang'])) ?></span>
                    <span class="cand-term"><?= e($c['source_term']) ?></span>
                    <span class="muted cand-freq"><?= (int) $c['frequency'] ?>回出現</span>
                </div>
                <?php if (!empty($c['example_src']) || !empty($c['example_ja'])): ?>
                    <div class="cand-example muted">
                        <?php if (!empty($c['example_src'])): ?><div>原: <?= e(mb_strimwidth((string) $c['example_src'], 0, 120, '…')) ?></div><?php endif; ?>
                        <?php if (!empty($c['example_ja'])): ?><div>訳: <?= e(mb_strimwidth((string) $c['example_ja'], 0, 120, '…')) ?></div><?php endif; ?>
                    </div>
                <?php endif; ?>
                <div class="cand-actions">
                    <form class="cand-approve" method="post"
                          action="<?= e(url('/glossary/candidates/' . (int) $c['id'] . '/approve')) ?>">
                        <input type="hidden" name="source_lang" value="<?= e($c['source_lang']) ?>">
                        <input type="text" name="source_term" value="<?= e($c['source_term']) ?>" required>
                        <span class="time-arrow">→</span>
                        <select name="target_lang" title="訳語の言語">
                            <?php foreach ($targetLangs as $t): ?>
                                <option value="<?= e($t['id']) ?>" <?= $t['id'] === $defaultTargetLang ? 'selected' : '' ?>><?= e($t['label']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="text" name="target_term" placeholder="正しい訳語を入力" required>
                        <button class="btn primary" type="submit">承認</button>
                    </form>
                    <form method="post" action="<?= e(url('/glossary/candidates/' . (int) $c['id'] . '/reject')) ?>"
                          onsubmit="return confirm('この候補を却下しますか？（今後再提案されません）');">
                        <button class="btn" type="submit">却下</button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<form class="gloss-add" method="post" action="<?= e(url('/glossary')) ?>">
    <select name="source_lang">
        <?php foreach (\App\TargetLangs::sourceChoices() as $sl): ?>
            <option value="<?= e($sl['id']) ?>" <?= $sl['id'] === 'en' ? 'selected' : '' ?>><?= e($sl['label']) ?></option>
        <?php endforeach; ?>
    </select>
    <input type="text" name="source_term" placeholder="原語 (例: fine-tuning)" required>
    <span class="time-arrow">→</span>
    <select name="target_lang" title="訳語の言語">
        <?php foreach ($targetLangs as $t): ?>
            <option value="<?= e($t['id']) ?>" <?= $t['id'] === $defaultTargetLang ? 'selected' : '' ?>><?= e($t['label']) ?></option>
        <?php endforeach; ?>
    </select>
    <input type="text" name="target_term" placeholder="訳語 (例: ファインチューニング)" required>
    <input type="text" name="note" placeholder="メモ(任意)">
    <button class="btn primary" type="submit">追加</button>
</form>

<div class="gloss-list">
    <?php if (empty($entries)): ?>
        <p class="empty-state muted">まだ用語がありません。上のフォームから追加してください。</p>
    <?php else: ?>
        <?php foreach ($entries as $g): ?>
            <div class="gloss-item">
                <form class="gloss-row" method="post" action="<?= e(url('/glossary/' . (int) $g['id'])) ?>">
                    <select name="source_lang">
                        <?php foreach (\App\TargetLangs::sourceChoices() as $sl): ?>
                            <option value="<?= e($sl['id']) ?>" <?= $g['source_lang'] === $sl['id'] ? 'selected' : '' ?>><?= e($sl['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text" name="source_term" value="<?= e($g['source_term']) ?>" required>
                    <span class="time-arrow">→</span>
                    <?php $gTarget = \App\TargetLangs::normalize($g['target_lang'] ?? null); ?>
                    <select name="target_lang" title="訳語の言語">
                        <?php foreach ($targetLangs as $t): ?>
                            <option value="<?= e($t['id']) ?>" <?= $t['id'] === $gTarget ? 'selected' : '' ?>><?= e($t['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text" name="target_term" value="<?= e($g['target_term']) ?>" required>
                    <input type="text" name="note" value="<?= e($g['note']) ?>" placeholder="メモ">
                    <button class="btn" type="submit">更新</button>
                </form>
                <form method="post" action="<?= e(url('/glossary/' . (int) $g['id'] . '/delete')) ?>"
                      onsubmit="return confirm('削除しますか？');">
                    <button class="btn danger" type="submit">削除</button>
                </form>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
