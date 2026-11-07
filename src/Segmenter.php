<?php

declare(strict_types=1);

namespace App;

/**
 * 文字起こしのセグメント境界を「文の区切り」に合わせ直す後処理。
 *
 * Deepgram/Whisper が返す生セグメントは utterances（発話＝ポーズ区切り）や
 * 無音区切りが基準のため、1つの区切りに複数の文が入ったり、逆に1つの文が
 * ポーズで分断されたりする。そのままだと字幕が読みにくく、翻訳も文の断片を
 * 訳すことになり精度が落ちる。
 *
 * ここでは全セグメントを「時刻つき文字ストリーム」に展開し、
 *   1) 文末記号（. ! ? 。！？…）で「文」に割り直す（発話境界をまたいで結合／分割）
 *   2) 長い無音が空いた所は、文末記号が無くても切る
 *   3) 長すぎる文は区切り（空白・読点）で分割
 * を行う。
 *
 * 各文字の時刻は、単語ごとの時刻(words)があればそれを使う。無ければセグメントの
 * [start,end] を文字数比で線形補間する（＝話速が一定という仮定なので、発話が長い
 * ほど、また文中の間が大きいほど境界がずれる）。時刻の精度は前者が段違いに良いので、
 * 文字起こし側は可能な限り words を添えること。
 * 文字起こしエンジン非依存（ローカル/APIどちらの結果にも使える）。
 */
final class Segmenter
{
    /** 分割対象とする文字数（長い1文はここを超えると分割） */
    private const SPLIT_MAX_CHARS = 100;
    /** 分割対象とする尺(ms) */
    private const SPLIT_MAX_DUR_MS = 8000;
    /** 分割時の1ピースの目安文字数 */
    private const TARGET_CHARS = 64;
    /** これ未満の極端に短い文は直前の文に連結する（一語だけの断片を防ぐ） */
    private const MERGE_MIN_CHARS = 3;
    /**
     * これ以上の無音が空いたら、文末記号が無くてもそこで文を切る(ms)。
     * 文末記号を付け損ねた発話どうしが連結され、字幕が無音区間をまたいで
     * 引き伸ばされる（＝音声より早く出て長く残る）のを防ぐ。
     */
    private const GAP_SPLIT_MS = 1200;

