-- subtitlesmith スキーマ（SQLite）
-- Database::migrate() から実行される。IF NOT EXISTS で冪等。

PRAGMA journal_mode = WAL;
PRAGMA foreign_keys = ON;

-- 動画
CREATE TABLE IF NOT EXISTS videos (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    title             TEXT NOT NULL,
    original_filename TEXT,
    source_lang       TEXT NOT NULL DEFAULT 'auto', -- auto|en|de|ja
    target_lang       TEXT NOT NULL DEFAULT 'ja',   -- 訳出先(字幕の出力言語)。App\TargetLangs のID
    duration_ms       INTEGER,
    video_width       INTEGER,        -- 映像の幅(px)。字幕1行の折り返し文字数の算出に使う
    video_height      INTEGER,        -- 映像の高さ(px)。同上
    thumbnail_path    TEXT,
    file_path         TEXT,           -- storage配下の動画ファイル相対パス
    audio_path        TEXT,           -- 抽出音声の相対パス
    status            TEXT NOT NULL DEFAULT 'uploaded',
                      -- uploaded|extracting|transcribing|translating|ready|exporting|exported|trimming|error
    kind              TEXT NOT NULL DEFAULT 'subtitle', -- subtitle|training_import
    folder_id         INTEGER,        -- 所属フォルダ(NULL=ルート)
    -- カタログ(スプレッドシート)由来のメタ情報
    source_file_name  TEXT,           -- 元ファイル名(リスト上)
    description       TEXT,           -- 内容
    period            TEXT,           -- 年月 (例 2023.9)
    format_label      TEXT,           -- 形式 (講義/デモ/レクチャー/レッスン等)
    duration_label    TEXT,           -- 分数 (リスト上の表記)
    stage             TEXT,           -- 完成品/仕掛品/翻訳素材
    status_note       TEXT,           -- 状態(自由記述)
    export_path       TEXT,           -- 焼き込み済みMP4の相対パス
    exported_at       TEXT,           -- 焼き込みを実行した時刻(字幕編集との新旧比較用)
    progress          INTEGER,        -- 現ステージの進捗(0-100)
    progress_note     TEXT,           -- 進捗の補足(例: 翻訳 3/8 バッチ)
    stage_index       INTEGER,        -- 現在のステージ番号(1始まり)
    stage_total       INTEGER,        -- 全ステージ数(例:3)
    stage_label       TEXT,           -- 現ステージ表示名(例: OCR中 / Whisper文字起こし中)
    translate_model   TEXT,           -- 翻訳に使うAIモデル(未指定=既定)
    translate_provider TEXT,          -- 翻訳プロバイダ(anthropic|gemini|openai。未指定=設定の既定)
    transcribe_model  TEXT,           -- 文字起こしエンジン(未指定=既定)
    error_message     TEXT,
    build_glossary    INTEGER NOT NULL DEFAULT 0,  -- 1なら取り込み時にAIで用語集を自動登録
    auto_describe     INTEGER NOT NULL DEFAULT 1,  -- 1なら文字起こしから「内容」をAIで自動生成
    created_at        TEXT NOT NULL DEFAULT (datetime('now','localtime')),
    updated_at        TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);

