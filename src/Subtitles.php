<?php

declare(strict_types=1);

namespace App;

/**
 * セグメントから字幕フォーマット(WebVTT / SRT / ASS)を生成する。
 */
final class Subtitles
{
    /** 表示に使うテキスト: 編集訳 > 機械翻訳 > 原文 */
    public static function displayText(array $seg): string
    {
        $t = trim((string) ($seg['edited_translation'] ?? ''));
        if ($t !== '') {
            return $t;
        }
        $t = trim((string) ($seg['machine_translation'] ?? ''));
        if ($t !== '') {
            return $t;
        }
        return trim((string) ($seg['source_text'] ?? ''));
    }

    /** 最低表示時間の既定（秒）: 2行以上 / 1行 */
    public const DEFAULT_MIN_SEC_MULTI = 5.0;
    public const DEFAULT_MIN_SEC_SINGLE = 2.5;
    /** 表示を延ばすとき、次の字幕との間に空けるすき間(ms) */
    public const MIN_GAP_MS = 80;

    /**
     * 最低表示時間（秒）の設定値。[2行以上, 1行]。設定ページで変更でき、0 で無効。
     * @return array{0:float,1:float}
     */
    public static function minDisplaySec(): array
    {
        $read = static function (string $key, float $def): float {
            $v = Settings::get($key, null);
            if ($v === null || !is_numeric($v)) {
                return $def;
            }
            return max(0.0, min(30.0, (float) $v));
        };
        return [
            $read('subtitle_min_sec_multi', self::DEFAULT_MIN_SEC_MULTI),
            $read('subtitle_min_sec_single', self::DEFAULT_MIN_SEC_SINGLE),
        ];
    }

    /**
     * 焼き込み時に何行で表示されるか（手動改行＋自動改行）。空行は数えない。
     * 2行以上かどうかの判定は wrapParagraph と一致する（幅が上限を超えたら2行以上）。
     */
    public static function lineCount(string $text, int $maxChars): int
    {
        $maxChars = max(6, $maxChars);
        $n = 0;
        foreach (preg_split('/\r\n|\r|\n/u', $text) ?: [] as $para) {
            $para = trim($para);
            if ($para === '') {
                continue;
            }
            $n += max(1, (int) ceil(self::textWidth($para) / $maxChars));
        }
        return max(1, $n);
    }

    /**
     * 読み切れるよう、短すぎる字幕の終了時刻を延ばした [start, end] の一覧を返す（DBは変更しない）。
     * 2行以上は minMulti 秒、1行は minSingle 秒を目安に延ばすが、次の字幕の開始
     * （の MIN_GAP_MS 手前）を越えては延ばさない。元の終了時刻より短くすることはない。
     *
     * @return array<int,array{start:int,end:int,text:string}>  表示テキストが空のセグメントは除く
     */
    public static function timedCues(array $segments, int $maxChars): array
    {
        [$minMulti, $minSingle] = self::minDisplaySec();
        $cues = [];
        foreach ($segments as $s) {
            $text = self::displayText($s);
            if ($text === '') {
                continue;
            }
            $start = (int) $s['start_ms'];
            $cues[] = ['start' => $start, 'end' => max($start + 200, (int) $s['end_ms']), 'text' => $text];
        }
        $n = count($cues);
        for ($i = 0; $i < $n; $i++) {
            $c = $cues[$i];
            $minSec = self::lineCount($c['text'], $maxChars) >= 2 ? $minMulti : $minSingle;
            $want = $c['start'] + (int) round($minSec * 1000);
            if ($want <= $c['end']) {
                continue;
            }
            // 次に始まる字幕（開始時刻が自分より後のもの）の手前まで
            $limit = PHP_INT_MAX;
            for ($j = $i + 1; $j < $n; $j++) {
                if ($cues[$j]['start'] > $c['start']) {
                    $limit = $cues[$j]['start'] - self::MIN_GAP_MS;
                    break;
                }
            }
            $cues[$i]['end'] = max($c['end'], min($want, $limit));
        }
        return $cues;
    }

    /** プレビュー用 WebVTT */
    public static function vtt(array $segments, int $width = 1280, int $height = 720): string
    {
        $out = "WEBVTT\n\n";
        $n = 1;
        foreach (self::timedCues($segments, self::maxLineChars($width, $height)) as $c) {
            $out .= $n++ . "\n";
            $out .= ms_to_vtt($c['start']) . ' --> ' . ms_to_vtt($c['end']) . "\n";
            $out .= $c['text'] . "\n\n";
        }
        return $out;
    }

    /** 書き出し用 SRT */
    public static function srt(array $segments, int $width = 1280, int $height = 720): string
    {
        $out = '';
        $n = 1;
        foreach (self::timedCues($segments, self::maxLineChars($width, $height)) as $c) {
            $out .= $n++ . "\n";
            $out .= ms_to_srt($c['start']) . ' --> ' . ms_to_srt($c['end']) . "\n";
            $out .= $c['text'] . "\n\n";
        }
        return $out;
    }

