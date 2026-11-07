<?php
/**
 * @var bool $anthropicSet @var bool $openaiSet
 * @var string $defaultModel @var array $models @var bool $saved
 * @var bool $anthropicFromConfig @var bool $openaiFromConfig
 */
?>
<div class="page-head">
    <h1>設定</h1>
</div>

<?php if ($saved): ?>
    <p class="notice ok">設定を保存しました。</p>
<?php endif; ?>

<form method="post" action="<?= e(url('/settings')) ?>" class="settings-form stack" autocomplete="off">

    <section class="settings-section">
        <h2>APIキー</h2>
        <p class="muted">文字起こしには OpenAI、翻訳には各サービスのキーが必要です。
            入力欄を空のまま保存すると現在の値は変更されません。</p>

        <label>Anthropic APIキー（Claude翻訳）
            <input type="password" name="anthropic_api_key"
                   placeholder="<?= $anthropicSet ? '設定済み（変更する場合のみ入力）' : 'sk-ant-...' ?>">
        </label>
        <div class="key-meta muted">
            状態:
            <?php if ($anthropicSet): ?>
                <span class="hstat ok">設定済み</span>
                <?= $anthropicFromConfig ? '（config.php 由来）' : '（この画面で設定）' ?>
                <label class="inline-check clear-check">
                    <input type="checkbox" name="clear_anthropic_api_key" value="1"> クリアする
                </label>
            <?php else: ?>
                <span class="hstat warn">未設定</span>
            <?php endif; ?>
        </div>

        <label>OpenAI APIキー（文字起こし・翻訳メモリ）
            <input type="password" name="openai_api_key"
                   placeholder="<?= $openaiSet ? '設定済み（変更する場合のみ入力）' : 'sk-...' ?>">
        </label>
        <div class="key-meta muted">
            状態:
            <?php if ($openaiSet): ?>
                <span class="hstat ok">設定済み</span>
                <?= $openaiFromConfig ? '（config.php 由来）' : '（この画面で設定）' ?>
                <label class="inline-check clear-check">
                    <input type="checkbox" name="clear_openai_api_key" value="1"> クリアする
                </label>
            <?php else: ?>
                <span class="hstat warn">未設定</span>
            <?php endif; ?>
        </div>

        <label>Deepgram APIキー（文字起こし: Nova-3）
            <input type="password" name="deepgram_api_key"
                   placeholder="<?= $deepgramSet ? '設定済み（変更する場合のみ入力）' : 'Deepgramのキーを貼り付け' ?>">
        </label>
        <div class="key-meta muted">
            状態: <?= $deepgramSet ? '<span class="hstat ok">設定済み</span>' : '<span class="hstat warn">未設定（Deepgram文字起こしを使う場合のみ必要）</span>' ?>
            <?php if ($deepgramSet): ?>
                <label class="inline-check clear-check"><input type="checkbox" name="clear_deepgram_api_key" value="1"> クリア</label>
            <?php endif; ?>
        </div>

        <label>Deepgram キーターム（専門用語の認識強化）
            <textarea name="deepgram_keyterms" rows="3"
                      placeholder="Kubernetes, ファインチューニング, fine-tuning&#10;（改行またはカンマ区切りで入力）"><?= e($deepgramKeyterms) ?></textarea>
        </label>
        <div class="key-meta muted">
            Deepgram(Nova-3)が聞き取りにくい専門用語や固有名詞を、正しく文字起こしさせるためのヒント語です。
            例:「Kubernetes」が別の語に化ける場合、ここに「Kubernetes」を追加します。
            <label class="inline-check">
                <input type="checkbox" name="deepgram_keyterm_glossary" value="1" <?= $deepgramKeytermGlossary ? 'checked' : '' ?>>
                用語集の語も自動でキータームに使う
            </label>
            <span class="muted">（音声の言語に合わせて、原語または訳語を使います）</span>
        </div>

        <label>Google Gemini APIキー（Gemini翻訳）
            <input type="password" name="gemini_api_key"
                   placeholder="<?= $geminiSet ? '設定済み（変更する場合のみ入力）' : 'AIza...' ?>">
        </label>
        <div class="key-meta muted">
            状態: <?= $geminiSet ? '<span class="hstat ok">設定済み</span>' : '<span class="hstat warn">未設定（Gemini翻訳を使う場合のみ必要）</span>' ?>
            <?php if ($geminiSet): ?>
                <label class="inline-check clear-check"><input type="checkbox" name="clear_gemini_api_key" value="1"> クリア</label>
            <?php endif; ?>
        </div>

        <label>OpenAI互換 の接続先（Groq / DeepSeek / Ollama / LM Studio 等の翻訳）
            <select name="openai_compat_preset">
                <option value="" <?= $compatPreset === '' ? 'selected' : '' ?>>カスタム（下の URL を使う）</option>
                <?php foreach ($compatPresets as $cp): ?>
                    <option value="<?= e($cp['id']) ?>" <?= $compatPreset === $cp['id'] ? 'selected' : '' ?>><?= e($cp['name']) ?>（<?= e($cp['base_url']) ?>）</option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>カスタムの接続先 URL（.../v1 まで。プリセットを選んだときは不要）
            <input type="url" name="openai_compat_base_url" value="<?= e($compatBaseUrl) ?>" placeholder="http://127.0.0.1:11434/v1">
        </label>
        <label>OpenAI互換 のモデル名
            <input type="text" name="openai_compat_model" value="<?= e($compatModel) ?>" list="compatModelList" placeholder="例: llama-3.3-70b-versatile / qwen2.5:7b">
            <datalist id="compatModelList">
                <?php foreach ($compatPresets as $cp): ?>
                    <?php foreach ($cp['models'] as $m): ?>
                        <option value="<?= e($m) ?>"><?= e($cp['name']) ?></option>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </datalist>
        </label>
        <label>OpenAI互換 APIキー
            <input type="password" name="openai_compat_api_key"
                   placeholder="<?= $compatSet ? '設定済み（変更する場合のみ入力）' : 'APIキーを貼り付け（Ollama / LM Studio は任意の文字列）' ?>">
        </label>
        <div class="key-meta muted">
            状態: <?= $compatSet ? '<span class="hstat ok">設定済み</span>' : '<span class="hstat warn">未設定（OpenAI互換翻訳を使う場合のみ必要）</span>' ?>
            <?php if ($compatSet): ?>
                <label class="inline-check clear-check"><input type="checkbox" name="clear_openai_compat_api_key" value="1"> クリア</label>
            <?php endif; ?>
        </div>
    </section>

    <section class="settings-section">
        <h2>字幕の出力言語</h2>
        <label>既定の出力言語（訳出先）
            <select name="target_lang">
                <?php foreach ($targetLangs as $t): ?>
                    <option value="<?= e($t['id']) ?>" <?= $t['id'] === $currentTargetLang ? 'selected' : '' ?>>
                        <?= e($t['label']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <p class="muted setting-hint">新規アップロード時の初期値です。動画ごとにアップロード画面で変更できます。
            用語集と翻訳メモリは出力言語ごとに分かれて管理され、翻訳時はその言語のものだけが使われます。</p>
    </section>

    <section class="settings-section">
        <h2>字幕の表示時間</h2>
        <label>2行以上の字幕の最低表示時間（秒）
            <input type="number" name="subtitle_min_sec_multi" min="0" max="30" step="0.1"
                   value="<?= e((string) $minSecMulti) ?>">
        </label>
        <label>1行の字幕の最低表示時間（秒）
            <input type="number" name="subtitle_min_sec_single" min="0" max="30" step="0.1"
                   value="<?= e((string) $minSecSingle) ?>">
        </label>
        <p class="muted setting-hint">発話が短くてすぐ消えてしまう字幕を、読み切れるようこの時間まで表示し続けます
            （プレビュー・VTT・焼き込みすべてに適用。行数は焼き込み時の自動改行も含めて判定します）。
            次の字幕が始まる直前までしか延ばさないので、字幕どうしが重なったり音声とずれたりはしません。
            届かない字幕は編集画面に警告が出ます。0 にすると延長しません。</p>
    </section>

    <section class="settings-section">
        <h2>翻訳の精度オプション</h2>
        <label class="inline-check">
            <input type="checkbox" name="translate_refine" value="1" <?= $refineOn ? 'checked' : '' ?>>
            2パス自己校正で精度を上げる（精度優先）
        </label>
        <p class="muted setting-hint">1回翻訳した後、もう一度AIに用語集と原文を見せて訳を見直させます。
            誤訳や用語の取りこぼしが減りますが、翻訳のAPIコストは約2倍になります。</p>

        <label class="inline-check">
            <input type="checkbox" name="prompt_cache" value="1" <?= $cacheOn ? 'checked' : '' ?>>
            プロンプトキャッシュでコスト削減（推奨・既定ON）
        </label>
        <p class="muted setting-hint">毎回同じ翻訳ルールの部分をキャッシュし、入力コストを大きく削減します。
            精度には影響しません。基本はONのままで構いません。</p>
    </section>

    <div><button type="submit" class="btn primary">設定を保存</button></div>
</form>
