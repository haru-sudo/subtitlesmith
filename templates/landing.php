<?php /** 公開ホームページ（未ログインで表示・アプリ説明） */ ?>
<div class="landing">
    <div class="landing-hero">
        <img class="landing-logo" src="<?= e(asset('apple-touch-icon.png')) ?>" alt="" width="72" height="72">
        <h1><?= e(App\App::config('app_name')) ?></h1>
        <p class="landing-lead">
            動画をアップロードすると、自動で<strong>文字起こし・翻訳・字幕作成</strong>を行い、
            確認・修正のうえ字幕を映像へ焼き込んで書き出せる、動画・字幕管理ツールです。
        </p>
        <a class="btn primary landing-cta" href="<?= e(url('/login')) ?>">ログイン</a>
        <p class="muted">※ 許可されたアカウントのみログインできます。</p>
    </div>

    <div class="landing-features">
        <h2>主な機能</h2>
        <ul>
            <li><strong>自動文字起こし</strong>（多言語の音声に対応）</li>
            <li><strong>多言語への自動翻訳</strong>（専門用語の用語集・過去の修正を学習して精度向上）</li>
            <li><strong>字幕の編集・タイミング調整</strong>（プレビューを見ながら修正・確認）</li>
            <li><strong>字幕の焼き込み書き出し</strong>（字幕入りのMP4を生成）</li>
            <li><strong>動画の整理</strong>（フォルダで分類して管理）</li>
        </ul>
        <h2>このアプリでのログインについて</h2>
        <p class="muted">
            ログイン認証のためにアカウントのメールアドレスを利用します。許可リストに登録された
            利用者のみが利用できます。詳しくは
            <a href="<?= e(url('/privacy')) ?>">プライバシーポリシー</a> をご覧ください。
        </p>
    </div>

    <footer class="landing-footer muted">
        <a href="<?= e(url('/privacy')) ?>">プライバシーポリシー</a>
        <?php $operator = trim((string) App\App::config('operator_name', '')); ?>
        <?php if ($operator !== ''): ?>・ 運営: <?= e($operator) ?><?php endif; ?>
    </footer>
</div>
