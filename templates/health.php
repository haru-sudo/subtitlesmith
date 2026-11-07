<?php /** @var array $checks */ ?>
<div class="page-head">
    <h1>環境チェック</h1>
    <a class="btn small" href="<?= e(url('/health?refresh=1')) ?>" title="ffmpeg等のキャッシュ済みチェックを再実行します">再チェック</a>
</div>
<p class="muted">デプロイ後の動作確認用。<span class="hstat ok">OK</span> は正常、
    <span class="hstat warn">注意</span> は設定推奨、<span class="hstat ng">NG</span> は要対応です。</p>

<div class="table-scroll">
<table class="health-table">
    <thead><tr><th>項目</th><th>状態</th><th>詳細</th></tr></thead>
    <tbody>
        <?php foreach ($checks as $c): ?>
            <tr>
                <td><?= e($c[0]) ?></td>
                <td><span class="hstat <?= e($c[1]) ?>"><?= $c[1] === 'ok' ? 'OK' : ($c[1] === 'warn' ? '注意' : 'NG') ?></span></td>
                <td class="muted"><?= e($c[2]) ?></td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>
</div>
