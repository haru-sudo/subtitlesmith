<?php
/**
 * @var string|null $error
 * @var array<int,array{id:string,label:string,blocks_webview:bool}> $providers  OpenID Connect プロバイダ
 * @var bool $passwordEnabled
 * @var bool $inApp        アプリ内ブラウザ(WebView)からのアクセスか
 * @var string $inAppName  アプリ名（LINE 等）
 * @var string $inAppOs    'ios'|'android'|''
 */
$providers       = $providers ?? [];
$passwordEnabled = $passwordEnabled ?? false;
$inApp           = $inApp ?? false;
$inAppName       = $inAppName ?? '';
$inAppOs         = $inAppOs ?? '';
// /auth/... でブロックされて戻ってきた場合も案内を出す
if (!empty($_GET['inapp'])) {
    $inApp = true;
}
// アプリ内ブラウザを拒否するプロバイダ（Google など）があるときだけ案内を出す
$webviewBlocked = [];
foreach ($providers as $p) {
    if ($p['blocks_webview']) {
        $webviewBlocked[] = $p['label'];
    }
}
$inApp = $inApp && $webviewBlocked !== [];
?>
<div class="auth-card">
    <h1><?= e(App\App::config('app_name')) ?></h1>
    <p class="muted">許可されたアカウントでログインしてください。</p>

    <?php if (!empty($error)): ?>
        <p class="alert"><?= e($error) ?></p>
    <?php endif; ?>

    <?php if ($inApp): ?>
        <div class="alert" style="text-align:left;line-height:1.7;">
            <strong><?= e($inAppName !== '' ? $inAppName . 'のアプリ内ブラウザ' : 'アプリ内ブラウザ') ?>では「<?= e(implode('」「', $webviewBlocked)) ?>」が使えません。</strong><br>
            プロバイダのセキュリティポリシーにより、アプリ内ブラウザからのログインはブロックされます（エラー 403: disallowed_useragent）。<br>
            お手数ですが <b>Safari / Chrome などの標準ブラウザ</b>でこのページを開き直してください。
        </div>

        <div style="display:flex;flex-direction:column;gap:8px;margin-top:12px;">
            <button type="button" id="openExternal" class="btn primary" style="width:100%;justify-content:center;">標準ブラウザで開く</button>
            <button type="button" id="copyUrl" class="btn" style="width:100%;justify-content:center;">URLをコピー</button>
        </div>

        <p class="muted" id="manualHint" style="margin-top:12px;line-height:1.7;">
            <?php if ($inAppName === 'LINE'): ?>
                自動で開かない場合は、右上の「<b>︙</b>」または「<b>‹›</b>」メニューから
                <b>「他のアプリで開く」/「ブラウザで開く」</b>を選んでください。
            <?php else: ?>
                ボタンで開けない場合は、画面のメニュー（「<b>⋯</b>」「<b>︙</b>」「共有」など）から
                <b>「ブラウザで開く」/「Safari で開く」/「Chrome で開く」</b>を選んでください。
            <?php endif; ?>
        </p>
    <?php endif; ?>

    <?php if ($providers !== []): ?>
        <div class="auth-providers" style="display:flex;flex-direction:column;gap:8px;margin-top:12px;">
        <?php foreach ($providers as $p): ?>
            <?php if ($inApp && $p['blocks_webview']) { continue; } ?>
            <?php if ($p['id'] === 'google'): ?>
                <a class="gsi-material-button" href="<?= e(url('/auth/oidc/google')) ?>">
                    <div class="gsi-material-button-state"></div>
                    <div class="gsi-material-button-content-wrapper">
                        <div class="gsi-material-button-icon">
                            <svg version="1.1" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48" style="display:block;">
                                <path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"></path>
                                <path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"></path>
                                <path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"></path>
                                <path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"></path>
                                <path fill="none" d="M0 0h48v48H0z"></path>
                            </svg>
                        </div>
                        <span class="gsi-material-button-contents"><?= e($p['label']) ?></span>
                    </div>
                </a>
            <?php else: ?>
                <a class="btn" style="width:100%;justify-content:center;" href="<?= e(url('/auth/oidc/' . rawurlencode($p['id']))) ?>"><?= e($p['label']) ?></a>
            <?php endif; ?>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($passwordEnabled): ?>
        <?php if ($providers !== []): ?>
            <p class="muted" style="margin:16px 0 8px;">または</p>
        <?php endif; ?>
        <form method="post" action="<?= e(url('/login')) ?>" class="auth-password" style="display:flex;flex-direction:column;gap:8px;text-align:left;">
            <label>メールアドレス
                <input type="email" name="email" autocomplete="username" required>
            </label>
            <label>パスワード
                <input type="password" name="password" autocomplete="current-password" required>
            </label>
            <button type="submit" class="btn primary" style="width:100%;justify-content:center;">ログイン</button>
        </form>
    <?php endif; ?>

    <?php if ($providers === [] && !$passwordEnabled): ?>
        <p class="muted">ログイン方式が設定されていません（config.php の password_users または oidc_providers を設定してください）。</p>
    <?php endif; ?>
</div>

<?php if ($inApp): ?>
<script>
(function () {
    // ログイン本来のURL（クエリの inapp / error を除去）
    var u = new URL(window.location.href);
    u.searchParams.delete('inapp');
    u.searchParams.delete('error');
    var cleanUrl = u.toString();

    var ua = navigator.userAgent || '';
    var isLine     = /Line\//i.test(ua);
    var isAndroid  = /Android/i.test(ua);

    function openExternal() {
        if (isLine) {
            // LINE専用: openExternalBrowser=1 を付けると標準ブラウザで開く
            var lu = new URL(cleanUrl);
            lu.searchParams.set('openExternalBrowser', '1');
            window.location.href = lu.toString();
            return;
        }
        if (isAndroid) {
            // Android: intent:// で Chrome を明示起動。失敗時は元URLにフォールバック。
            var noScheme = cleanUrl.replace(/^https?:\/\//, '');
            var scheme = cleanUrl.indexOf('https://') === 0 ? 'https' : 'http';
            window.location.href =
                'intent://' + noScheme +
                '#Intent;scheme=' + scheme +
                ';package=com.android.chrome;S.browser_fallback_url=' +
                encodeURIComponent(cleanUrl) + ';end';
            return;
        }
        // iOS等: 自動脱出は不可。新規タブを試みつつ、ダメなら手動案内に委ねる。
        window.open(cleanUrl, '_blank');
    }

    var openBtn = document.getElementById('openExternal');
    if (openBtn) openBtn.addEventListener('click', openExternal);

    var copyBtn = document.getElementById('copyUrl');
    if (copyBtn) {
        copyBtn.addEventListener('click', function () {
            var done = function () {
                copyBtn.textContent = 'コピーしました';
                setTimeout(function () { copyBtn.textContent = 'URLをコピー'; }, 2000);
            };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(cleanUrl).then(done).catch(function () {
                    window.prompt('このURLをコピーして標準ブラウザで開いてください', cleanUrl);
                });
            } else {
                window.prompt('このURLをコピーして標準ブラウザで開いてください', cleanUrl);
            }
        });
    }

    // LINEは自動脱出が安定して動くため、開いた瞬間に一度だけ自動で試行する。
    if (isLine && !u.searchParams.get('noauto')) {
        openExternal();
    }
})();
</script>
<?php endif; ?>
