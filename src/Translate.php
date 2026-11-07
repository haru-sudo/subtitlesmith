<?php

declare(strict_types=1);

namespace App;

use App\Clients\OpenAIClient;
use App\Repo\Glossary;
use App\Repo\Segments;
use App\Repo\Tm;

/**
 * 字幕翻訳（検索拡張翻訳 / RAG）。分野は設定 content_domain / translation_guidelines で指定できる。
 * - 1リクエストで複数セグメントを同期翻訳
 * - システムプロンプト（翻訳ガイド）は Prompt Caching で再利用
 * - 各バッチごとに「用語集の該当語（用語制約）」と「類似する過去の確定訳（TMのfew-shot）」を
 *   ユーザーメッセージに注入し、使うほど精度が上がるようにする
 *   ※ 用語集はキー不要。TM検索は埋め込み(OpenAI)が必要だが、失敗/未設定でも安全に従来動作。
 *
 * 訳出先は動画ごとの target_lang（日本語・中国語など。既定は日本語）。
 * 用語集・翻訳メモリも訳出先ごとに分けて参照するので、言語をまたいで混ざることはない。
 *
 * 注: プロバイダとの受け渡しJSONのキーは互換のため "ja" のままだが、
 *     意味は「訳文」であって日本語に限らない。
 */
final class Translate
{
    private const TM_PER_SEGMENT = 3;   // 1セグメントあたりのTM参照数
    private const TM_MAX_EXAMPLES = 8;  // バッチ全体のTM例の上限
    private const GLOSSARY_MAX = 40;    // 注入する用語数の上限

    /**
     * 動画を1つの言語へ翻訳する。多言語の動画では言語ごとに呼ばれる。
     * @param string|null $targetLang 訳出先。未指定なら動画の主言語。
     */
    public static function translateVideo(int $videoId, ?string $targetLang = null): void
    {
        $video = App::db()->fetch(
            'SELECT source_lang, target_lang, translate_model, translate_provider FROM videos WHERE id = :v',
            [':v' => $videoId]
        ) ?? [];
        $lang = (string) ($video['source_lang'] ?? 'auto');
        $target = ($targetLang !== null && $targetLang !== '')
            ? \App\TargetLangs::normalize($targetLang)
            : \App\TargetLangs::normalize($video['target_lang'] ?? null);

        $segments = Segments::forVideo($videoId, $target);
        if (!$segments) {
            return;
        }
        $providerId = (string) ($video['translate_provider'] ?? '');
        $model = (string) ($video['translate_model'] ?? '');
        // Claudeのときだけ、未指定/不正ならClaude既定にフォールバック。
        // 他プロバイダのモデルIDはClaudeの検証に通らないのでそのまま使う。
        if (($providerId === '' || $providerId === \App\TranslateProviders::ANTHROPIC)
            && ($model === '' || !\App\Models::isValid($model))) {
            $model = \App\Models::default();
        }

        $batchSize = max(1, (int) App::config('translate_batch_size', 10));
        $provider = \App\TranslateProviders::make($providerId);
        $openai = new OpenAIClient();
        $tmIndex = Tm::loadIndex($lang, $target); // 空なら検索しない

        // 全原文を用意（隣接文脈と完全一致判定に使う）
        $allTexts = array_map(static fn($s) => (string) $s['source_text'], $segments);

        // 確定訳と完全一致する原文は、APIを呼ばずにその訳を採用（コスト削減＋精度向上）
        $exact = Tm::exactMatches($allTexts, $lang, $target);
        $exactHits = 0;

        $chunks = array_chunk($segments, $batchSize, true);
        $totalBatches = count($chunks);
        $doneBatches = 0;
        $langLabel = \App\TargetLangs::label($target);
        \App\Repo\Videos::setProgress($videoId, 0, $langLabel . '訳 0/' . $totalBatches);
        foreach ($chunks as $batch) {
            // このバッチで、確定訳に完全一致するものは即確定し、残りだけAPIへ
            $toTranslate = [];
            foreach ($batch as $seg) {
                $id = (int) $seg['id'];
                $src = (string) $seg['source_text'];
                $key = Tm::normalizeKey($src);
                if (isset($exact[$key]) && $exact[$key] !== '') {
                    Segments::setMachineTranslation($id, self::cleanTarget($exact[$key], $target), $target);
                    $exactHits++;
                } else {
                    $toTranslate[] = ['id' => $id, 'text' => $src];
                }
            }
            if ($toTranslate) {
                // 隣接文脈（このバッチの前後の原文を、訳出せず参考として渡す）
                $context = self::neighborContext($segments, $toTranslate);
                $map = self::translateBatch($provider, $openai, $toTranslate, $lang, $target, $tmIndex, $model, $context);
                foreach ($toTranslate as $it) {
                    $id = (int) $it['id'];
                    if (isset($map[$id]) && $map[$id] !== '') {
                        Segments::setMachineTranslation($id, self::cleanTarget($map[$id], $target), $target);
                    }
                }
            }
            $doneBatches++;
            $pct = (int) round($doneBatches / max(1, $totalBatches) * 100);
            \App\Repo\Videos::setProgress($videoId, $pct, "{$langLabel}訳 {$doneBatches}/{$totalBatches}");
        }

        if ($exactHits > 0) {
            App::log("確定訳の完全一致でAPIを節約: {$exactHits}件 (video #{$videoId} / {$target})", 'translate');
        }
    }