-- 動画が持つ字幕言語。1本の動画に複数言語を持たせ、編集画面で切り替える。
-- 書き出し(焼き込みMP4)は言語ごとに別ファイルになるので、ここで管理する。
CREATE TABLE IF NOT EXISTS video_langs (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    video_id      INTEGER NOT NULL,
    target_lang   TEXT NOT NULL,                    -- App\TargetLangs のID
    status        TEXT NOT NULL DEFAULT 'pending',  -- pending|translating|ready|error
    error_message TEXT,
    export_path   TEXT,                             -- この言語で焼き込んだMP4の相対パス
    exported_at   TEXT,                             -- 焼き込み時刻(字幕編集との新旧比較用)
    created_at    TEXT NOT NULL DEFAULT (datetime('now','localtime')),
    UNIQUE(video_id, target_lang),
    FOREIGN KEY (video_id) REFERENCES videos(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_video_langs_video ON video_langs(video_id);

-- 字幕セグメント（編集対象）
CREATE TABLE IF NOT EXISTS segments (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    video_id            INTEGER NOT NULL,
    idx                 INTEGER NOT NULL,           -- 表示順
    start_ms            INTEGER NOT NULL,
    end_ms              INTEGER NOT NULL,
    source_text         TEXT NOT NULL DEFAULT '',   -- 文字起こし(原文)。言語に依らず共通
    -- 以下4列は多言語対応より前の名残（1言語だけを持っていた頃の保管場所）。
    -- 現在の訳文は segment_translations にあり、これらは移行時のバックアップとしてのみ残す。
    machine_translation TEXT,
    edited_translation  TEXT,
    is_approved         INTEGER NOT NULL DEFAULT 0,
    approved_by         TEXT,
    confidence          REAL,                        -- OCR取込時の時刻対応付け信頼度(0..1)
    updated_at          TEXT NOT NULL DEFAULT (datetime('now','localtime')),
    FOREIGN KEY (video_id) REFERENCES videos(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_segments_video ON segments(video_id, idx);
CREATE INDEX IF NOT EXISTS idx_segments_video_time ON segments(video_id, start_ms);

-- セグメントの訳文（言語ごと）。時刻・原文は segments 側で共通に持ち、訳だけを言語別に持つ。
CREATE TABLE IF NOT EXISTS segment_translations (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    segment_id          INTEGER NOT NULL,
    target_lang         TEXT NOT NULL,              -- App\TargetLangs のID
    machine_translation TEXT,                       -- 自動翻訳
    edited_translation  TEXT,                       -- 人手編集後の訳（初期=machine）
    is_approved         INTEGER NOT NULL DEFAULT 0,
    approved_by         TEXT,                       -- 確認済にした人(表示名)
    updated_at          TEXT NOT NULL DEFAULT (datetime('now','localtime')),
    UNIQUE(segment_id, target_lang),
    FOREIGN KEY (segment_id) REFERENCES segments(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_segtr_lang ON segment_translations(target_lang, segment_id);

-- 用語集（Glossary）
CREATE TABLE IF NOT EXISTS glossary (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    source_term   TEXT NOT NULL,
    source_lang   TEXT NOT NULL DEFAULT 'en',       -- en|de
    target_lang   TEXT NOT NULL DEFAULT 'ja',       -- 訳語の言語(App\TargetLangs のID)
    target_term   TEXT NOT NULL,                    -- 訳語
    part_of_speech TEXT,
    note          TEXT,
    usage_count   INTEGER NOT NULL DEFAULT 0,
    origin        TEXT NOT NULL DEFAULT 'curated',  -- curated|mined|ai
    source_video_id INTEGER,                         -- 生成元の動画(学習用動画の削除時に連動削除)。手動追加はNULL。
    created_at    TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);
CREATE INDEX IF NOT EXISTS idx_glossary_term ON glossary(source_lang, target_lang, source_term);
CREATE INDEX IF NOT EXISTS idx_glossary_video ON glossary(source_video_id);

-- 用語集の「候補」。完成動画のOCR取り込み時に自動抽出され、人の承認待ちで貯まる。
-- 自動では翻訳に使われない。承認すると glossary に curated として昇格する。
CREATE TABLE IF NOT EXISTS glossary_candidates (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    source_term   TEXT NOT NULL,
    source_lang   TEXT NOT NULL DEFAULT 'en',          -- en|de
    target_term   TEXT NOT NULL DEFAULT '',            -- 推定訳語（空のこともある）
    frequency     INTEGER NOT NULL DEFAULT 1,          -- 出現回数（多いほど確からしい）
    example_src   TEXT,                                -- 出典の原文例
    example_ja    TEXT,                                -- 出典の訳文例
    status        TEXT NOT NULL DEFAULT 'pending',     -- pending|accepted|rejected
    source_video_id INTEGER,                            -- 最初に提案した動画(学習用動画の削除時に連動削除)
    created_at    TEXT NOT NULL DEFAULT (datetime('now','localtime')),
    updated_at    TEXT NOT NULL DEFAULT (datetime('now','localtime')),
    UNIQUE(source_lang, source_term, target_term)
);
CREATE INDEX IF NOT EXISTS idx_gcand_status ON glossary_candidates(status, frequency DESC);
CREATE INDEX IF NOT EXISTS idx_gcand_video ON glossary_candidates(source_video_id);


-- 翻訳メモリ（TM）
CREATE TABLE IF NOT EXISTS translation_memory (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    source_text     TEXT NOT NULL,
    source_lang     TEXT NOT NULL DEFAULT 'auto',
    target_lang     TEXT NOT NULL DEFAULT 'ja',     -- 訳文の言語(App\TargetLangs のID)
    target_text     TEXT NOT NULL,                  -- 確定訳
    origin          TEXT NOT NULL DEFAULT 'human_edit', -- human_edit|import_ocr
    source_video_id INTEGER,
    quality         REAL NOT NULL DEFAULT 1.0,
    embedding       BLOB,                           -- float32配列のバイナリ
    created_at      TEXT NOT NULL DEFAULT (datetime('now','localtime')),
    FOREIGN KEY (source_video_id) REFERENCES videos(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_tm_source ON translation_memory(source_text, target_lang);

-- ジョブキュー（cronワーカーが処理）
CREATE TABLE IF NOT EXISTS jobs (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    video_id    INTEGER,
    stage       TEXT NOT NULL,            -- extract|transcribe|translate|import_ocr|export
    status      TEXT NOT NULL DEFAULT 'pending', -- pending|running|done|error
    attempts    INTEGER NOT NULL DEFAULT 0,
    payload     TEXT,                     -- JSON
    error       TEXT,
    created_at  TEXT NOT NULL DEFAULT (datetime('now','localtime')),
    started_at  TEXT,
    finished_at TEXT,
    FOREIGN KEY (video_id) REFERENCES videos(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_jobs_status ON jobs(status, id);

-- フォルダ（入れ子可）
CREATE TABLE IF NOT EXISTS folders (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT NOT NULL,
    parent_id  INTEGER,                 -- NULL=ルート直下
    created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
    FOREIGN KEY (parent_id) REFERENCES folders(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_folders_parent ON folders(parent_id);
CREATE INDEX IF NOT EXISTS idx_videos_folder ON videos(folder_id);

-- アプリ設定（APIキー等をUIから保存）。key-value。
-- config.php より優先される。空文字は「未設定」とみなしconfigにフォールバック。
CREATE TABLE IF NOT EXISTS app_settings (
    key        TEXT PRIMARY KEY,
    value      TEXT NOT NULL DEFAULT '',
    updated_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);