    /**
     * @param array<int,array{start_ms:int,end_ms:int,text:string,words?:array<int,array{text:string,start_ms:int,end_ms:int}>}> $segments
     * @return array<int,array{start_ms:int,end_ms:int,text:string}>
     */
    public static function resegment(array $segments, string $lang = ''): array
    {
        $segs = [];
        foreach ($segments as $s) {
            $t = trim((string) ($s['text'] ?? ''));
            if ($t === '') {
                continue;
            }
            $st = (int) ($s['start_ms'] ?? 0);
            $en = (int) ($s['end_ms'] ?? 0);
            if ($en < $st) {
                $en = $st;
            }
            $segs[] = [
                'start_ms' => $st,
                'end_ms'   => $en,
                'text'     => $t,
                'words'    => is_array($s['words'] ?? null) ? $s['words'] : [],
            ];
        }
        if (!$segs) {
            return $segments;
        }
        usort($segs, static fn($a, $b) => $a['start_ms'] <=> $b['start_ms']);

        $cjk = self::isCjk($lang);
        $sep = $cjk ? '' : ' ';

        // 1) 全セグメントを「時刻つき文字ストリーム」に展開する。
        //    セグメント間には区切り文字(空白/なし)を挟み、発話境界をまたいで文を再構成できるようにする。
        //    区切りatomは前セグメント終了〜次セグメント開始の無音をそのまま持つので、
        //    後段の無音判定にそのまま使える。
        $stream = [];
        $prevEnd = null;
        $wordFallbacks = 0;
        foreach ($segs as $s) {
            $atoms = self::atomsFromWords($s, $sep);
            if ($atoms === null) {
                // 単語時刻が無い／使えない → 文字数比の補間（精度が落ちる）
                if ($s['words']) {
                    $wordFallbacks++;
                }
                $atoms = self::atomsByRatio($s);
            }
            if (!$atoms) {
                continue;
            }
            if ($stream && $sep !== '') {
                $gs = (int) $prevEnd;
                $stream[] = ['c' => $sep, 's' => $gs, 'e' => max($gs, $atoms[0]['s'])];
            }
            foreach ($atoms as $a) {
                $stream[] = $a;
            }
            $prevEnd = $atoms[count($atoms) - 1]['e'];
        }
        if (!$stream) {
            return $segments;
        }
        if ($wordFallbacks > 0) {
            // 単語時刻が来ているのに使えなかったケース。時刻がずれる原因になるので気づけるようにする。
            App::log(
                '単語時刻を使えず文字数比で補間したセグメント: ' . $wordFallbacks . '/' . count($segs) . '件',
                'worker'
            );
        }

        // 2) 文末記号（末尾の閉じ引用符・括弧・空白まで1文に含める）と、長い無音で「文」に割り直す。
        $count = count($stream);
        $sentences = [];
        $buf = [];
        for ($i = 0; $i < $count; $i++) {
            $buf[] = $stream[$i];
            $break = false;
            if (preg_match('/[\.!\?。！？…]/u', $stream[$i]['c'])) {
                $j = $i + 1;
                while ($j < $count && preg_match('/[”")\x27』」）\s]/u', $stream[$j]['c'])) {
                    $buf[] = $stream[$j];
                    $j++;
                }
                $i = $j - 1;
                $break = true;
            } elseif (self::gapAfter($stream, $i, $count) >= self::GAP_SPLIT_MS) {
                $break = true;
            }
            if ($break) {
                $sentences[] = $buf;
                $buf = [];
            }
        }
        if ($buf) {
            $sentences[] = $buf;
        }

        // 3) 文（atom列）→セグメント化。前後の空白atomはトリム。
        //    分割時に実時刻を使えるよう atom列を持ち回る。
        $cues = [];
        foreach ($sentences as $atoms) {
            $atoms = self::trimSpaceAtoms($atoms);
            if (!$atoms) {
                continue;
            }
            $text = '';
            foreach ($atoms as $a) {
                $text .= $a['c'];
            }
            $text = trim($text);
            if ($text === '') {
                continue;
            }
            $cues[] = [
                'start_ms' => $atoms[0]['s'],
                'end_ms'   => $atoms[count($atoms) - 1]['e'],
                'text'     => $text,
                'atoms'    => $atoms,
            ];
        }
        if (!$cues) {
            return $segments;
        }

        // 3.5) 極端に短い断片（例: 記号のみ/一文字）は直前の文に連結。
        $joined = [];
        foreach ($cues as $c) {
            if ($joined && mb_strlen($c['text']) < self::MERGE_MIN_CHARS) {
                $prev = &$joined[count($joined) - 1];
                $gapAt = $prev['end_ms'];
                $prev['text'] = rtrim($prev['text']) . $sep . $c['text'];
                $prev['end_ms'] = max($prev['end_ms'], $c['end_ms']);
                if ($sep !== '') {
                    $prev['atoms'][] = ['c' => $sep, 's' => $gapAt, 'e' => max($gapAt, $c['atoms'][0]['s'])];
                }
                foreach ($c['atoms'] as $a) {
                    $prev['atoms'][] = $a;
                }
                unset($prev);
                continue;
            }
            $joined[] = $c;
        }
        $cues = $joined;

        // 4) 長すぎる文は区切り（空白・読点）で分割。
        $out = [];
        foreach ($cues as $m) {
            $len = mb_strlen($m['text']);
            $dur = $m['end_ms'] - $m['start_ms'];
            if ($len <= self::SPLIT_MAX_CHARS && $dur <= self::SPLIT_MAX_DUR_MS) {
                $out[] = self::cue($m['start_ms'], $m['end_ms'], $m['text']);
                continue;
            }
            foreach (self::splitLong($m, $cjk) as $piece) {
                $out[] = $piece;
            }
        }

        // 不正時刻の補正
        foreach ($out as &$o) {
            if ($o['end_ms'] <= $o['start_ms']) {
                $o['end_ms'] = $o['start_ms'] + 1;
            }
        }
        unset($o);

        return $out ?: $segments;
    }

