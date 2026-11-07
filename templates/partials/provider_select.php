<?php
/**
 * 翻訳プロバイダ + プロバイダ別モデル選択のパーシャル。
 * 必要な変数:
 *   $providers       TranslateProviders::choices()
 *   $currentProvider 現在のプロバイダID
 *   $modelChoices    TranslateProviders::modelChoices()
 *   $defaultModel    Claudeの既定モデルID
 *   $defaultGeminiModel  Geminiの既定モデルID
 *   $defaultCompatModel  OpenAI互換の既定モデルID
 *   $uid             同一ページに複数置く場合の接頭辞（例 'upload' / 'attach'）
 */
$uid = $uid ?? 'prov';
$defaultByProvider = [
    'anthropic' => $defaultModel ?? '',
    'gemini'    => $defaultGeminiModel ?? '',
    'openai'    => $defaultCompatModel ?? '',
];
?>
<div class="model-field">
    <span class="model-field-label">翻訳プロバイダ</span>
    <div class="model-options model-options-compact provider-group" data-uid="<?= e($uid) ?>">
        <?php foreach ($providers as $p): ?>
            <label class="model-option <?= $p['id'] === $currentProvider ? 'selected' : '' ?> <?= $p['ready'] ? '' : 'opt-disabled' ?>">
                <input type="radio" name="translate_provider" value="<?= e($p['id']) ?>"
                       <?= $p['id'] === $currentProvider ? 'checked' : '' ?>>
                <span class="model-main">
                    <span class="model-name"><?= e($p['name']) ?>
                        <?php if ($p['local'] ?? false): ?><span class="badge tag">コスト0</span>
                        <?php elseif ($p['free']): ?><span class="badge tag recommend">無料枠あり</span><?php endif; ?>
                        <?php if (!$p['ready']): ?><span class="badge tag" title="設定ページでキー等の登録が必要">未設定</span><?php endif; ?>
                    </span>
                    <span class="model-note muted"><?= e($p['note']) ?></span>
                </span>
            </label>
        <?php endforeach; ?>
    </div>
</div>

<?php foreach ($modelChoices as $provId => $models): ?>
    <?php
        // モデル候補が無いプロバイダ（例: OpenAI互換が未設定）はモデル欄を出さない
        if (!$models) {
            continue;
        }
        $isActive = ($provId === $currentProvider);
        $def = $defaultByProvider[$provId] ?? '';
        if ($def === '' && isset($models[0])) {
            $def = $models[0]['id'];
        }
        $labelByProv = ['anthropic' => 'Claude のモデル', 'gemini' => 'Gemini のモデル', 'openai' => 'モデル'];
    ?>
    <div class="model-field provider-models" data-uid="<?= e($uid) ?>" data-provider="<?= e($provId) ?>" <?= $isActive ? '' : 'hidden' ?>>
        <span class="model-field-label"><?= e($labelByProv[$provId] ?? 'モデル') ?></span>
        <div class="model-options model-options-compact">
            <?php foreach ($models as $m): ?>
                <label class="model-option <?= $m['id'] === $def ? 'selected' : '' ?>">
                    <input type="radio" name="model_<?= e($provId) ?>" value="<?= e($m['id']) ?>"
                           data-provider="<?= e($provId) ?>"
                           <?= $m['id'] === $def ? 'checked' : '' ?> <?= $isActive ? '' : 'disabled' ?>>
                    <span class="model-main">
                        <span class="model-name"><?= e($m['name']) ?></span>
                        <?php if (!empty($m['note'])): ?><span class="model-note muted"><?= e($m['note']) ?></span><?php endif; ?>
                    </span>
                </label>
            <?php endforeach; ?>
        </div>
    </div>
<?php endforeach; ?>

<!-- 実際に送信する値（選択プロバイダのモデルをJSがここへ反映） -->
<input type="hidden" name="translate_model" id="<?= e($uid) ?>_model_hidden" value="<?= e($defaultModel ?? '') ?>">
