<?php
/** デモ用のサンプルデータを投入する（tools/demo/build.sh から呼ばれる。既存データは消える） */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI専用です。\n");
}
require dirname(__DIR__, 2) . '/src/bootstrap.php';
$db = App\App::db();
foreach (['segment_translations','segments','video_langs','jobs','videos','folders','glossary','glossary_candidates','translation_memory'] as $t) { $db->run("DELETE FROM $t"); }
$db->run("DELETE FROM sqlite_sequence");
$db->insert('folders', ['name' => '製品紹介']);
$db->insert('folders', ['name' => '社内研修']);
$v1 = [
  'title' => 'クラウド設定ガイド 第1回', 'original_filename' => 'cloud-setup-01.mp4', 'source_lang' => 'en', 'target_lang' => 'ja',
  'duration_ms' => 24000, 'video_width' => 854, 'video_height' => 480, 'thumbnail_path' => 'thumbs/demo1.jpg',
  'file_path' => 'videos/demo1.mp4', 'status' => 'ready', 'folder_id' => null, 'auto_describe' => 0,
  'description' => '管理画面の開き方からプロジェクトの作成、通知の設定までを順に説明する入門編。',
  'translate_provider' => 'anthropic', 'transcribe_model' => 'deepgram-nova-3',
];
$db->insert('videos', $v1);
$db->insert('videos', [
  'title' => 'Fine-tuning workshop（抜粋）', 'original_filename' => 'workshop.mp4', 'source_lang' => 'en', 'target_lang' => 'ja',
  'duration_ms' => 18000, 'video_width' => 854, 'video_height' => 480, 'thumbnail_path' => 'thumbs/demo2.jpg',
  'file_path' => 'videos/demo2.mp4', 'status' => 'ready', 'folder_id' => 2, 'auto_describe' => 0,
  'description' => 'ファインチューニングの準備として、学習データの集め方と品質チェックの手順を紹介する。',
]);
$db->insert('video_langs', ['video_id' => 1, 'target_lang' => 'ja', 'status' => 'ready']);
$db->insert('video_langs', ['video_id' => 1, 'target_lang' => 'zh-Hans', 'status' => 'ready']);
$db->insert('video_langs', ['video_id' => 2, 'target_lang' => 'ja', 'status' => 'ready']);
$segs = [
 [0, 2800, "Welcome to the first part of our cloud setup guide.", "クラウド設定ガイドの第1回へようこそ", "欢迎来到云设置指南第一部分", 1],
 [3000, 5900, "First, open the settings page from the top menu.", "まず上部のメニューから設定画面を開きます", "首先，从顶部菜单打开设置页面", 1],
 [6100, 9200, "Click New Project and give your project a short name.", "「新規プロジェクト」を押して短い名前を付けます", "点击“新建项目”，并为项目起一个简短的名称", 1],
 [9400, 12600, "The region you choose here affects latency, so pick the one closest to your users.", "ここで選ぶリージョンは遅延に影響するので\nユーザーに最も近いものを選びます", "这里选择的区域会影响延迟，请选择离用户最近的区域", 0],
 [12800, 15600, "Next, enable notifications so you know when a deployment fails.", "次にデプロイの失敗に気付けるよう通知を有効にします", "接下来启用通知，以便在部署失败时收到提醒", 0],
 [15800, 18700, "You can connect Slack or email in the integrations tab.", "連携タブで Slack やメールを接続できます", "您可以在集成选项卡中连接 Slack 或电子邮件", 0],
 [18900, 21400, "Don't forget to save your changes before leaving the page.", "ページを離れる前に変更を保存してください", "离开页面前请不要忘记保存更改", 0],
 [21600, 23800, "In the next video, we'll set up access permissions.", "次回はアクセス権限を設定します", "下一期视频我们将设置访问权限", 0],
];
foreach ($segs as $i => [$s, $e, $src, $ja, $zh, $ok]) {
  $db->insert('segments', ['video_id' => 1, 'idx' => $i, 'start_ms' => $s, 'end_ms' => $e, 'source_text' => $src,
    'machine_translation' => $ja, 'edited_translation' => $ja, 'is_approved' => $ok, 'approved_by' => $ok ? 'デモユーザー' : null]);
  $sid = (int) $db->fetchValue('SELECT max(id) FROM segments');
  $db->insert('segment_translations', ['segment_id' => $sid, 'target_lang' => 'ja', 'machine_translation' => $ja, 'edited_translation' => $ja, 'is_approved' => $ok, 'approved_by' => $ok ? 'デモユーザー' : null]);
  $db->insert('segment_translations', ['segment_id' => $sid, 'target_lang' => 'zh-Hans', 'machine_translation' => $zh, 'edited_translation' => $zh, 'is_approved' => 0]);
}
$segs2 = [
 [0, 3200, "Today we'll prepare data for fine-tuning.", "今日はファインチューニング用のデータを準備します"],
 [3400, 7000, "Collect real examples from your users rather than writing them yourself.", "自分で書くより、ユーザーの実例を集めましょう"],
 [7200, 11000, "Then remove duplicates and check the labels by hand.", "そのあと重複を取り除き、ラベルを手で確認します"],
 [11200, 15000, "A small, clean dataset often beats a large, noisy one.", "小さくてもきれいなデータは\n大きくて雑なデータに勝ることが多いです"],
 [15200, 17800, "Let's look at an example.", "例を見てみましょう"],
];
foreach ($segs2 as $i => [$s, $e, $src, $ja]) {
  $db->insert('segments', ['video_id' => 2, 'idx' => $i, 'start_ms' => $s, 'end_ms' => $e, 'source_text' => $src, 'machine_translation' => $ja, 'edited_translation' => $ja]);
  $sid = (int) $db->fetchValue('SELECT max(id) FROM segments');
  $db->insert('segment_translations', ['segment_id' => $sid, 'target_lang' => 'ja', 'machine_translation' => $ja, 'edited_translation' => $ja]);
}
foreach ([
  ['region', 'リージョン', 'クラウドの地域'], ['deployment', 'デプロイ', ''], ['integrations tab', '連携タブ', '画面上の表記に合わせる'],
  ['fine-tuning', 'ファインチューニング', ''], ['access permissions', 'アクセス権限', ''], ['dataset', 'データセット', ''],
] as [$s, $t, $n]) {
  $db->insert('glossary', ['source_term' => $s, 'source_lang' => 'en', 'target_lang' => 'ja', 'target_term' => $t, 'note' => $n, 'origin' => 'curated']);
}
$db->insert('glossary', ['source_term' => 'region', 'source_lang' => 'en', 'target_lang' => 'zh-Hans', 'target_term' => '区域', 'origin' => 'ai']);
$db->insert('glossary_candidates', ['source_term' => 'latency', 'source_lang' => 'en', 'frequency' => 4, 'example_src' => 'The region you choose here affects latency.', 'example_ja' => 'ここで選ぶリージョンは遅延に影響します']);
$db->insert('glossary_candidates', ['source_term' => 'labels', 'source_lang' => 'en', 'frequency' => 3, 'example_src' => 'check the labels by hand', 'example_ja' => 'ラベルを手で確認します']);
foreach (array_slice($segs, 0, 3) as [$s, $e, $src, $ja]) {
  $db->insert('translation_memory', ['source_text' => $src, 'source_lang' => 'en', 'target_lang' => 'ja', 'target_text' => $ja, 'origin' => 'human_edit', 'source_video_id' => 1]);
}
echo "seeded\n";