    /**
     * 焼き込みスタイルの寸法を算出する。
     * ass() と「編集画面の1行文字数チェック」で同じ値を使うため、ここに集約する。
     *
     * @return array{fontSize:int,outline:int,shadow:int,marginV:int,marginLR:int,maxChars:int}
     */
    private static function metrics(int $width, int $height): array
    {
        $fontSize = max(24, (int) round($height * 0.078));
        $outline  = max(2,  (int) round($fontSize * 0.050)); // 黒縁の太さ
        $shadow   = max(1,  (int) round($fontSize * 0.030)); // 柔らかな影
        $marginV  = max(6, (int) round($height * 0.018));
        // 左右余白は最小限にして、字幕を画面幅いっぱいに使う（端の見切れ防止に僅かだけ残す）
        $marginLR = max(4, (int) round($width * 0.005));
        // 1行に収まる全角文字数（はみ出し防止の自動改行に使用）
        $usableW  = max(1, $width - 2 * $marginLR);
        // 全角1文字の実効字送りは概ねフォントサイズの0.7前後（libass実測）。
        // 以前は大きく見積もりすぎて早く折り返し、左右に余白が残っていた。
        // 少しだけ安全側(0.78)に見積もり、縁取り・影のはみ出し分も差し引く。
        $advance  = $fontSize * 0.78;
        $safeW    = max(1, $usableW - 2 * ($outline + $shadow));
        $maxChars = max(8, (int) floor($safeW / $advance));
        // AI翻訳側で指定した1行最大文字数があれば、それを上限として合わせる（編集後テキストやTM一致にも適用）。
        // 画面に収まる上限(上の算出)は安全天井として残す。
        $cfgMax = (int) App::config('subtitle_max_line_chars', 0);
        if ($cfgMax > 0) {
            $maxChars = max(6, min($maxChars, $cfgMax));
        }
        return [
            'fontSize' => $fontSize, 'outline' => $outline, 'shadow' => $shadow,
            'marginV' => $marginV, 'marginLR' => $marginLR, 'maxChars' => $maxChars,
        ];
    }

    /**
     * 焼き込み時に自動改行される「1行の上限文字数（全角換算）」。
     * 編集画面で長すぎる行に警告を出すために使う。焼き込みと同じ計算。
     */
    public static function maxLineChars(int $width = 1280, int $height = 720): int
    {
        return self::metrics($width, $height)['maxChars'];
    }

    /**
     * 焼き込み用 ASS。訳出先の言語に合ったフォントで読みやすいスタイルにする。
     * @param int    $width      動画幅(px)。PlayResX用。
     * @param int    $height     動画高さ(px)。PlayResY用。
     * @param string $targetLang 訳出先の言語（フォント選択に使う）
     */
    public static function ass(
        array $segments,
        int $width = 1280,
        int $height = 720,
        string $targetLang = TargetLangs::DEFAULT
    ): string {
        [
            'fontSize' => $fontSize, 'outline' => $outline, 'shadow' => $shadow,
            'marginV' => $marginV, 'marginLR' => $marginLR, 'maxChars' => $maxChars,
        ] = self::metrics($width, $height);
        $font = TargetLangs::font($targetLang);

        $header = "[Script Info]\n"
            . "ScriptType: v4.00+\n"
            . "PlayResX: {$width}\n"
            . "PlayResY: {$height}\n"
            . "WrapStyle: 0\n"
            . "ScaledBorderAndShadow: yes\n\n"
            . "[V4+ Styles]\n"
            . "Format: Name, Fontname, Fontsize, PrimaryColour, SecondaryColour, OutlineColour, BackColour, "
            . "Bold, Italic, Underline, StrikeOut, ScaleX, ScaleY, Spacing, Angle, BorderStyle, Outline, Shadow, "
            . "Alignment, MarginL, MarginR, MarginV, Encoding\n"
            . "Style: Default,{$font},{$fontSize},&H00FFFFFF,&H000000FF,&H00000000,&H80000000,"
            . "-1,0,0,0,100,100,0,0,1,{$outline},{$shadow},2,{$marginLR},{$marginLR},{$marginV},1\n\n"
            . "[Events]\n"
            . "Format: Layer, Start, End, Style, Name, MarginL, MarginR, MarginV, Effect, Text\n";

        $events = '';
        foreach (self::timedCues($segments, $maxChars) as $c) {
            $text = $c['text'];
            $start = $c['start'];
            $end = $c['end'];
            // ASSの改行は \N、波括弧はエスケープ
            $text = str_replace(['{', '}'], ['(', ')'], $text);
            // 画面外へはみ出さないよう長い行を自動改行（手動改行も尊重）
            $text = self::wrapForAss($text, $maxChars);
            $events .= 'Dialogue: 0,' . ms_to_ass($start) . ',' . ms_to_ass($end)
                . ',Default,,0,0,0,,' . $text . "\n";
        }

        return $header . $events;
    }

