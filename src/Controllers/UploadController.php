<?php

declare(strict_types=1);

namespace App\Controllers;

use App\App;
use App\Http\Request;
use App\Http\Response;
use App\Jobs;
use App\Repo\Videos;

final class UploadController
{
    private const ALLOWED_EXT = ['mp4', 'mov', 'mkv', 'webm', 'm4v', 'avi'];

    /** 新規アップロード（AJAX, JSON応答）。複数ファイルは1ファイルずつ本エンドポイントへ送る。 */
    public function store(Request $request): void
    {
        $file = $request->file('video');
        $origName = is_array($file) ? (string) ($file['name'] ?? '') : '';

        try {
            $relPath = $this->moveUpload($file);
        } catch (\RuntimeException $e) {
            Response::json(['error' => 'upload_failed', 'message' => $e->getMessage()], 422);
            return;
        }

        $videoId = $this->finalizeUpload($request, $relPath, $origName);
        Response::json(['ok' => true, 'id' => $videoId, 'redirect' => '/videos/' . $videoId], 201);
    }

    /**
     * 分割（チャンク）アップロードの受領。1GB等の塊を順番に受け取り、一時ファイルへ追記結合する。
     * 共用サーバーの「1リクエスト＝2GB前後」の上限を回避するための仕組み。
     *
     * 期待するPOSTフィールド（multipart/form-data）:
     *   - upload_id   : ファイルごとの一意キー（フロントが生成）
     *   - chunk_index : 0始まりのチャンク番号
     *   - chunk_total : 総チャンク数
     *   - file_name   : 元のファイル名
     *   - chunk       : チャンク本体（ファイルとして送る）
     *   - その他、最終チャンク時に store と同じメタ（title, kind, provider 等）
     */
    public function storeChunk(Request $request): void
    {
        // 大きなチャンクの結合中にタイムアウトしないよう実行時間制限を解除（可能な環境のみ）
        @set_time_limit(0);

        $uploadId = $this->safeUploadId((string) $request->input('upload_id', ''));
        $index = (int) $request->input('chunk_index', -1);
        $total = (int) $request->input('chunk_total', 0);
        $fileName = (string) $request->input('file_name', '');

        if ($uploadId === '' || $index < 0 || $total <= 0 || $index >= $total) {
            Response::json(['error' => 'bad_request', 'message' => 'チャンク情報が不正です。'], 422);
            return;
        }

        // 拡張子チェックは最初のチャンク受領時点で行う（無駄な転送を早めに止める）
        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        if (!in_array($ext, self::ALLOWED_EXT, true)) {
            Response::json(['error' => 'bad_ext', 'message' => '対応していない形式です（mp4 / mov / mkv / webm / m4v / avi）。'], 422);
            return;
        }

        $chunk = $request->file('chunk');
        if ($chunk === null || !isset($chunk['error'])) {
            Response::json(['error' => 'no_chunk', 'message' => 'チャンクがありません。'], 422);
            return;
        }
        if ($chunk['error'] !== UPLOAD_ERR_OK) {
            Response::json(['error' => 'chunk_error', 'message' => self::uploadErrorMessage((int) $chunk['error'])], 422);
            return;
        }
        if (!is_uploaded_file((string) $chunk['tmp_name'])) {
            Response::json(['error' => 'bad_chunk', 'message' => '不正なアップロードです。'], 422);
            return;
        }

        $partsDir = App::storagePath('uploads_tmp/' . $uploadId);

        // 最初のチャンク受領時: このアップロード用のディレクトリを作り直し、古い残骸も掃除
        if ($index === 0) {
            $this->removeDir($partsDir);
            $this->cleanupStaleParts();
        }
        if (!is_dir($partsDir) && !@mkdir($partsDir, 0775, true) && !is_dir($partsDir)) {
            App::log('chunk mkdir failed: ' . $partsDir, 'error');
            Response::json(['error' => 'io_error', 'message' => 'サーバー側で結合に失敗しました。'], 500);
            return;
        }

        // チャンクを番号付きの個別ファイルとして保存する。
        // 同じ番号が再送されても上書きになるだけで壊れない（追記方式の二重書き込み事故を防ぐ）。
        $chunkPath = $partsDir . '/' . str_pad((string) $index, 6, '0', STR_PAD_LEFT) . '.chunk';
        if (!@move_uploaded_file((string) $chunk['tmp_name'], $chunkPath)) {
            App::log('chunk move failed: ' . $chunkPath, 'error');
            Response::json(['error' => 'io_error', 'message' => 'サーバー側で結合に失敗しました。'], 500);
            return;
        }

        // まだ最終チャンクでなければ、受領のみ応答
        if ($index < $total - 1) {
            Response::json(['ok' => true, 'received' => $index + 1, 'total' => $total]);
            return;
        }

        // 最終チャンク到達 → 全チャンクが揃っているか確認してから番号順に結合する
        for ($i = 0; $i < $total; $i++) {
            $p = $partsDir . '/' . str_pad((string) $i, 6, '0', STR_PAD_LEFT) . '.chunk';
            if (!is_file($p)) {
                App::log('chunk missing on finalize: ' . $p, 'error');
                $this->removeDir($partsDir);
                Response::json(['error' => 'incomplete', 'message' => 'チャンクが不足しています。もう一度お試しください。'], 422);
                return;
            }
        }

        $storedName = date('Ymd_His') . '_' . bin2hex(random_bytes(16)) . '.' . $ext;
        $relPath = 'videos/' . $storedName;
        $destAbs = App::storagePath($relPath);

        $out = @fopen($destAbs, 'wb');
        if ($out === false) {
            App::log('chunk finalize open dest failed: ' . $destAbs, 'error');
            $this->removeDir($partsDir);
            Response::json(['error' => 'finalize_failed', 'message' => '保存に失敗しました。'], 500);
            return;
        }
        $written = 0;
        for ($i = 0; $i < $total; $i++) {
            $p = $partsDir . '/' . str_pad((string) $i, 6, '0', STR_PAD_LEFT) . '.chunk';
            $in = @fopen($p, 'rb');
            if ($in === false) {
                fclose($out);
                @unlink($destAbs);
                $this->removeDir($partsDir);
                App::log('chunk finalize open part failed: ' . $p, 'error');
                Response::json(['error' => 'finalize_failed', 'message' => '保存に失敗しました。'], 500);
                return;
            }
            $copied = stream_copy_to_stream($in, $out);
            fclose($in);
            $expected = (int) @filesize($p);
            if ($copied === false || $copied < $expected) {
                fclose($out);
                @unlink($destAbs);
                $this->removeDir($partsDir);
                App::log('chunk finalize copy short: ' . $p . ' copied=' . var_export($copied, true) . ' expected=' . $expected, 'error');
                Response::json(['error' => 'finalize_failed', 'message' => '結合に失敗しました（書き込みが途中で止まりました）。サーバーの空き容量をご確認ください。'], 500);
                return;
            }
            $written += $copied;
        }
        fclose($out);

        // 結合後のサイズが各チャンクの合計と一致するか最終確認（壊れたファイルを世に出さない）
        if ((int) @filesize($destAbs) !== $written) {
            @unlink($destAbs);
            $this->removeDir($partsDir);
            App::log('chunk finalize size mismatch: dest=' . (int) @filesize($destAbs) . ' written=' . $written, 'error');
            Response::json(['error' => 'finalize_failed', 'message' => '結合後のファイルが不正です。もう一度お試しください。'], 500);
            return;
        }

        // 一時チャンクを片付ける
        $this->removeDir($partsDir);

        $videoId = $this->finalizeUpload($request, $relPath, $fileName);
        Response::json(['ok' => true, 'id' => $videoId, 'redirect' => '/videos/' . $videoId], 201);
    }

