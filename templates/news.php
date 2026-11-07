<?php
/** @var array $items お知らせ一覧（新しい順） */

/** タグ名 → バッジの種類 */
$tagKind = static function (string $tag): string {
    return [
        '新機能'  => 'feature',
        '改善'    => 'improve',
        '修正'    => 'fix',
        'お知らせ' => 'info',
    ][$tag] ?? 'info';
};

/** 日付 'YYYY-MM-DD' → 'YYYY年M月D日'（失敗時はそのまま） */
$fmtDate = static function (string $d): string {
    $t = strtotime($d);
    return $t !== false ? date('Y年n月j日', $t) : $d;
};
?>
<div class="news">
    <div class="news-head">
        <h1>お知らせ</h1>
        <p class="muted">アプリの更新情報や運用に関するお知らせを掲載します。</p>
    </div>

    <?php if (empty($items)): ?>
        <p class="muted">お知らせはまだありません。</p>
    <?php else: ?>
        <ol class="news-list">
            <?php foreach ($items as $item): ?>
                <li class="news-item">
                    <div class="news-meta">
                        <span class="news-date"><?= e($fmtDate((string) $item['date'])) ?></span>
                        <span class="news-tag news-tag-<?= e($tagKind((string) $item['tag'])) ?>"><?= e($item['tag']) ?></span>
                    </div>
                    <h2 class="news-title"><?= e($item['title']) ?></h2>
                    <div class="news-body">
                        <?php
                        // 本文を描画。'- ' で始まる連続行は箇条書きにまとめる。
                        $lines = $item['body'];
                        $i = 0;
                        $n = count($lines);
                        while ($i < $n) {
                            $line = (string) $lines[$i];
                            if (strncmp($line, '- ', 2) === 0) {
                                echo '<ul>';
                                while ($i < $n && strncmp((string) $lines[$i], '- ', 2) === 0) {
                                    echo '<li>' . e(substr((string) $lines[$i], 2)) . '</li>';
                                    $i++;
                                }
                                echo '</ul>';
                            } else {
                                if (trim($line) !== '') {
                                    echo '<p>' . e($line) . '</p>';
                                }
                                $i++;
                            }
                        }
                        ?>
                    </div>
                </li>
            <?php endforeach; ?>
        </ol>
    <?php endif; ?>
</div>

<script>
  /* このページを開いたら「既読」にして未読バッジを消す */
  window.APP_NEWS_PAGE = true;
</script>