    /**
     * 焼き込み字幕が画面外へはみ出さないよう、長い行を \N で折り返す。
     * 既存の手動改行（\r\n / \n）は尊重し、各段落を全角換算 $maxChars 以内に
     * 均等折り返しする（参考画像のように行の長さをそろえる）。
     */
    private static function wrapForAss(string $text, int $maxChars): string
    {
        $maxChars = max(6, $maxChars);
        $paras = preg_split('/\r\n|\r|\n/u', $text);
        $out = [];
        foreach ($paras as $para) {
            foreach (self::wrapParagraph($para, $maxChars) as $line) {
                $out[] = $line;
            }
        }
        return implode('\\N', $out);
    }

    /**
     * 1段落（改行なし）を全角換算 $maxChars 以内の複数行へ均等分割する。
     * 改行できるのは「トークンの境目」だけ。全角文字は1文字＝1トークン（どこでも切れる）、
     * 半角文字の連続は1トークン（英単語などを途中で切らない）として扱う。
     */
    private static function wrapParagraph(string $para, int $maxChars): array
    {
        $para = trim($para);
        if ($para === '') {
            return [''];
        }
        $total = self::textWidth($para);
        if ($total <= $maxChars) {
            return [$para];
        }
        // 必要行数から、各行が均等になる目標幅を決める
        $numLines = (int) ceil($total / $maxChars);
        $target   = $total / $numLines;

        $tokens = self::tokenize($para);
        $lines = [];
        $cur = '';
        $curW = 0.0;
        $n = count($tokens);
        for ($i = 0; $i < $n; $i++) {
            $tok = $tokens[$i];
            $tw = self::textWidth($tok);

            // 入り切らないなら先に改行する（半角の単語を途中で切らないための事前判定）
            if ($cur !== '' && ($curW + $tw) > $maxChars) {
                $lines[] = rtrim($cur);
                $cur = '';
                $curW = 0.0;
            }

            $cur .= $tok;
            $curW += $tw;

            // 均等割り: 目標幅に達したら改行（最終行の手前まで）
            $isLast = ($i === $n - 1);
            if (!$isLast && count($lines) < $numLines - 1 && $curW >= $target) {
                // 次が行頭禁則の約物なら、上限内であれば現在行にぶら下げる
                $next = $tokens[$i + 1];
                if (self::isNoStart($next) && ($curW + self::textWidth($next)) <= $maxChars) {
                    continue;
                }
                $lines[] = rtrim($cur);
                $cur = '';
                $curW = 0.0;
            }
        }
        if (trim($cur) !== '') {
            $lines[] = rtrim($cur);
        }
        return $lines ?: [$para];
    }

    /**
     * 段落を折り返し候補の単位（トークン）に分ける。
     *  - 全角文字: 1文字ごと（日本語・中国語は文字単位で改行できる）
     *  - 半角文字の連続: まとめて1つ（英単語や数値を途中で切らない）
     *  - 半角スペース: 直前のトークンに含めて区切りにする（行末では trim される）
     * @return array<int,string>
     */
    private static function tokenize(string $para): array
    {
        $chars = preg_split('//u', $para, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $tokens = [];
        $buf = '';
        foreach ($chars as $c) {
            if ($c === ' ') {
                $buf .= ' ';
                $tokens[] = $buf;
                $buf = '';
                continue;
            }
            if (self::charWidth($c) >= 1.0) {
                if ($buf !== '') {
                    $tokens[] = $buf;
                    $buf = '';
                }
                $tokens[] = $c;
                continue;
            }
            $buf .= $c;
        }
        if ($buf !== '') {
            $tokens[] = $buf;
        }
        return $tokens;
    }

    /** 行頭に置かない約物（行頭禁則）だけのトークンか */
    private static function isNoStart(string $token): bool
    {
        static $noStart = ['、','。','，','．','・','…','〜','ー','！','？','!','?','」','』','）',')','】','〉','》','”','’','：',':','；',';',',','.'];
        return in_array(rtrim($token), $noStart, true);
    }

    /** 文字列の表示幅（全角換算）。 */
    public static function textWidth(string $text): float
    {
        $w = 0.0;
        foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $c) {
            $w += self::charWidth($c);
        }
        return $w;
    }

    /** 文字の表示幅（全角=1.0 / 半角=0.5）。折り返し計算に使う。 */
    private static function charWidth(string $ch): float
    {
        $o = function_exists('mb_ord') ? mb_ord($ch, 'UTF-8') : ord($ch);
        if ($o === false) {
            return 1.0;
        }
        // ラテン(〜U+00FF)・半角カナは半角、それ以外(かな/漢字/全角約物)は全角
        if ($o <= 0x00FF || ($o >= 0xFF61 && $o <= 0xFF9F)) {
            return 0.5;
        }
        return 1.0;
    }

}
