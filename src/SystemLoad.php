<?php

declare(strict_types=1);

namespace App;

/**
 * サーバー全体の負荷（CPU使用率・メモリ・ロード平均）を取得する。
 * Linux の /proc から読む。取得できない環境では null を返す。
 * ローカル文字起こし中の負荷可視化に使う。
 */
final class SystemLoad
{
    /**
     * @return array{
     *   cpu_percent: ?float, load1: ?float, load5: ?float, load15: ?float,
     *   cores: ?int, mem_total_mb: ?int, mem_used_mb: ?int, mem_percent: ?float
     * }
     */
    public static function snapshot(): array
    {
        // ① サンプリングA（プロセスjiffies + システムCPU時間を同時に取る）
        // /proc 全走査は1回で済ませ、対象スクリプトをまとめて探す（最適化）。
        $pids = self::findScriptPids(['transcribe_local.py', 'translate_llm_local.py']);
        $transcribePid = $pids['transcribe_local.py'];
        $translatePid  = $pids['translate_llm_local.py'] ?? null;

        $sysA  = self::cpuTimes();
        $trsA  = $transcribePid !== null ? self::pidJiffies($transcribePid) : null;
        $trlA  = $translatePid  !== null ? self::pidJiffies($translatePid)  : null;

        usleep(200000); // 0.2秒 — プロセス・システム共通のサンプリング間隔

        // ② サンプリングB
        $sysB  = self::cpuTimes();
        $trsB  = $transcribePid !== null ? self::pidJiffies($transcribePid) : null;
        $trlB  = $translatePid  !== null ? self::pidJiffies($translatePid)  : null;

        // ③ システム全体CPU%
        $cpuPct = null;
        if ($sysA !== null && $sysB !== null) {
            $totalDiff = $sysB['total'] - $sysA['total'];
            $idleDiff  = $sysB['idle']  - $sysA['idle'];
            if ($totalDiff > 0) {
                $cpuPct = round(max(0.0, min(100.0, (1.0 - $idleDiff / $totalDiff) * 100.0)), 1);
            }
        }

        // ④ プロセスごとのCPU%（同じサンプリング区間から計算）
        $trsProc = self::buildProcResult($transcribePid, $trsA, $trsB, $sysA, $sysB);
        $trlProc = self::buildProcResult($translatePid,  $trlA, $trlB, $sysA, $sysB);

        return [
            'cpu_percent' => $cpuPct,
            'cores'       => self::cores(),
            'transcribe'  => $trsProc,
            'translate'   => $trlProc,
        ] + self::loadAvg() + self::memory();
    }

    /**
     * サンプリングA/B のjiffiesとsys時間からプロセスのCPU%・メモリを返す。
     * @return array{running:bool, pid:?int, cpu_percent:?float, mem_mb:?int}
     */
    private static function buildProcResult(
        ?int $pid,
        ?int $jiffiesA,
        ?int $jiffiesB,
        ?array $sysA,
        ?array $sysB
    ): array {
        if ($pid === null) {
            return ['running' => false, 'pid' => null, 'cpu_percent' => null, 'mem_mb' => null];
        }
        $cpu = null;
        if ($jiffiesA !== null && $jiffiesB !== null && $sysA !== null && $sysB !== null) {
            $sysDiff = $sysB['total'] - $sysA['total'];
            if ($sysDiff > 0) {
                $clkTck = 100; // /proc/stat は HZ=100 前提
                $cpu = round(($jiffiesB - $jiffiesA) / $sysDiff * $clkTck * 100.0, 1);
            }
        }
        $mem = self::pidMemMb($pid);
        return ['running' => true, 'pid' => $pid, 'cpu_percent' => $cpu, 'mem_mb' => $mem];
    }

    /**
     * 文字起こしプロセス(faster-whisper の Python)を探し、その有無とCPU使用率を返す。
     * 「サーバー全体は0%でも、実は文字起こしが走っている/いない」を切り分けるために使う。
     *
     * @return array{running:bool, pid:?int, cpu_percent:?float, mem_mb:?int}
     */
    /**
     * 文字起こしプロセスの状態（snapshot() の 'transcribe' キーと同内容）。
     * 外部から直接呼びたい場合用。通常は snapshot() を使う。
     * @return array{running:bool, pid:?int, cpu_percent:?float, mem_mb:?int}
     */
    public static function transcribeProcess(): array
    {
        $pid = self::findScriptPid('transcribe_local.py');
        if ($pid === null) {
            return ['running' => false, 'pid' => null, 'cpu_percent' => null, 'mem_mb' => null];
        }
        return ['running' => true, 'pid' => $pid, 'cpu_percent' => self::pidCpuPercent($pid), 'mem_mb' => self::pidMemMb($pid)];
    }