    /**
     * アップロード完了後の共通処理（タイトル決定→メタ解決→DBレコード作成→ジョブ投入）。
     * 通常アップロード(store)と分割アップロード(storeChunk)の両方から呼ぶ。
     */
    private function finalizeUpload(Request $request, string $relPath, string $origName): int
    {
        // タイトル未入力なら元ファイル名（拡張子なし）を採用する（複数アップロード対応）
        $title = trim((string) $request->input('title', ''));
        if ($title === '') {
            $title = pathinfo($origName, PATHINFO_FILENAME);
        }
        if ($title === '') {
            $title = '無題';
        }

        $kind = ((string) $request->input('kind', '') === 'training_import') ? 'training_import' : 'subtitle';
        // 用語集をAIで自動登録するか（チェック時のみ。APIコストがかかるため既定オフ）
        $buildGlossary = ((string) $request->input('build_glossary', '') !== '') ? 1 : 0;
        // 文字起こし内容から「内容」をAIで自動生成するか（既定オン。チェックを外すと無効）
        $autoDescribe = ((string) $request->input('auto_describe', '') !== '') ? 1 : 0;

        // 翻訳プロバイダ（未指定/不正は設定の既定）
        $provider = (string) $request->input('translate_provider', '');
        if ($provider === '' || !\App\TranslateProviders::isValid($provider)) {
            $provider = \App\TranslateProviders::current();
        }
        // 選択プロバイダに対応するモデルを解決
        $model = self::resolveModel($request, $provider);
        // 文字起こしエンジン（未指定/不正は既定）
        $tModel = (string) $request->input('transcribe_model', '');
        if ($tModel === '' || !\App\TranscribeModels::isValid($tModel)) {
            $tModel = \App\TranscribeModels::default();
        }
        // 訳出先の言語（複数可。先頭が主言語＝編集画面で最初に開く言語）
        $targetLangs = self::resolveTargetLangs($request);
        $targetLang = $targetLangs[0];

        $folderId = $request->input('folder_id');
        $folderId = ($folderId === null || $folderId === '' || (int) $folderId === 0) ? null : (int) $folderId;
        if ($folderId !== null && !\App\Repo\Folders::find($folderId)) {
            $folderId = null;
        }

        $videoId = Videos::create([
            'title'             => $title,
            'original_filename' => basename($relPath),
            'source_file_name'  => $origName,
            'source_lang'       => 'auto',
            'target_lang'       => $targetLang,
            'file_path'         => $relPath,
            'status'            => 'uploaded',
            'kind'              => $kind,
            'build_glossary'    => $buildGlossary,
            'auto_describe'     => $autoDescribe,
            'folder_id'         => $folderId,
            'translate_model'   => $model,
            'translate_provider' => $provider,
            'transcribe_model'  => $tModel,
        ]);

        // この動画が持つ字幕言語を登録（翻訳は文字起こし後に言語ごとのジョブで走る）
        foreach ($targetLangs as $tl) {
            \App\Repo\VideoLangs::add($videoId, $tl);
        }

        Jobs::enqueue($videoId, 'extract');
        \App\Worker::spawn(); // 即時にバックグラウンド処理を起動（cron不要）
        return $videoId;
    }