    /** @return array{start_ms:int,end_ms:int,text:string} */
    private static function cue(int $start, int $end, string $text): array
    {
        return ['start_ms' => $start, 'end_ms' => $end, 'text' => $text];
    }

    /**
     * atom i の直後に空く無音(ms)。区切りatom自身が持つ幅も無音として扱う
     * （CJKは区切りatomを挟まないので、次atomとの間隔で見る）。
     * @param array<int,array{c:string,s:int,e:int}> $stream
     */
    private static function gapAfter(array $stream, int $i, int $count): int
    {
        $own = 0;
        if (preg_match('/^\s$/u', $stream[$i]['c'])) {
            $own = $stream[$i]['e'] - $stream[$i]['s'];
        }
        $next = ($i + 1 < $count) ? ($stream[$i + 1]['s'] - $stream[$i]['e']) : 0;
        return max($own, $next);
    }

    /**
     * 前後の空白atomを落とす。
     * @param array<int,array{c:string,s:int,e:int}> $atoms
     * @return array<int,array{c:string,s:int,e:int}>
     */
    private static function trimSpaceAtoms(array $atoms): array
    {
        while ($atoms && preg_match('/^\s$/u', $atoms[0]['c'])) {
            array_shift($atoms);
        }
        while ($atoms && preg_match('/^\s$/u', $atoms[count($atoms) - 1]['c'])) {
            array_pop($atoms);
        }
        return array_values($atoms);
    }

