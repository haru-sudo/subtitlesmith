/* デモ用のサンプルデータ（build_demo で実際のアプリから取得） */
window.DEMO_BASE = "/subtitlesmith2";
window.DEMO_REPO_URL = "https://github.com/haru-sudo/subtitlesmith2";
window.DEMO_DATA = {
 "videos": {
  "videos": [
   {
    "id": 2,
    "title": "Fine-tuning workshop（抜粋）",
    "status": "ready",
    "status_label": "編集可能",
    "kind": "subtitle",
    "has_thumbnail": true,
    "has_export": false,
    "created_at": "2026-11-07 13:40:22",
    "updated_at": "2026-11-07 13:40:22",
    "error": null,
    "progress": null,
    "progress_note": null,
    "stage_index": null,
    "stage_total": null,
    "stage_label": null
   },
   {
    "id": 1,
    "title": "クラウド設定ガイド 第1回",
    "status": "ready",
    "status_label": "編集可能",
    "kind": "subtitle",
    "has_thumbnail": true,
    "has_export": false,
    "created_at": "2026-11-07 13:40:22",
    "updated_at": "2026-11-07 13:40:22",
    "error": null,
    "progress": null,
    "progress_note": null,
    "stage_index": null,
    "stage_total": null,
    "stage_label": null
   }
  ]
 },
 "load": {
  "cpu_percent": 0,
  "cores": 4,
  "transcribe": {
   "running": false,
   "pid": null,
   "cpu_percent": null,
   "mem_mb": null
  },
  "translate": {
   "running": false,
   "pid": null,
   "cpu_percent": null,
   "mem_mb": null
  },
  "load1": 1.2802734375,
  "load5": 0.27783203125,
  "load15": 0.0888671875,
  "mem_total_mb": 16086,
  "mem_used_mb": 507,
  "mem_percent": 3.2
 },
 "video": {
  "1": {
   "id": 1,
   "title": "クラウド設定ガイド 第1回",
   "status": "ready",
   "status_label": "編集可能",
   "kind": "subtitle",
   "has_thumbnail": true,
   "has_export": false,
   "created_at": "2026-11-07 13:40:22",
   "updated_at": "2026-11-07 13:40:22",
   "error": null,
   "progress": null,
   "progress_note": null,
   "stage_index": null,
   "stage_total": null,
   "stage_label": null,
   "langs": [
    {
     "lang": "ja",
     "label": "日本語",
     "tag": "ja",
     "status": "ready",
     "has_export": false,
     "export_stale": true,
     "primary": true
    },
    {
     "lang": "zh-Hans",
     "label": "中国語（簡体字）",
     "tag": "zh-Hans",
     "status": "ready",
     "has_export": false,
     "export_stale": true,
     "primary": false
    }
   ]
  },
  "2": {
   "id": 2,
   "title": "Fine-tuning workshop（抜粋）",
   "status": "ready",
   "status_label": "編集可能",
   "kind": "subtitle",
   "has_thumbnail": true,
   "has_export": false,
   "created_at": "2026-11-07 13:40:22",
   "updated_at": "2026-11-07 13:40:22",
   "error": null,
   "progress": null,
   "progress_note": null,
   "stage_index": null,
   "stage_total": null,
   "stage_label": null,
   "langs": [
    {
     "lang": "ja",
     "label": "日本語",
     "tag": "ja",
     "status": "ready",
     "has_export": false,
     "export_stale": true,
     "primary": true
    }
   ]
  }
 },
 "segments": {
  "1": {
   "ja": [
    {
     "id": 1,
     "start_ms": 0,
     "end_ms": 2800,
     "source_text": "Welcome to the first part of our cloud setup guide.",
     "machine_translation": "クラウド設定ガイドの第1回へようこそ",
     "edited_translation": "クラウド設定ガイドの第1回へようこそ",
     "is_approved": true,
     "approved_by": "デモユーザー"
    },
    {
     "id": 2,
     "start_ms": 3000,
     "end_ms": 5900,
     "source_text": "First, open the settings page from the top menu.",
     "machine_translation": "まず上部のメニューから設定画面を開きます",
     "edited_translation": "まず上部のメニューから設定画面を開きます",
     "is_approved": true,
     "approved_by": "デモユーザー"
    },
    {
     "id": 3,
     "start_ms": 6100,
     "end_ms": 9200,
     "source_text": "Click New Project and give your project a short name.",
     "machine_translation": "「新規プロジェクト」を押して短い名前を付けます",
     "edited_translation": "「新規プロジェクト」を押して短い名前を付けます",
     "is_approved": true,
     "approved_by": "デモユーザー"
    },
    {
     "id": 4,
     "start_ms": 9400,
     "end_ms": 12600,
     "source_text": "The region you choose here affects latency, so pick the one closest to your users.",
     "machine_translation": "ここで選ぶリージョンは遅延に影響するので\nユーザーに最も近いものを選びます",
     "edited_translation": "ここで選ぶリージョンは遅延に影響するので\nユーザーに最も近いものを選びます",
     "is_approved": false,
     "approved_by": null
    },
    {
     "id": 5,
     "start_ms": 12800,
     "end_ms": 15600,
     "source_text": "Next, enable notifications so you know when a deployment fails.",
     "machine_translation": "次にデプロイの失敗に気付けるよう通知を有効にします",
     "edited_translation": "次にデプロイの失敗に気付けるよう通知を有効にします",
     "is_approved": false,
     "approved_by": null
    },
    {
     "id": 6,
     "start_ms": 15800,
     "end_ms": 18700,
     "source_text": "You can connect Slack or email in the integrations tab.",
     "machine_translation": "連携タブで Slack やメールを接続できます",
     "edited_translation": "連携タブで Slack やメールを接続できます",
     "is_approved": false,
     "approved_by": null
    },
    {
     "id": 7,
     "start_ms": 18900,
     "end_ms": 21400,
     "source_text": "Don't forget to save your changes before leaving the page.",
     "machine_translation": "ページを離れる前に変更を保存してください",
     "edited_translation": "ページを離れる前に変更を保存してください",
     "is_approved": false,
     "approved_by": null
    },
    {
     "id": 8,
     "start_ms": 21600,
     "end_ms": 23800,
     "source_text": "In the next video, we'll set up access permissions.",
     "machine_translation": "次回はアクセス権限を設定します",
     "edited_translation": "次回はアクセス権限を設定します",
     "is_approved": false,
     "approved_by": null
    }
   ],
   "zh-Hans": [
    {
     "id": 1,
     "start_ms": 0,
     "end_ms": 2800,
     "source_text": "Welcome to the first part of our cloud setup guide.",
     "machine_translation": "欢迎来到云设置指南第一部分",
     "edited_translation": "欢迎来到云设置指南第一部分",
     "is_approved": false,
     "approved_by": null
    },
    {
     "id": 2,
     "start_ms": 3000,
     "end_ms": 5900,
     "source_text": "First, open the settings page from the top menu.",
     "machine_translation": "首先，从顶部菜单打开设置页面",
     "edited_translation": "首先，从顶部菜单打开设置页面",
     "is_approved": false,
     "approved_by": null
    },
    {
     "id": 3,
     "start_ms": 6100,
     "end_ms": 9200,
     "source_text": "Click New Project and give your project a short name.",
     "machine_translation": "点击“新建项目”，并为项目起一个简短的名称",
     "edited_translation": "点击“新建项目”，并为项目起一个简短的名称",
     "is_approved": false,
     "approved_by": null
    },
    {
     "id": 4,
     "start_ms": 9400,
     "end_ms": 12600,
     "source_text": "The region you choose here affects latency, so pick the one closest to your users.",
     "machine_translation": "这里选择的区域会影响延迟，请选择离用户最近的区域",
     "edited_translation": "这里选择的区域会影响延迟，请选择离用户最近的区域",
     "is_approved": false,
     "approved_by": null
    },
    {
     "id": 5,
     "start_ms": 12800,
     "end_ms": 15600,
     "source_text": "Next, enable notifications so you know when a deployment fails.",
     "machine_translation": "接下来启用通知，以便在部署失败时收到提醒",
     "edited_translation": "接下来启用通知，以便在部署失败时收到提醒",
     "is_approved": false,
     "approved_by": null
    },
    {
     "id": 6,
     "start_ms": 15800,
     "end_ms": 18700,
     "source_text": "You can connect Slack or email in the integrations tab.",
     "machine_translation": "您可以在集成选项卡中连接 Slack 或电子邮件",
     "edited_translation": "您可以在集成选项卡中连接 Slack 或电子邮件",
     "is_approved": false,
     "approved_by": null
    },
    {
     "id": 7,
     "start_ms": 18900,
     "end_ms": 21400,
     "source_text": "Don't forget to save your changes before leaving the page.",
     "machine_translation": "离开页面前请不要忘记保存更改",
     "edited_translation": "离开页面前请不要忘记保存更改",
     "is_approved": false,
     "approved_by": null
    },
    {
     "id": 8,
     "start_ms": 21600,
     "end_ms": 23800,
     "source_text": "In the next video, we'll set up access permissions.",
     "machine_translation": "下一期视频我们将设置访问权限",
     "edited_translation": "下一期视频我们将设置访问权限",
     "is_approved": false,
     "approved_by": null
    }
   ]
  },
  "2": {
   "ja": [
    {
     "id": 9,
     "start_ms": 0,
     "end_ms": 3200,
     "source_text": "Today we'll prepare data for fine-tuning.",
     "machine_translation": "今日はファインチューニング用のデータを準備します",
     "edited_translation": "今日はファインチューニング用のデータを準備します",
     "is_approved": false,
     "approved_by": null
    },
    {
     "id": 10,
     "start_ms": 3400,
     "end_ms": 7000,
     "source_text": "Collect real examples from your users rather than writing them yourself.",
     "machine_translation": "自分で書くより、ユーザーの実例を集めましょう",
     "edited_translation": "自分で書くより、ユーザーの実例を集めましょう",
     "is_approved": false,
     "approved_by": null
    },
    {
     "id": 11,
     "start_ms": 7200,
     "end_ms": 11000,
     "source_text": "Then remove duplicates and check the labels by hand.",
     "machine_translation": "そのあと重複を取り除き、ラベルを手で確認します",
     "edited_translation": "そのあと重複を取り除き、ラベルを手で確認します",
     "is_approved": false,
     "approved_by": null
    },
    {
     "id": 12,
     "start_ms": 11200,
     "end_ms": 15000,
     "source_text": "A small, clean dataset often beats a large, noisy one.",
     "machine_translation": "小さくてもきれいなデータは\n大きくて雑なデータに勝ることが多いです",
     "edited_translation": "小さくてもきれいなデータは\n大きくて雑なデータに勝ることが多いです",
     "is_approved": false,
     "approved_by": null
    },
    {
     "id": 13,
     "start_ms": 15200,
     "end_ms": 17800,
     "source_text": "Let's look at an example.",
     "machine_translation": "例を見てみましょう",
     "edited_translation": "例を見てみましょう",
     "is_approved": false,
     "approved_by": null
    }
   ]
  }
 }
};
