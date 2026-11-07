<?php
/**
 * 字幕の出力言語の選択パーシャル。
 * 1本の動画に複数言語の字幕を持たせられるので、複数選択できる。
 * 最初にチェックした言語が「主言語」（編集画面で最初に開く言語）になる。
 *
 * 必要な変数:
 *   $targetLangs       TargetLangs::choices()
 *   $currentTargetLang 既定で選択しておく言語ID
 */
$targetLangs = $targetLangs ?? [];
$currentTargetLang = $currentTargetLang ?? 'ja';
?>
<div class="model-field">
    <span class="model-field-label">字幕の出力言語</span>
    <div class="lang-choices">
        <?php foreach ($targetLangs as $t): ?>
            <label class="inline-check lang-choice">
                <input type="checkbox" name="target_langs[]" value="<?= e($t['id']) ?>"
                       <?= $t['id'] === $currentTargetLang ? 'checked' : '' ?>>
                <?= e($t['label']) ?>
            </label>
        <?php endforeach; ?>
    </div>
    <span class="muted field-hint">
        複数選べます。選んだ言語ぶんの字幕を作り、編集画面のタブで切り替えられます。
        用語集・翻訳メモリも言語ごとに使い分けます。
        <strong>翻訳のAPIコストは言語の数だけかかります。</strong>あとから編集画面で追加・削除もできます。
    </span>
</div>