    /**
     * ローカル翻訳プロセス(LLM)の状態。通常は snapshot() を使う。
     * @return array{running:bool, pid:?int, cpu_percent:?float, mem_mb:?int}
     */
    public static function translateProcess(): array
    {
        $pid = self::findScriptPid('translate_llm_local.py');
        if ($pid === null) {
            return ['running' => false, 'pid' => null, 'cpu_percent' => null, 'mem_mb' => null];
        }
        return ['running' => true, 'pid' => $pid, 'cpu_percent' => self::pidCpuPercent($pid), 'mem_mb' => self::pidMemMb($pid)];
    }

    /** Python で指定スクリプト(末尾一致)を実行中のプロセスのPIDを探す（Linux /proc 前提） */
    private static function findScriptPid(string $scriptSuffix): ?int
    {
        return self::findScriptPids([$scriptSuffix])[$scriptSuffix];
    }

    /**
     * 複数スクリプトのPIDを /proc の1回走査でまとめて探す。
     * @param string[] $scriptSuffixes
     * @return array<string, ?int> サフィックス => PID（見つからなければ null）
     */
    private static function findScriptPids(array $scriptSuffixes): array
    {
        $result = array_fill_keys($scriptSuffixes, null);
        if (!is_dir('/proc')) {
            return $result;
        }
        $self = getmypid();
        $remaining = count($scriptSuffixes);
        foreach (glob('/proc/[0-9]*') ?: [] as $dir) {
            if ($remaining <= 0) {
                break; // 全部見つかったら打ち切り
            }
            $pid = (int) basename($dir);
            if ($pid === $self) {
                continue; // 自分自身(PHP)は除外
            }
            $cmd = @file_get_contents($dir . '/cmdline');
            if ($cmd === false || $cmd === '') {
                continue;
            }
            // cmdline はNUL区切り。引数ごとに分ける。
            $args = array_values(array_filter(explode("\0", $cmd), static fn($a) => $a !== ''));
            if (!$args) {
                continue;
            }
            // 実行ファイルがPython であり、かつ引数に対象スクリプトを含むものだけを対象にする。
            // （grep やシェル等が文字列を含むだけの誤検出を防ぐ）
            if (strpos(strtolower(basename($args[0])), 'python') === false) {
                continue;
            }
            foreach ($scriptSuffixes as $suffix) {
                if ($result[$suffix] !== null) {
                    continue;
                }
                $sufLen = strlen($suffix);
                foreach ($args as $a) {
                    if (substr($a, -$sufLen) === $suffix) {
                        $result[$suffix] = $pid;
                        $remaining--;
                        break;
                    }
                }
            }
        }
        return $result;
    }

    /** 指定PIDのCPU使用率(%)を /proc/<pid>/stat の2回サンプリングで概算 */
    private static function pidCpuPercent(int $pid): ?float
    {
        $a = self::pidJiffies($pid);
        $sysA = self::cpuTimes();
        if ($a === null || $sysA === null) {
            return null;
        }
        usleep(200000); // 0.2s
        $b = self::pidJiffies($pid);
        $sysB = self::cpuTimes();
        if ($b === null || $sysB === null) {
            return null;
        }
        $procDiff = $b - $a;
        $totalDiff = $sysB['total'] - $sysA['total'];
        if ($totalDiff <= 0) {
            return null;
        }
        $cores = self::cores() ?: 1;
        // 全コア合計に対する割合 × コア数 = 「1コア=100%」換算の使用率
        $pct = $procDiff / $totalDiff * 100.0 * $cores;
        return round(max(0.0, $pct), 1);
    }

    /** /proc/<pid>/stat の utime+stime（jiffies） */
    private static function pidJiffies(int $pid): ?int
    {
        $stat = @file_get_contents("/proc/{$pid}/stat");
        if ($stat === false) {
            return null;
        }
        // comm に空白/括弧があるため、最後の ')' 以降を使う
        $rp = strrpos($stat, ')');
        if ($rp === false) {
            return null;
        }
        $rest = preg_split('/\s+/', trim(substr($stat, $rp + 1)));
        // rest[0] = state(3番目フィールド)。utime=14,stime=15 → rest index 11,12
        if (!$rest || count($rest) < 13) {
            return null;
        }
        return (int) $rest[11] + (int) $rest[12];
    }

