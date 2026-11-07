<?php

declare(strict_types=1);

namespace App;

use PDO;

/**
 * SQLite(PDO) の薄いラッパ。
 */
final class Database
{
    private PDO $pdo;

    public function __construct(string $path)
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $this->pdo = new PDO('sqlite:' . $path);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->exec('PRAGMA journal_mode = WAL;');
        $this->pdo->exec('PRAGMA foreign_keys = ON;');
        $this->pdo->exec('PRAGMA busy_timeout = 5000;');
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /** schema.sql を実行（IF NOT EXISTSで冪等） */
    public function migrate(string $schemaFile): void
    {
        // 先に既存テーブルへ不足カラムを追加する。
        // （schema.sql 内のインデックス作成が新カラムに依存するため、順序が重要）
        $this->applyColumnMigrations();

        if (is_file($schemaFile)) {
            $sql = file_get_contents($schemaFile);
            if ($sql !== false && trim($sql) !== '') {
                $this->pdo->exec($sql);
            }
        }

        // テーブルが揃ったあとに、1回だけ既存データを新しい形へ移す。
        $this->applyDataMigrations();
    }

    /**
     * 既存データの移行（1回だけ実行。完了印は app_settings に残す）。
     * 多言語対応より前のデータは「1動画=1言語」なので、その言語の行として
     * video_langs / segment_translations へ写す。
     */
    private function applyDataMigrations(): void
    {
        $this->runOnce('multilang_v1', function (): void {
            // 動画が持つ言語（旧 videos.target_lang。書き出し済みならその情報も引き継ぐ）
            $this->pdo->exec(
                "INSERT OR IGNORE INTO video_langs (video_id, target_lang, status, export_path, exported_at, created_at)
                 SELECT id,
                        COALESCE(NULLIF(target_lang, ''), 'ja'),
                        CASE WHEN status IN ('ready','exported','exporting','trimming') THEN 'ready' ELSE 'pending' END,
                        export_path,
                        exported_at,
                        COALESCE(created_at, datetime('now','localtime'))
                   FROM videos"
            );
            // セグメントの訳文（旧 segments の訳文列）
            $this->pdo->exec(
                "INSERT OR IGNORE INTO segment_translations
                        (segment_id, target_lang, machine_translation, edited_translation, is_approved, approved_by, updated_at)
                 SELECT s.id,
                        COALESCE(NULLIF(v.target_lang, ''), 'ja'),
                        s.machine_translation, s.edited_translation, s.is_approved, s.approved_by,
                        COALESCE(s.updated_at, datetime('now','localtime'))
                   FROM segments s
                   JOIN videos v ON v.id = s.video_id"
            );
        });
    }

    /**
     * 同じキーの処理を一度だけ実行する（完了印は app_settings テーブルに保存）。
     * app_settings が無い等で失敗しても、アプリ自体は動くようにここでは投げない。
     */
    private function runOnce(string $key, callable $fn): void
    {
        $mark = '_migrated_' . $key;
        try {
            $done = $this->fetchValue(
                'SELECT value FROM app_settings WHERE key = :k',
                [':k' => $mark]
            );
            if ((string) $done === '1') {
                return;
            }
            $fn();
            $this->run(
                'INSERT INTO app_settings (key, value, updated_at) VALUES (:k, :v, :u)
                 ON CONFLICT(key) DO UPDATE SET value = :v2, updated_at = :u2',
                [':k' => $mark, ':v' => '1', ':u' => date('Y-m-d H:i:s'), ':v2' => '1', ':u2' => date('Y-m-d H:i:s')]
            );
        } catch (\Throwable $e) {
            error_log('データ移行に失敗(' . $key . '): ' . $e->getMessage());
        }
    }

