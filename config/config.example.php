<?php
/**
 * 設定テンプレート。
 * 本ファイルを同ディレクトリに config.php としてコピーし、値を埋めてください。
 * config.php は公開領域の外に置き、Gitにもコミットしません（.gitignore済み）。
 */

return [
    // アプリ表示名（Google OAuth同意画面のアプリ名と一致させること）
    'app_name' => 'subtitlesmith',

    // タイムゾーン（表示・記録に使用）
    'timezone' => 'Asia/Tokyo',

    // ベースパス（サブディレクトリ設置時のURL接頭辞）。
    //   ルート(ドメイン直下)に置くなら '' （または下行を削除して自動検出）。
    //   例: https://example.com/subtitlesmith/ で使うなら '/subtitlesmith'。
    'base_path' => '',

    // 運営者名（トップページのフッターに表示。空なら表示しない）
    'operator_name' => '',

    // ソースコードの公開先（全ページのフッターにリンクを表示）。
    //   本ソフトウェアは GNU AGPL-3.0 です。改変して公開・提供する場合は、利用者が改変後の
    //   ソースコードを入手できるよう、ここを改変版のリポジトリURLに変更してください。
    'source_url' => 'https://github.com/haru-sudo/subtitlesmith2',

    // ---- 動画の分野（翻訳・用語抽出・説明文生成の AI に伝える） ----
    // 扱う動画の分野。空なら汎用の字幕翻訳者として振る舞います。
    //   例: '料理', 'プログラミング講座', 'スポーツ解説'
    'content_domain' => '',

    // 翻訳プロンプトに追記する分野固有のガイド（任意・複数行可）。
    //   専門用語の訳例や表記ルールなど。例:
    //   "- fine-tuning→ファインチューニング のように業界の標準訳を使う\n- 製品名は原綴りのまま"
    'translation_guidelines' => '',

    // 動画の説明文（AI 自動生成）を書く言語。'' なら設定ページの既定の出力言語に合わせます。
    //   例: 'ja', 'en', 'zh-Hans'
    'description_lang' => '',

    // ---- 認証 ----
    // セッション用のランダム文字列（任意の長い文字列）
    'app_secret' => 'change-this-to-a-long-random-string',

    // ログイン方式は次の2種類。どちらか一方だけでも、両方でも使えます。
    //
    // (1) パスワードログイン
    //   ハッシュの作り方: php -r 'echo password_hash("ここにパスワード", PASSWORD_DEFAULT), "\n";'
    //   ここに登録した人は allowed_emails に関係なくログインできます。
    'password_users' => [
        // ['email' => 'you@example.com', 'name' => 'あなた', 'password_hash' => '$2y$10$...'],
    ],

    // (2) OpenID Connect（Google / Microsoft / GitLab / Keycloak / Auth0 / Okta / Authentik など）
    //   各プロバイダの管理画面で「Webアプリ」のクライアントを作り、リダイレクトURIに
    //   https://<ドメイン><base_path>/auth/oidc/<id>/callback を登録します（<id> は下の配列のキー）。
    //   preset に google / microsoft / gitlab を指定すると issuer 等を省略できます。
    'oidc_providers' => [
        // 'google' => [
        //     'preset'        => 'google',
        //     'client_id'     => '',
        //     'client_secret' => '',
        // ],
        // 'microsoft' => [
        //     'preset'        => 'microsoft',
        //     'tenant'        => 'common',   // 組織だけに限るならテナントID
        //     'client_id'     => '',
        //     'client_secret' => '',
        // ],
        // 'keycloak' => [
        //     'label'         => '社内アカウントでログイン',
        //     'issuer'        => 'https://sso.example.com/realms/main',  // /.well-known/openid-configuration の親URL
        //     'client_id'     => '',
        //     'client_secret' => '',
        //     // 'scopes'                 => 'openid email profile',
        //     // 'require_verified_email' => true,   // email_verified が true のメールだけ受け付ける
        //     // 'redirect_uri'           => '',     // 省略時は自動（/auth/oidc/keycloak/callback）
        // ],
    ],

    // 旧形式の Google 設定（oidc_providers の 'google' と同じ。リダイレクトURIは /auth/google/callback）
    'google_client_id'     => '',
    'google_client_secret' => '',
    'google_redirect_uri'  => '',

    // OpenID Connect でログインを許可する人（どちらかに当てはまればログイン可）
    'allowed_emails' => [
        // 'you@example.com',
    ],
    'allowed_domains' => [
        // 'example.com',   // @example.com のメールは全員許可
    ],

    // ---- 外部API ----
    // ※ APIキーは画面の「設定」ページからも登録できます（推奨）。
    //    ここに書いた値は、設定ページで未入力のときのフォールバックになります。
    'anthropic_api_key' => '', // sk-ant-...
    'openai_api_key'    => '', // sk-...

    // 翻訳モデルの既定値（設定ページの「既定の翻訳モデル」で上書き可能）。
    //   claude-opus-5-5
    'translate_model' => 'claude-opus-5-5',
    // 文字起こしエンジン（設定ページで上書き可能）。
    //   deepgram-nova-3（既定・$0.0043/分・時刻つき）
    //   / whisper-1（$0.006/分・セグメント時刻つき）/ local-whisper（サーバー実行・コスト0）
    //   / gpt-4o-transcribe, gpt-4o-mini-transcribe
    //     ※gpt-4o系はセグメント時刻を返さないため、字幕用途では whisper-1 で実行される
    'transcribe_model' => 'deepgram-nova-3',
    // ローカル文字起こし(faster-whisper)用。transcribe_model=local-whisper の時のみ使用。
    //   GPUなし・大容量RAMの多コアCPUサーバー向けの既定値:
    //   - model: large-v3-turbo（large-v3とほぼ同等の精度で大幅に高速。CPU環境に最適）
    //            ※ より高精度を狙うなら large-v3、速度優先なら medium も可
    //   - compute: float32（RAM潤沢なCPUでは int8 より速く高精度。省メモリ時のみ int8）
    //   - device: cpu / cuda（NVIDIA GPU がある場合）
    //   - cpu_threads: 1ファイルあたりのスレッド数。0で自動（推奨）。
    //                  （極端に大きくしても頭打ち。複数動画は別プロセスで並列処理される）
    //   - beam: ビーム幅。精度優先5 / 速度優先1
    'local_whisper_model'       => 'large-v3-turbo',
    'local_whisper_compute'     => 'float32',
    'local_whisper_device'      => 'cpu',
    'local_whisper_cpu_threads' => 0,
    // セグメント並列のプロセス数。多コアサーバーで大幅高速化（OCRセグメント文字起こし）。1=従来。
    //   重要: 各ワーカーが自前でモデルをロードするため、メモリ使用 ≒ workers × モデルサイズ。
    //   float32 large-v3-turbo は1ワーカー約3〜5GB。安全圏の目安:
    //     workers ≦ (空きメモリGB × 0.7) ÷ 5   かつ   workers ≦ 空きコア数
    //   例: 空きメモリ32GB・8コアなら 4 程度。上げ過ぎるとOOMで落ちる（自動で直列にフォールバックはする）。
    //   省メモリにして数を増やしたい場合は local_whisper_compute を 'int8' に（1ワーカー約1〜1.5GB）。
    'local_whisper_workers'     => 1,
    // 通常モード(OCRなし)でBatchedInferencePipelineを使う（任意・高速だが窓間文脈は無効）。
    'local_whisper_batched'     => false,
    'local_whisper_batch_size'  => 16,
    'local_whisper_beam'        => 5,

    // ---- ローカルLLM翻訳(小型instructモデル / CTranslate2)。翻訳プロバイダ=ローカルLLM の時のみ使用 ----
    //   共有レンタルサーバーでも動く一発実行型。Ollama等の常駐サーバー不要・GPU不要・APIコスト0。
    //   用語集・翻訳メモリ・文脈・校正の指示を理解する。CPUなので処理は遅め。
    //   - model: HFのinstructモデル名。日本語に強く小型な Qwen2.5-3B-Instruct が既定。
    //            速度優先 Qwen/Qwen2.5-1.5B-Instruct、品質優先 Qwen/Qwen2.5-7B-Instruct。
    //            ※ CTranslate2 が対応するアーキテクチャ(Qwen2/Llama/Gemma2等)であること。
    //   - compute: CPUは int8。RAM潤沢なら float32。GPUは float16。
    //   - cpu_threads: 使用スレッド数。0で自動（推奨）。
    //   初回実行時にHFモデルをCTranslate2形式へ自動変換し storage/models/ct2_llm にキャッシュします。
    'local_llm_model'       => 'Qwen/Qwen2.5-3B-Instruct',
    'local_llm_compute'     => 'int8',
    'local_llm_device'      => 'cpu',
    // inter_threads: 同時に動かす翻訳の数（モデルをその数だけメモリに載せる）。
    // cpu_threads: 1翻訳あたりのスレッド数。0で自動。多コアなら inter × cpu ≦ コア数 になるように。
    'local_llm_inter_threads' => 1,
    'local_llm_cpu_threads'   => 0,

    // 埋め込みモデル（TM類似検索用）
    'embedding_model' => 'text-embedding-3-small',
    // 埋め込み次元（小さくすると保存/計算が軽い。0でモデル既定=1536）
    'embedding_dim' => 512,

    // ---- 実行ファイルのパス ----
    // PATH 上にあれば 'ffmpeg' / 'ffprobe' のままで可。共有サーバーでは ~/bin に置いた静的バイナリのフルパスを指定
    'ffmpeg'  => 'ffmpeg',
    'ffprobe' => 'ffprobe',
    // 焼き込み字幕の取り込み（OCR）用の Python 実行ファイル（opencv-python-headless と numpy が必要）
    //   例: '/home/USER/venvs/subtitlesmith/bin/python'
    'python'  => 'python',

    // ローカル文字起こし(faster-whisper)用の Python 3 実行ファイル。
    //   【重要】: faster-whisper は Python 3 必須。サーバーの 'python' が Python 2 だと
    //          「Non-ASCII character / SyntaxError」等で失敗します。
    //   下記のいずれかに Python 3 のフルパスを指定してください（python_whisper を最優先）。
    //   未指定なら python3 → python の順で探します。
    //   例(conda): '/home/USER/.conda/envs/subtitlesmith-whisper/bin/python'
    //   例(一般):          '/usr/bin/python3'
    'python_whisper' => '',
    'python3'        => 'python3',

    // ローカルLLM翻訳用の Python 3 実行ファイル。未指定なら
    //   python_whisper → python3 → python の順で探します。
    //   文字起こしと同じ env に ctranslate2 / transformers を追加すれば OK。
    'python_llm' => '',

    // バックグラウンド処理(runner.php)を起動するPHP CLIのパス。
    //   空ならPHP_BINARY/'php'を自動推定。もし自動起動が動かない場合のみ、
    //   サーバーのPHP CLIフルパスを指定してください（例: '/usr/bin/php8.3'）。
    'php_binary' => '',

    // 字幕1行の最大文字数(全角換算)。AI翻訳がこの数を超えそうな時、意味の切れ目で改行する。
    //   焼き込み時はこの数を超えた行が自動改行され、編集画面でも超過した行に警告が出る。
    //   半角文字は0.5文字として数えるので、英語などでは実質この2倍の文字数が入る。0で無効。
    'subtitle_max_line_chars' => 20,

    // 字幕焼き込みフォント（サーバーに該当フォントが必要）。日本語字幕に使う。
    //   Linux: 'Noto Sans CJK JP'（noto-cjk等を導入）
    //   Windowsローカル: 'Yu Gothic' / 'Meiryo' / 'MS Gothic' 等
    'subtitle_font' => 'Noto Sans CJK JP',

    // ---- 訳出先（字幕の出力言語） ----
    // 新規アップロード時の既定。設定ページからも変更できる（そちらが優先）。
    //   ja / zh-Hans / zh-Hant / ko / en / de / fr / es / pt / th / vi / id
    'target_lang' => 'ja',
    // 日本語以外の出力言語で使う焼き込みフォント。'subtitle_font_<言語ID>' で個別指定する。
    //   未指定なら言語ごとの既定（中国語簡体=Noto Sans CJK SC、繁体=TC、韓国語=KR、
    //   ラテン文字圏=Noto Sans）を使う。サーバーに入っているフォント名に合わせて指定すること。
    // 'subtitle_font_zh-Hans' => 'Noto Sans CJK SC',
    // 'subtitle_font_zh-Hant' => 'Noto Sans CJK TC',
    // 'subtitle_font_ko'      => 'Noto Sans CJK KR',

    // ---- パス ----
    // ストレージ（動画・音声・サムネ・字幕・書出物）。公開領域の外を推奨。
    'storage_dir' => __DIR__ . '/../storage',
    // ログ
    'log_dir' => __DIR__ . '/../logs',
    // SQLite DBファイル
    'db_path' => __DIR__ . '/../storage/app.sqlite',

    // ---- 翻訳の挙動 ----
    // 1リクエストでまとめて翻訳するセグメント数（コスト/レイテンシ調整）
    'translate_batch_size' => 15,
    // TM類似検索の取得件数（few-shot例として付与）
    'tm_topk' => 5,

    // ---- 学習用取り込み(OCR) ----
    // 字幕の切り替わり検出の感度（IoU基準、0-1。小さいほど敏感）
    'ocr_diff_threshold'  => '0.28',
    // 字幕帯のクロップ開始位置（画面上からの割合。3行字幕は0.4推奨）
    'ocr_crop_top'        => '0.5',
    'ocr_crop_bottom'     => '1.0',
    // サンプリング間隔（秒）
    'ocr_interval'        => '0.5',
    // 最小字幕表示時間（ms。これ未満の区間は隣接にマージ）
    'ocr_min_duration_ms' => '1000',
    // 字幕読み取りに使うVisionモデル（コスト削減目的でHaikuを既定）
    'ocr_vision_model'    => 'claude-haiku-4-5',
];