    /**
     * フォームから出力言語を取り出す。
     * target_langs[]（複数選択）を優先し、無ければ target_lang（単一）、
     * それも無ければ設定の既定言語。重複と不正値は落とす。
     * @return array<int,string> 少なくとも1件。先頭が主言語。
     */
    private static function resolveTargetLangs(Request $request): array
    {
        $raw = $request->input('target_langs');
        if (!is_array($raw)) {
            $single = (string) $request->input('target_lang', '');
            $raw = $single !== '' ? [$single] : [];
        }
        $out = [];
        foreach ($raw as $c) {
            $c = (string) $c;
            if (\App\TargetLangs::isValid($c) && !in_array($c, $out, true)) {
                $out[] = $c;
            }
        }
        return $out ?: [\App\TargetLangs::current()];
    }

    /** upload_id をファイル名に使える安全な文字だけに制限する（パストラバーサル防止）。 */
    private function safeUploadId(string $id): string
    {
        $id = preg_replace('/[^A-Za-z0-9_-]/', '', $id) ?? '';
        return substr($id, 0, 64);
    }

    /** uploads_tmp 内の古いチャンク（中断された結合途中のディレクトリ/ファイル）を削除する。 */
    private function cleanupStaleParts(int $maxAgeSeconds = 86400): void
    {
        $dir = App::storagePath('uploads_tmp');
        if (!is_dir($dir)) {
            return;
        }
        $now = time();
        // 旧方式の .part ファイル（残っていれば）
        foreach (glob($dir . '/*.part') ?: [] as $f) {
            if (is_file($f) && ($now - (int) @filemtime($f)) > $maxAgeSeconds) {
                @unlink($f);
            }
        }
        // 新方式の up_* チャンクディレクトリ
        foreach (glob($dir . '/*', GLOB_ONLYDIR) ?: [] as $d) {
            if (($now - (int) @filemtime($d)) > $maxAgeSeconds) {
                $this->removeDir($d);
            }
        }
    }