    /** 指定PIDの実メモリ(RSS, MB) */
    private static function pidMemMb(int $pid): ?int
    {
        $status = @file_get_contents("/proc/{$pid}/status");
        if ($status === false) {
            return null;
        }
        if (preg_match('/^VmRSS:\s+(\d+)\s*kB/m', $status, $m)) {
            return (int) round((int) $m[1] / 1024);
        }
        return null;
    }

    private static function cores(): ?int
    {
        if (is_file('/proc/cpuinfo')) {
            $c = @file_get_contents('/proc/cpuinfo');
            if ($c !== false) {
                $n = substr_count($c, "processor\t");
                if ($n > 0) {
                    return $n;
                }
            }
        }
        return null;
    }

    /** @return array{load1:?float,load5:?float,load15:?float} */
    private static function loadAvg(): array
    {
        if (function_exists('sys_getloadavg')) {
            $l = @sys_getloadavg();
            if (is_array($l) && count($l) >= 3) {
                return ['load1' => (float) $l[0], 'load5' => (float) $l[1], 'load15' => (float) $l[2]];
            }
        }
        return ['load1' => null, 'load5' => null, 'load15' => null];
    }

    /**
     * 2回の /proc/stat サンプリングでCPU使用率(%)を概算する。
     * 短時間（約150ms）ブロックするが、ポーリング用途なら許容範囲。
     */
    private static function cpuPercent(): ?float
    {
        $a = self::cpuTimes();
        if ($a === null) {
            return null;
        }
        usleep(150000);
        $b = self::cpuTimes();
        if ($b === null) {
            return null;
        }
        $totalDiff = $b['total'] - $a['total'];
        $idleDiff  = $b['idle'] - $a['idle'];
        if ($totalDiff <= 0) {
            return null;
        }
        $usage = (1.0 - $idleDiff / $totalDiff) * 100.0;
        return round(max(0.0, min(100.0, $usage)), 1);
    }

    /** @return array{total:int,idle:int}|null */
    private static function cpuTimes(): ?array
    {
        if (!is_file('/proc/stat')) {
            return null;
        }
        $fp = @fopen('/proc/stat', 'r');
        $line = $fp ? (string) @fgets($fp) : '';
        if ($fp) {
            fclose($fp);
        }
        $parts = preg_split('/\s+/', trim($line));
        if (!$parts || $parts[0] !== 'cpu') {
            return null;
        }
        $nums = array_map('intval', array_slice($parts, 1));
        if (count($nums) < 5) {
            return null;
        }
        $idle = ($nums[3] ?? 0) + ($nums[4] ?? 0); // idle + iowait
        $total = array_sum($nums);
        return ['total' => $total, 'idle' => $idle];
    }

    /** @return array{mem_total_mb:?int,mem_used_mb:?int,mem_percent:?float} */
    private static function memory(): array
    {
        if (!is_file('/proc/meminfo')) {
            return ['mem_total_mb' => null, 'mem_used_mb' => null, 'mem_percent' => null];
        }
        $c = @file_get_contents('/proc/meminfo');
        if ($c === false) {
            return ['mem_total_mb' => null, 'mem_used_mb' => null, 'mem_percent' => null];
        }
        $get = static function (string $key) use ($c): ?int {
            if (preg_match('/^' . preg_quote($key, '/') . ':\s+(\d+)\s*kB/m', $c, $m)) {
                return (int) $m[1]; // kB
            }
            return null;
        };
        $totalKb = $get('MemTotal');
        $availKb = $get('MemAvailable');
        if ($totalKb === null || $totalKb <= 0) {
            return ['mem_total_mb' => null, 'mem_used_mb' => null, 'mem_percent' => null];
        }
        $usedKb = $availKb !== null ? max(0, $totalKb - $availKb) : 0;
        return [
            'mem_total_mb' => (int) round($totalKb / 1024),
            'mem_used_mb'  => (int) round($usedKb / 1024),
            'mem_percent'  => $availKb !== null ? round($usedKb / $totalKb * 100, 1) : null,
        ];
    }
}