    /**
     * バッチ対象の前後にある原文を「文脈ヒント」として最大数件返す。
     * 字幕は前後の流れに依存するため、訳の一貫性・正確さが上がる（追加コストは僅少）。
     * @param array<int,array> $allSegments idつき全セグメント（順序＝時系列）
     * @param array<int,array{id:int,text:string}> $batchItems 翻訳対象
     * @return array{before:array<int,string>, after:array<int,string>}
     */
    private static function neighborContext(array $allSegments, array $batchItems): array
    {
        $N = 2; // 前後それぞれ最大2文
        $ids = array_column($allSegments, 'id');
        $batchIds = array_column($batchItems, 'id');
        $first = $batchIds[0] ?? null;
        $last = $batchIds[count($batchIds) - 1] ?? null;
        $firstPos = array_search($first, $ids, true);
        $lastPos = array_search($last, $ids, true);

        $before = [];
        if ($firstPos !== false) {
            for ($i = max(0, $firstPos - $N); $i < $firstPos; $i++) {
                $before[] = (string) $allSegments[$i]['source_text'];
            }
        }
        $after = [];
        if ($lastPos !== false) {
            $cnt = count($allSegments);
            for ($i = $lastPos + 1; $i < min($cnt, $lastPos + 1 + $N); $i++) {
                $after[] = (string) $allSegments[$i]['source_text'];
            }
        }
        return ['before' => $before, 'after' => $after];
    }

    /**
     * 1バッチを翻訳し [segmentId => 訳文] を返す。
     * @param array<int,array{id:int,text:string}> $items
     * @param array<int,array> $tmIndex
     */
    private static function translateBatch(
        \App\Translators\TranslatorInterface $provider,
        OpenAIClient $openai,
        array $items,
        string $lang,
        string $target,
        array $tmIndex,
        string $model = '',
        array $context = ['before' => [], 'after' => []]
    ): array {
        // 1パス目: バッチ全体を翻訳
        $map = self::translateChunk($provider, $openai, $items, $lang, $target, $tmIndex, $model, $context);

        // 応答の切り詰め・取りこぼしで欠けた訳を、より小さい単位に分割して取り直す。
        // （長尺の後半は字幕が密で1応答に収まらず欠落→精度低下に見えることがあるため）
        $map = self::recoverMissing($provider, $openai, $items, $map, $lang, $target, $tmIndex, $model, $context, 0);

        // 精度優先: 2パス目で自己校正（誤りやすいセグメントのみ・任意）
        $refineOn = \App\Settings::getBool('translate_refine', false);
        if ($refineOn && $map) {
            $sources = array_column($items, 'text');
            $glossary = array_slice(Glossary::matchInTexts($sources, $lang, $target), 0, self::GLOSSARY_MAX);
            $suspect = self::selectForRefine($items, $map, $glossary);
            if ($suspect) {
                try {
                    $refined = self::refineBatch($provider, $suspect, $map, $lang, $target, $glossary, $model);
                    foreach ($refined as $id => $ja) {
                        $map[$id] = $ja;
                    }
                } catch (\Throwable $e) {
                    App::log('2パス校正に失敗（1パス結果を採用）: ' . $e->getMessage(), 'translate');
                }
            }
        }

        return $map;
    }