    /**
     * セグメント内の文字を [start,end] で線形補間して atom 列にする（単語時刻が無いとき用）。
     * 話速が一定という仮定なので、発話が長いほど、また文中の間が大きいほど境界がずれる。
     *
     * @param array{start_ms:int,end_ms:int,text:string,words:array} $s
     * @return array<int,array{c:string,s:int,e:int}>
     */
    private static function atomsByRatio(array $s): array
    {
        $cs = preg_split('//u', $s['text'], -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $n = count($cs);
        if ($n === 0) {
            return [];
        }
        $span = max(1, $s['end_ms'] - $s['start_ms']);
        $atoms = [];
        for ($i = 0; $i < $n; $i++) {
            $atoms[] = [
                'c' => $cs[$i],
                's' => (int) round($s['start_ms'] + ($i / $n) * $span),
                'e' => (int) round($s['start_ms'] + (($i + 1) / $n) * $span),
            ];
        }
        return $atoms;
    }

    /**
     * 単語時刻から atom 列を作る。使えない（単語が無い／時刻が壊れている／
     * 単語を繋いだ文字列が本文と一致しない）場合は null を返して呼び元に補間させる。
     *
     * @param array{start_ms:int,end_ms:int,text:string,words:array} $s
     * @return array<int,array{c:string,s:int,e:int}>|null
     */
    private static function atomsFromWords(array $s, string $sep): ?array
    {
        $words = $s['words'];
        if (!is_array($words) || !$words) {
            return null;
        }

        $atoms = [];
        $prevEnd = null;
        foreach ($words as $w) {
            if (!is_array($w)) {
                return null;
            }
            $t = trim((string) ($w['text'] ?? ''));
            if ($t === '') {
                continue;
            }
            $ws = (int) ($w['start_ms'] ?? -1);
            $we = (int) ($w['end_ms'] ?? -1);
            if ($ws < 0 || $we < $ws) {
                return null;
            }
            if ($atoms && $sep !== '') {
                $gs = (int) $prevEnd;
                $atoms[] = ['c' => $sep, 's' => $gs, 'e' => max($gs, $ws)];
            }
            $cs = preg_split('//u', $t, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $n = count($cs);
            $span = max(1, $we - $ws);
            for ($i = 0; $i < $n; $i++) {
                $atoms[] = [
                    'c' => $cs[$i],
                    's' => (int) round($ws + ($i / $n) * $span),
                    'e' => (int) round($ws + (($i + 1) / $n) * $span),
                ];
            }
            $prevEnd = $we;
        }
        if (!$atoms) {
            return null;
        }

        // 単語列と本文がずれている（取りこぼし・別表記）なら時刻の対応が保証できない
        $joined = '';
        foreach ($atoms as $a) {
            $joined .= $a['c'];
        }
        if (self::squash($joined) !== self::squash($s['text'])) {
            return null;
        }
        return $atoms;
    }

    /** 比較用に空白を潰す */
    private static function squash(string $t): string
    {
        return (string) preg_replace('/\s+/u', '', $t);
    }

    private static function isCjk(string $lang): bool
    {
        $l = strtolower($lang);
        return in_array($l, ['ja', 'jpn', 'zh', 'zh-cn', 'zh-tw', 'cmn', 'yue', 'ko', 'kor'], true);
    }

    /**
     * 長いセグメントを目標文字数前後で、なるべく区切り（空白・読点）位置で分割する。
     * 分割点の時刻は atom（＝単語時刻または補間値）からそのまま取る。
     *
     * @param array{start_ms:int,end_ms:int,text:string,atoms:array<int,array{c:string,s:int,e:int}>} $cue
     * @return array<int,array{start_ms:int,end_ms:int,text:string}>
     */
    private static function splitLong(array $cue, bool $cjk): array
    {
        $atoms = $cue['atoms'] ?? [];
        $N = count($atoms);
        if ($N === 0) {
            return [self::cue($cue['start_ms'], $cue['end_ms'], $cue['text'])];
        }
        $chars = array_map(static fn(array $a): string => $a['c'], $atoms);

        $parts = [];
        $a = 0;
        while ($a < $N) {
            $target = $a + self::TARGET_CHARS;
            if ($target >= $N - 8) {
                $b = $N - 1; // 残りは全部（半端に短い末尾を作らない）
            } else {
                $b = self::findCut($chars, $a, $target, $N, $cjk);
            }

            // 端の空白atomは時刻の根拠にしない（無音を巻き込んで早出し・居残りになる）
            $ls = $a;
            while ($ls < $b && preg_match('/^\s$/u', $chars[$ls])) {
                $ls++;
            }
            $le = $b;
            while ($le > $ls && preg_match('/^\s$/u', $chars[$le])) {
                $le--;
            }

            $sliceStart = $atoms[$ls]['s'];
            $sliceEnd = $atoms[$le]['e'];
            if ($sliceEnd <= $sliceStart) {
                $sliceEnd = $sliceStart + 1;
            }
            $text = trim(implode('', array_slice($chars, $a, $b - $a + 1)));
            if ($text !== '') {
                $parts[] = self::cue($sliceStart, $sliceEnd, $text);
            }
            $a = $b + 1;
        }
        return $parts ?: [self::cue($cue['start_ms'], $cue['end_ms'], $cue['text'])];
    }

    /**
     * target 位置の近くで分割に適した区切り文字の位置を返す（後方優先→前方）。
     * @param array<int,string> $chars
     */
    private static function findCut(array $chars, int $a, int $target, int $N, bool $cjk): int
    {
        $soft = $cjk
            ? ['、', '，', '。', '！', '？', '；', '：']
            : [' ', ',', ';', ':'];
        $window = 24;
        $max = min($N - 1, $target + $window);
        for ($i = $target; $i <= $max; $i++) {
            if (in_array($chars[$i], $soft, true)) {
                return $i;
            }
        }
        $min = max($a, $target - $window);
        for ($i = $target; $i >= $min; $i--) {
            if (in_array($chars[$i], $soft, true)) {
                return $i;
            }
        }
        return min($target, $N - 1);
    }
}