    /** ディレクトリを中身ごと削除する。 */
    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (glob($dir . '/*') ?: [] as $f) {
            if (is_dir($f)) {
                $this->removeDir($f);
            } else {
                @unlink($f);
            }
        }
        @rmdir($dir);
    }

    /** カタログ(ファイル未添付)に動画ファイルを添付してパイプライン開始（フォーム, リダイレクト） */
    public function attach(Request $request, array $params): void
    {
        $videoId = (int) $params['id'];
        $v = Videos::find($videoId);
        if (!$v) {
            Response::notFound('動画が見つかりません');
            return;
        }

        try {
            $relPath = $this->moveUpload($request->file('video'));
        } catch (\RuntimeException $e) {
            Response::redirect('/videos/' . $videoId . '?error=' . rawurlencode($e->getMessage()));
            return;
        }

        $tModel = (string) $request->input('transcribe_model', '');
        $provider = (string) $request->input('translate_provider', '');
        $update = [
            'file_path'     => $relPath,
            'status'        => 'uploaded',
            'error_message' => null,
        ];
        // 出力言語（複数可）。既に言語が登録済みならそれを尊重し、指定があれば足す。
        $targetLangs = self::resolveTargetLangs($request);
        if ($request->input('target_langs') !== null || (string) $request->input('target_lang', '') !== '') {
            $update['target_lang'] = $targetLangs[0];
        }
        if ($provider !== '' && \App\TranslateProviders::isValid($provider)) {
            $update['translate_provider'] = $provider;
            $update['translate_model'] = self::resolveModel($request, $provider);
        }
        if ($tModel !== '' && \App\TranscribeModels::isValid($tModel)) {
            $update['transcribe_model'] = $tModel;
        }
        Videos::update($videoId, $update);
        foreach ($targetLangs as $tl) {
            \App\Repo\VideoLangs::add($videoId, $tl);
        }
        Jobs::enqueue($videoId, 'extract');
        \App\Worker::spawn(); // 即時にバックグラウンド処理を起動
        Response::redirect('/videos/' . $videoId);
    }

    /**
     * 選択プロバイダに対応する翻訳モデルを解決する。
     * フォームは provider ごとに model_<provider> ラジオを持ち、
     * 互換用に hidden translate_model も送る。プロバイダ別に妥当性検証する。
     */
    private static function resolveModel(Request $request, string $provider): string
    {
        // プロバイダ別フィールド → hidden の順で候補を拾う
        $candidate = (string) $request->input('model_' . $provider, '');
        if ($candidate === '') {
            $candidate = (string) $request->input('translate_model', '');
        }

        if ($provider === \App\TranslateProviders::ANTHROPIC) {
            return ($candidate !== '' && \App\Models::isValid($candidate)) ? $candidate : \App\Models::default();
        }

        // 非Claude: そのプロバイダのモデル候補に含まれるか検証
        $choices = \App\TranslateProviders::modelChoices()[$provider] ?? [];
        foreach ($choices as $m) {
            if ($m['id'] === $candidate) {
                return $candidate;
            }
        }
        // 候補に無ければ、設定の既定モデルにフォールバック
        if ($provider === \App\TranslateProviders::GEMINI) {
            return (string) \App\Settings::get('gemini_model', 'gemini-2.5-flash');
        }
        if ($provider === \App\TranslateProviders::OPENAI) {
            return (string) \App\Settings::get('openai_compat_model', '');
        }
        return $candidate;
    }

    /** アップロードファイルを検証して storage/videos へ移動し相対パスを返す */
    private function moveUpload(?array $file): string
    {
        if ($file === null || !isset($file['error'])) {
            throw new \RuntimeException('動画ファイルを選択してください。');
        }
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new \RuntimeException(self::uploadErrorMessage((int) $file['error']));
        }
        $orig = (string) $file['name'];
        $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
        if (!in_array($ext, self::ALLOWED_EXT, true)) {
            throw new \RuntimeException('対応していない形式です（mp4 / mov / mkv / webm / m4v / avi）。');
        }
        if (!is_uploaded_file((string) $file['tmp_name'])) {
            throw new \RuntimeException('不正なアップロードです。');
        }
        $storedName = date('Ymd_His') . '_' . bin2hex(random_bytes(16)) . '.' . $ext;
        $relPath = 'videos/' . $storedName;
        if (!move_uploaded_file((string) $file['tmp_name'], App::storagePath($relPath))) {
            App::log('move_uploaded_file failed: ' . $relPath, 'error');
            throw new \RuntimeException('保存に失敗しました。');
        }
        return $relPath;
    }

    private static function uploadErrorMessage(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE =>
                'ファイルサイズが大きすぎます。サーバーのアップロード上限を確認してください。',
            UPLOAD_ERR_PARTIAL => 'アップロードが途中で中断されました。再試行してください。',
            UPLOAD_ERR_NO_FILE => 'ファイルが選択されていません。',
            default => 'アップロードに失敗しました（コード: ' . $code . '）。',
        };
    }
}