    /**
     * 1回分の翻訳呼び出し（用語集・TM例・文脈を付けてJSONで受け取る）。
     * @param array<int,array{id:int,text:string}> $items
     * @return array<int,string> id => 訳文
     */
    private static function translateChunk(
        \App\Translators\TranslatorInterface $provider,
        OpenAIClient $openai,
        array $items,
        string $lang,
        string $target,
        array $tmIndex,
        string $model,
        array $context
    ): array {
        if (!$items) {
            return [];
        }
        $sources = array_column($items, 'text');

        // 用語集（部分一致・埋め込み不要）
        $glossary = array_slice(Glossary::matchInTexts($sources, $lang, $target), 0, self::GLOSSARY_MAX);
        // 翻訳メモリ（埋め込み類似検索）
        $examples = self::retrieveTmExamples($openai, $sources, $tmIndex);

        $count = count($items);
        // 切り詰め防止のため1セグメントあたりの予算を多めに確保
        $maxTokens = min(8000, 400 * $count + 1200);

        $data = $provider->translateJson(
            self::systemText($target),
            self::userPrompt($items, $lang, $target, $glossary, $examples, $context),
            $maxTokens,
            $model
        );

        $map = [];
        if (isset($data['translations']) && is_array($data['translations'])) {
            foreach ($data['translations'] as $t) {
                if (isset($t['id'])) {
                    $map[(int) $t['id']] = self::translatedText($t);
                }
            }
        }
        return $map;
    }

    /**
     * 応答1件から訳文を取り出す。
     * 正規のキーは互換のため "ja"（意味は「訳文」で日本語に限らない）。
     * 訳出先が日本語以外だとモデルが "text"/"target"/"translation" で返すことがあるため、
     * それらも拾って取りこぼしを防ぐ。
     */
    private static function translatedText(array $row): string
    {
        foreach (['ja', 'text', 'target', 'translation'] as $key) {
            if (isset($row[$key]) && is_scalar($row[$key])) {
                $v = trim((string) $row[$key]);
                if ($v !== '') {
                    return $v;
                }
            }
        }
        return '';
    }

    /**
     * 欠けた（未訳・空の）idを、半分ずつに分割して取り直す。
     * 1応答に入り切らずに後半が落ちるケースを救済する。再帰は深さで打ち切る。
     * @param array<int,array{id:int,text:string}> $items
     * @param array<int,string> $map
     * @return array<int,string>
     */
    private static function recoverMissing(
        \App\Translators\TranslatorInterface $provider,
        OpenAIClient $openai,
        array $items,
        array $map,
        string $lang,
        string $target,
        array $tmIndex,
        string $model,
        array $context,
        int $depth
    ): array {
        $missing = array_values(array_filter(
            $items,
            static fn($it) => empty($map[(int) $it['id']])
        ));
        if (!$missing || $depth > 4) {
            return $map;
        }

        // 1件なら単独で取り直して終了（これ以上分割できない）
        if (count($missing) === 1) {
            $sub = self::translateChunk($provider, $openai, $missing, $lang, $target, $tmIndex, $model, $context);
            foreach ($sub as $id => $text) {
                if ($text !== '') {
                    $map[(int) $id] = $text;
                }
            }
            return $map;
        }

        // 欠けた分を半分ずつにして取り直し、なお欠けるものは再帰的に細かく
        $halfSize = (int) ceil(count($missing) / 2);
        foreach (array_chunk($missing, max(1, $halfSize)) as $half) {
            $sub = self::translateChunk($provider, $openai, $half, $lang, $target, $tmIndex, $model, $context);
            foreach ($sub as $id => $text) {
                if ($text !== '') {
                    $map[(int) $id] = $text;
                }
            }
            $map = self::recoverMissing($provider, $openai, $half, $map, $lang, $target, $tmIndex, $model, $context, $depth + 1);
        }
        return $map;
    }