    /** 既存DBに不足カラムを追加（CREATE TABLE IF NOT EXISTS では列追加されないため） */
    private function applyColumnMigrations(): void
    {
        $this->addColumnIfMissing('videos', 'folder_id', 'INTEGER');
        $this->addColumnIfMissing('segments', 'confidence', 'REAL');
        $this->addColumnIfMissing('segments', 'approved_by', 'TEXT');
        // カタログ(動画リスト)用メタ列
        $this->addColumnIfMissing('videos', 'source_file_name', 'TEXT');
        $this->addColumnIfMissing('videos', 'description', 'TEXT');
        $this->addColumnIfMissing('videos', 'period', 'TEXT');
        $this->addColumnIfMissing('videos', 'format_label', 'TEXT');
        $this->addColumnIfMissing('videos', 'duration_label', 'TEXT');
        $this->addColumnIfMissing('videos', 'stage', 'TEXT');
        $this->addColumnIfMissing('videos', 'status_note', 'TEXT');
        $this->addColumnIfMissing('videos', 'translate_model', 'TEXT');
        $this->addColumnIfMissing('videos', 'translate_provider', 'TEXT');
        $this->addColumnIfMissing('videos', 'transcribe_model', 'TEXT');
        $this->addColumnIfMissing('videos', 'exported_at', 'TEXT');
        $this->addColumnIfMissing('videos', 'progress', 'INTEGER');
        $this->addColumnIfMissing('videos', 'progress_note', 'TEXT');
        $this->addColumnIfMissing('videos', 'stage_index', 'INTEGER');
        $this->addColumnIfMissing('videos', 'stage_total', 'INTEGER');
        $this->addColumnIfMissing('videos', 'stage_label', 'TEXT');
        $this->addColumnIfMissing('videos', 'build_glossary', 'INTEGER');
        $this->addColumnIfMissing('videos', 'auto_describe', 'INTEGER NOT NULL DEFAULT 1');
        // 学習用動画の削除時に、その動画が生成した用語集/候補を連動削除するための紐づけ列
        $this->addColumnIfMissing('glossary', 'source_video_id', 'INTEGER');
        $this->addColumnIfMissing('glossary_candidates', 'source_video_id', 'INTEGER');
        // 訳出先(出力言語)の多言語対応。既存データはすべて日本語訳なので既定 'ja' で埋まる。
        $this->addColumnIfMissing('videos', 'target_lang', "TEXT NOT NULL DEFAULT 'ja'");
        $this->addColumnIfMissing('glossary', 'target_lang', "TEXT NOT NULL DEFAULT 'ja'");
        $this->addColumnIfMissing('translation_memory', 'target_lang', "TEXT NOT NULL DEFAULT 'ja'");
        // 字幕1行の折り返し文字数を編集画面でも算出するための映像サイズ
        $this->addColumnIfMissing('videos', 'video_width', 'INTEGER');
        $this->addColumnIfMissing('videos', 'video_height', 'INTEGER');
    }

    public function addColumnIfMissing(string $table, string $column, string $definition): void
    {
        $cols = $this->fetchAll("PRAGMA table_info($table)");
        foreach ($cols as $c) {
            if (($c['name'] ?? '') === $column) {
                return;
            }
        }
        if ($cols) { // テーブルが存在する場合のみ
            $this->pdo->exec("ALTER TABLE $table ADD COLUMN $column $definition");
        }
    }

    /** プレースホルダ付きで実行し statement を返す */
    public function run(string $sql, array $params = []): \PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /** 1行取得（無ければ null） */
    public function fetch(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /** 全行取得 */
    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    /** 1値取得 */
    public function fetchValue(string $sql, array $params = []): mixed
    {
        $v = $this->run($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    /** INSERTして新ID（int）を返す */
    public function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $place = array_map(fn($c) => ':' . $c, $cols);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', $cols),
            implode(', ', $place)
        );
        $params = [];
        foreach ($data as $k => $v) {
            $params[':' . $k] = $v;
        }
        $this->run($sql, $params);
        return (int) $this->pdo->lastInsertId();
    }

    /** UPDATE（where は連想配列） */
    public function update(string $table, array $data, array $where): int
    {
        $set = [];
        $params = [];
        foreach ($data as $k => $v) {
            $set[] = "$k = :set_$k";
            $params[":set_$k"] = $v;
        }
        $cond = [];
        foreach ($where as $k => $v) {
            $cond[] = "$k = :w_$k";
            $params[":w_$k"] = $v;
        }
        $sql = sprintf(
            'UPDATE %s SET %s WHERE %s',
            $table,
            implode(', ', $set),
            implode(' AND ', $cond)
        );
        return $this->run($sql, $params)->rowCount();
    }

    public function beginTransaction(): void
    {
        $this->pdo->beginTransaction();
    }

    public function commit(): void
    {
        $this->pdo->commit();
    }

    public function rollBack(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }
}
