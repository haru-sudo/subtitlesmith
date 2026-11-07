<?php /** @var string $title @var string $content @var bool $authenticated @var string $containerClass */
$containerClass = $containerClass ?? 'container'; ?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#f6f7f9">
    <meta name="format-detection" content="telephone=no">
    <title><?= e($title) ?></title>
    <link rel="icon" type="image/png" sizes="32x32" href="<?= e(asset('favicon-32.png')) ?>">
    <link rel="icon" type="image/png" sizes="64x64" href="<?= e(asset('favicon.png')) ?>">
    <link rel="apple-touch-icon" href="<?= e(asset('apple-touch-icon.png')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
    <script>window.APP_BASE = <?= json_encode(defined('APP_BASE') ? APP_BASE : '') ?>; window.APP_LATEST_NEWS = <?= json_encode(\App\Announcements::latestKey()) ?>;</script>
    <script src="<?= e(asset('js/common.js')) ?>"></script>
</head>
<body>
<?php if ($authenticated): ?>
    <header class="topbar">
        <a class="brand" href="<?= e(url('/')) ?>"><?= e(App\App::config('app_name')) ?></a>
        <nav class="topnav">
            <a href="<?= e(url('/')) ?>">ダッシュボード</a>
            <a href="<?= e(url('/glossary')) ?>">用語集</a>
            <a href="<?= e(url('/memory')) ?>">翻訳メモリ</a>
            <a href="<?= e(url('/settings')) ?>">設定</a>
            <a href="<?= e(url('/news')) ?>" class="nav-news">お知らせ<span class="nav-dot" id="newsDot" hidden aria-label="未読のお知らせがあります"></span></a>
            <a href="<?= e(url('/health')) ?>">状態</a>
            <?php if (App\Auth::name() || App\Auth::email()): ?>
                <span class="muted user-email"><?= e(App\Auth::name() ?: App\Auth::email()) ?></span>
            <?php endif; ?>
            <form action="<?= e(url('/logout')) ?>" method="post" class="inline">
                <button type="submit" class="link-btn">ログアウト</button>
            </form>
        </nav>
    </header>
<?php endif; ?>

<main class="<?= e($containerClass) ?>">
    <?= $content ?>
</main>
<?php $sourceUrl = trim((string) App\App::config('source_url', '')); ?>
<?php if ($sourceUrl !== ''): ?>
<footer class="site-footer muted">
    <a href="<?= e($sourceUrl) ?>" rel="noopener">ソースコード</a>（GNU AGPL-3.0）
</footer>
<?php endif; ?>
</body>
</html>