    /**
     * 2パス校正の対象（誤りやすいセグメント）を選ぶ。
     * - 用語集の語を含む（用語の取りこぼし・表記ゆれが起きやすい）
     * - 原文が長い（複雑で誤訳しやすい）
     * - 訳が空 or 極端に短い/長い（怪しい）
     * 該当が多すぎる場合に備え、用語集が空なら「全件」を返して従来動作を保つ。
     * @param array<int,array{id:int,text:string}> $items
     * @param array<int,string> $map id => 訳
     * @param array<int,array{source_term:string,target_term:string,note:?string}> $glossary
     * @return array<int,array{id:int,text:string}>
     */
    private static function selectForRefine(array $items, array $map, array $glossary): array
    {
        if (!$glossary) {
            // 用語制約が無い場合は全件校正（従来通りの精度優先動作）
            return $items;
        }
        $terms = [];
        foreach ($glossary as $g) {
            $t = mb_strtolower((string) $g['source_term']);
            if ($t !== '') {
                $terms[] = $t;
            }
        }
        $suspect = [];
        foreach ($items as $it) {
            $id = (int) $it['id'];
            $src = (string) $it['text'];
            $low = mb_strtolower($src);
            $ja = (string) ($map[$id] ?? '');

            $hasTerm = false;
            foreach ($terms as $t) {
                if (mb_strpos($low, $t) !== false) {
                    $hasTerm = true;
                    break;
                }
            }
            $isLong = mb_strlen($src) >= 80;
            $isOddLen = $ja === '' || mb_strlen($ja) < 2 || mb_strlen($ja) > mb_strlen($src) * 3;

            if ($hasTerm || $isLong || $isOddLen) {
                $suspect[] = ['id' => $id, 'text' => $src];
            }
        }
        return $suspect;
    }

