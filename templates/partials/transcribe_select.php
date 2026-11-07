<?php
/**
 * 文字起こしエンジン選択のパーシャル。
 * 必要な変数:
 *   $transcribeModels  TranscribeModels::choices()
 *   $defaultTranscribe 既定エンジンID
 */
?>
<div class="model-field">
    <span class="model-field-label">文字起こしエンジン</span>
    <div class="model-options model-options-compact">
        <?php foreach ($transcribeModels as $m): ?>
            <label class="model-option <?= $m['id'] === $defaultTranscribe ? 'selected' : '' ?>">
                <input type="radio" name="transcribe_model" value="<?= e($m['id']) ?>"
                       <?= $m['id'] === $defaultTranscribe ? 'checked' : '' ?>>
                <span class="model-main">
                    <span class="model-name"><?= e($m['name']) ?>
                        <?php if ($m['recommended']): ?><span class="badge tag recommend">推奨</span><?php endif; ?>
                        <?php if ($m['kind'] === 'local'): ?><span class="badge tag">コスト0</span><?php endif; ?>
                    </span>
                    <span class="model-note muted"><?= e($m['note']) ?></span>
                    <span class="model-specs muted">
                        精度: <?= e($m['accuracy']) ?> ・
                        <?php if ($m['kind'] === 'local'): ?>料金: 無料（サーバー実行）<?php
                        else: ?>料金: $<?= e(number_format((float) $m['cost_per_min'], 3)) ?>/分<?php endif; ?>
                    </span>
                </span>
            </label>
        <?php endforeach; ?>
    </div>
</div>