    /**
     * 2パス目の自己校正。1パス目の訳を、原文・用語集に照らして見直させる。
     * コストは概ね2倍になるが、用語の取りこぼしや不自然な訳を減らせる（精度優先）。
     *
     * @param array<int,array{id:int,text:string}> $items
     * @param array<int,string> $firstPass id => 訳
     * @param array<int,array{source_term:string,target_term:string,note:?string}> $glossary
     * @return array<int,string>
     */
    private static function refineBatch(
        \App\Translators\TranslatorInterface $provider,
        array $items,
        array $firstPass,
        string $lang,
        string $target,
        array $glossary,
        string $modelOverride = ''
    ): array {
        $targetName = \App\TargetLangs::name($target);
        // 原文と1パス訳を対にして提示
        $pairs = [];
        foreach ($items as $it) {
            $id = (int) $it['id'];
            $pairs[] = ['id' => $id, 'source' => (string) $it['text'], 'draft' => (string) ($firstPass[$id] ?? '')];
        }

        $lines = [];
        if ($glossary) {
            $lines[] = '# 用語集（必ずこの訳語を使用）';
            foreach ($glossary as $g) {
                $lines[] = '- ' . $g['source_term'] . ' → ' . $g['target_term']
                    . (!empty($g['note']) ? '（' . $g['note'] . '）' : '');
            }
            $lines[] = '';
        }
        $lines[] = '# 校正タスク';
        $lines[] = "以下は原文と{$targetName}訳の下書きです。各訳を原文と用語集に照らして見直し、";
        $lines[] = "誤訳・用語の不一致・不自然な{$targetName}があれば修正してください。";
        $lines[] = '問題なければ下書きのまま返してください。意味を変えたり情報を足したりしないこと。';
        $lines[] = '下書きにある改行は、意味の切れ目として適切なら維持する。1行が長い場合は意味の切れ目で改行し、不要に長い行を作らない。';
        $lines[] = "訳文は必ず{$targetName}で書くこと。";
        $lines[] = '出力は {"translations":[{"id":整数,"ja":"訳文"}, ...]} のJSON形式で、各idに対応する最終訳のみを返すこと'
            . '（キー名は "ja" のまま固定。中身は上記の訳出先言語で書く）。';
        $lines[] = '';
        $lines[] = json_encode($pairs, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        $data = $provider->translateJson(
            self::systemText($target),
            implode("\n", $lines),
            min(8000, 300 * count($items) + 800),
            $modelOverride
        );

        $refined = $firstPass;
        if (isset($data['translations']) && is_array($data['translations'])) {
            foreach ($data['translations'] as $t) {
                if (isset($t['id'])) {
                    $text = self::translatedText($t);
                    if ($text !== '') {
                        $refined[(int) $t['id']] = $text;
                    }
                }
            }
        }
        return $refined;
    }

    /**
     * バッチ原文に類似する過去の確定訳を取得（重複排除・スコア順）。
     * @return array<int,array{source:string,target:string,score:float}>
     */
    private static function retrieveTmExamples(OpenAIClient $openai, array $sources, array $tmIndex): array
    {
        if (!$tmIndex || !$sources) {
            return [];
        }
        try {
            $queryVecs = $openai->embed($sources);
        } catch (\Throwable $e) {
            App::log('TM検索の埋め込み取得に失敗（用語集のみで翻訳）: ' . $e->getMessage(), 'translate');
            return [];
        }

        $pool = [];
        foreach ($queryVecs as $i => $qv) {
            foreach (Tm::searchInIndex($qv, $tmIndex, self::TM_PER_SEGMENT) as $h) {
                $key = $h['source_text'];
                if (!isset($pool[$key]) || $pool[$key]['score'] < $h['score']) {
                    $pool[$key] = ['source' => $key, 'target' => $h['target_text'], 'score' => $h['score']];
                }
            }
        }
        $list = array_values($pool);
        usort($list, static fn($a, $b) => $b['score'] <=> $a['score']);
        return array_slice($list, 0, self::TM_MAX_EXAMPLES);
    }

    /**
     * 字幕用に訳文を整える。改行まわりを正規化し、
     * 日本語・中国語では字幕慣習として行末の句点（。/．）を落とす。
     * 文中の句点は保持する。句点を打たない言語（英語など）では除去しない。
     */
    private static function cleanTarget(string $s, string $target): string
    {
        $s = trim($s);
        // 改行を正規化: CRLF→LF、各行頭末の空白除去、空行は1つに（AIが入れた改行は保持）
        $s = str_replace(["\r\n", "\r"], "\n", $s);
        $s = preg_replace('/[ \t　]*\n[ \t　]*/u', "\n", $s) ?? $s;
        $s = preg_replace('/\n{2,}/u', "\n", $s) ?? $s;
        if (\App\TargetLangs::dropsSentencePeriod($target)) {
            // 文字列末の句点（。/．）と前後の空白を除去。文中の句点は保持。
            $s = preg_replace('/[。．]+[\s　]*$/u', '', $s) ?? $s;
        }
        return trim($s);
    }

    /**
     * システムプロンプト（翻訳ルール・プレーンテキスト）。
     * 訳出先の言語ごとに文面が変わるが、同じ言語なら毎回同一なので
     * Prompt Caching はそのまま効く。
     */
    private static function systemText(string $target): string
    {
        $targetName = \App\TargetLangs::name($target);
        $isWide = \App\TargetLangs::isWide($target);

        $maxLine = max(0, (int) App::config('subtitle_max_line_chars', 20));
        $lineRule = '';
        if ($maxLine > 0) {
            // 全角=1文字/半角=0.5文字で数えるのは共通。ラテン文字の言語には半角換算でも示す。
            $limit = $isWide
                ? "全角{$maxLine}文字"
                : '半角' . ($maxLine * 2) . '文字（全角換算で' . $maxLine . '文字）';
            $lineRule = "\n# 1行の長さ・改行（字幕の読みやすさ）\n"
                . "- 1行は{$limit}以内にする（全角は1文字、半角は0.5文字として数える）。\n"
                . "- 改行は最小限にする。訳が{$limit}を超えるときだけ改行し、収まる場合は改行しない。\n"
                . "- 改行が必要な場合は、必ず意味の切れ目（文節・句のまとまり、句読点の位置など）で行う。単語の途中や、修飾語とその対象の間など、不自然な位置では絶対に改行しない。\n"
                . "- 自然に切れる場所が見つからない場合は、無理に改行せず1行のまま{$limit}を多少超えても構わない。\n"
                . "- 改行は原則2行まで（やむを得ず長いときのみ3行）。各行の長さができるだけ均等になるように分ける。\n"
                . "- 行頭に句読点や閉じ括弧（、。，．」』）！？）を置かない。\n"
                . "- 改行は ja の文字列の中に通常の改行として入れる（JSONとして正しくエスケープすること）。\n";
        }

        // 動画の分野（設定 content_domain）。空なら汎用の字幕翻訳者として振る舞う。
        $domain = trim((string) App::config('content_domain', ''));
        $role = $domain !== ''
            ? "あなたは{$domain}に精通したプロの字幕翻訳者です。\n"
                . "{$domain}の動画の音声を文字起こししたテキストを、自然で正確な{$targetName}字幕に翻訳します。"
            : "あなたはプロの字幕翻訳者です。\n"
                . "動画の音声を文字起こししたテキストを、自然で正確な{$targetName}字幕に翻訳します。";
        $termRule = $domain !== ''
            ? "- {$domain}の専門用語・表現を正しく訳す。日常語の直訳で不自然にならないよう、{$domain}の文脈で解釈する。\n"
                . "  専門用語は{$targetName}の{$domain}で標準的に使われている訳語を使う。\n"
            : "- 専門用語・表現は、その分野で{$targetName}として標準的に使われている訳語を使う。日常語の直訳で不自然にならないよう、動画の文脈で解釈する。\n";

        // 分野固有の追加ガイド（設定 translation_guidelines）
        $extra = trim((string) App::config('translation_guidelines', ''));
        $extraRule = $extra !== '' ? "\n# 分野固有のガイド\n" . $extra . "\n" : '';

        // 句点の扱いは言語ごとの字幕作法に合わせる
        $punctRule = \App\TargetLangs::dropsSentencePeriod($target)
            ? "- 文末に句点（。）を付けない。読点は読みやすさに必要な最小限のみ。\n"
            : "- 字幕として自然な句読点にとどめ、不要な記号は足さない。\n";

        return <<<TXT
        {$role}

        # 翻訳方針
        - 訳文は必ず{$targetName}で書く。原文の言語や、この指示文の言語（日本語）に引きずられないこと。
        {$termRule}- 提示される「用語集」の訳語は必ず優先して使用する。
        - 提示される「過去の確定訳の例」の語彙・言い回しに極力合わせ、一貫性を保つ。
        - 字幕は短く。意味が正しく伝わる範囲で、できるだけ少ない文字数にする。
          冗長な言い回し・自明な主語や代名詞・不要な接続詞は削る。ただし意味は絶対に削らない。
        {$punctRule}- 説明や注釈を勝手に足さない。
        - 意味を変えない。聞き取り誤り由来の不明瞭な箇所は文脈から最も妥当な解釈にする。
        - 固有名詞（人名・地名・団体名・作品名など）は{$targetName}で一般的な表記に。原綴りのままが自然なら原綴り可。
        {$lineRule}{$extraRule}
        - 出力は必ず {"translations":[{"id":整数,"ja":"訳文"}, ...]} のJSON形式で、各入力idに対応する訳文のみを返す。
          キー名は互換のため "ja" で固定だが、その中身は{$targetName}で書くこと。
        TXT;
    }

    /**
     * ユーザーメッセージ（用語集・TM例などの動的文脈＋翻訳対象）。
     * キャッシュを壊さないよう動的文脈はsystemではなくここに置く。
     * @param array<int,array{id:int,text:string}> $items
     * @param array<int,array{source_term:string,target_term:string,note:?string}> $glossary
     * @param array<int,array{source:string,target:string,score:float}> $examples
     */
    private static function userPrompt(array $items, string $lang, string $target, array $glossary, array $examples, array $context = ['before' => [], 'after' => []]): string
    {
        $langName = \App\TargetLangs::sourceName($lang);
        $targetName = \App\TargetLangs::name($target);

        $parts = [];

        if ($glossary) {
            $lines = ['# 用語集（必ずこの訳語を使用）'];
            foreach ($glossary as $g) {
                $line = '- ' . $g['source_term'] . ' → ' . $g['target_term'];
                if (!empty($g['note'])) {
                    $line .= '（' . $g['note'] . '）';
                }
                $lines[] = $line;
            }
            $parts[] = implode("\n", $lines);
        }

        if ($examples) {
            $lines = ['# 参考: 過去の確定訳の例（語彙・言い回しを合わせる）'];
            foreach ($examples as $ex) {
                $lines[] = '- ' . $ex['source'] . ' → ' . $ex['target'];
            }
            $parts[] = implode("\n", $lines);
        }

        // 隣接文脈（訳出しないが、文脈理解のために提示）。一貫性・正確さ向上。
        $before = $context['before'] ?? [];
        $after  = $context['after'] ?? [];
        if ($before || $after) {
            $lines = ['# 文脈（前後のセリフ。これらは訳さない。話の流れの理解にのみ使う）'];
            foreach ($before as $b) {
                $lines[] = '（前）' . $b;
            }
            $lines[] = '（↓ここからが翻訳対象↓）';
            foreach ($after as $a) {
                $lines[] = '（後）' . $a;
            }
            $parts[] = implode("\n", $lines);
        }

        $json = json_encode($items, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $parts[] = "# 翻訳対象\n"
            . "次の{$langName}の字幕セグメントを{$targetName}に翻訳してください。"
            . "各セグメントは id と text を持ちます。id ごとに{$targetName}の訳文を ja に入れて返してください。\n\n"
            . $json;

        return implode("\n\n", $parts);
    }
}
