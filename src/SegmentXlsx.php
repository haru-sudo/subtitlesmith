<?php

declare(strict_types=1);

namespace App;

/**
 * 字幕セグメントの Excel(.xlsx) 書き出し／取り込み。
 *
 * Composer を使わない構成のため、外部ライブラリに依存せず
 * 標準の ZipArchive + SimpleXML だけで最小限の OOXML を読み書きする。
 *
 *  - build(): 編集画面の全セグメントを1シートの xlsx バイナリにする（翻訳者がExcelで編集する用）
 *  - parse(): Excelで編集した xlsx / csv を読み、行の配列に戻す（ID列で元の行と対応付け）
 *
 * 列（書き出し時の並び。取り込み時は「見出し名」で対応付けるので順序が変わってもよい）:
 *   ID / 開始 / 終了 / 開始ms / 終了ms / 原文 / 機械翻訳 / 編集訳 / 確認済 / 確認者
 */
final class SegmentXlsx
{
    /** 書き出しの列定義: [見出し, 内部キー] */
    private const COLUMNS = [
        ['ID',     'id'],
        ['開始',    'start_clock'],
        ['終了',    'end_clock'],
        ['開始ms',  'start_ms'],
        ['終了ms',  'end_ms'],
        ['原文',    'source_text'],
        ['機械翻訳', 'machine_translation'],
        ['編集訳',  'edited_translation'],
        ['確認済',  'is_approved'],
        ['確認者',  'approved_by'],
    ];

    /** 列ごとの幅（Excelの文字数単位） */
    private const WIDTHS = [6, 12, 12, 10, 10, 46, 46, 46, 8, 14];

    // ---------------------------------------------------------------------
    // 書き出し
    // ---------------------------------------------------------------------

    /**
     * セグメント配列から xlsx のバイナリを生成して返す。
     * @param array<int,array> $segments Segments::forVideo() の行
     */
    public static function build(array $segments): string
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('この環境では ZipArchive が使えないため Excel(.xlsx) を作成できません。CSVでの書き出しをご利用ください。');
        }

        // 1行目=見出し、以降=データ
        $rows = [];
        $rows[] = array_map(static fn(array $c) => ['t' => 'text', 'v' => $c[0]], self::COLUMNS);

        foreach ($segments as $s) {
            $start = (int) ($s['start_ms'] ?? 0);
            $end   = (int) ($s['end_ms'] ?? 0);
            $cells = [
                ['t' => 'num',  'v' => (int) ($s['id'] ?? 0)],
                ['t' => 'text', 'v' => ms_to_vtt($start)],
                ['t' => 'text', 'v' => ms_to_vtt($end)],
                ['t' => 'num',  'v' => $start],
                ['t' => 'num',  'v' => $end],
                ['t' => 'text', 'v' => (string) ($s['source_text'] ?? '')],
                ['t' => 'text', 'v' => (string) ($s['machine_translation'] ?? '')],
                ['t' => 'text', 'v' => (string) ($s['edited_translation'] ?? '')],
                ['t' => 'text', 'v' => !empty($s['is_approved']) ? '1' : ''],
                ['t' => 'text', 'v' => (string) ($s['approved_by'] ?? '')],
            ];
            $rows[] = $cells;
        }

        $sheetXml = self::sheetXml($rows);

        $tmp = tempnam(sys_get_temp_dir(), 'subtitlesmith_xlsx_');
        if ($tmp === false) {
            throw new \RuntimeException('一時ファイルを作成できませんでした。');
        }
        $zip = new \ZipArchive();
        if ($zip->open($tmp, \ZipArchive::OVERWRITE) !== true) {
            @unlink($tmp);
            throw new \RuntimeException('xlsxの作成に失敗しました。');
        }

        $zip->addFromString('[Content_Types].xml', self::contentTypesXml());
        $zip->addFromString('_rels/.rels', self::rootRelsXml());
        $zip->addFromString('xl/workbook.xml', self::workbookXml());
        $zip->addFromString('xl/_rels/workbook.xml.rels', self::workbookRelsXml());
        $zip->addFromString('xl/styles.xml', self::stylesXml());
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
        $zip->close();

        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);
        return $bytes;
    }

    /** @param array<int,array<int,array{t:string,v:mixed}>> $rows */
    private static function sheetXml(array $rows): string
    {
        $cols = '';
        foreach (self::WIDTHS as $i => $w) {
            $n = $i + 1;
            $cols .= '<col min="' . $n . '" max="' . $n . '" width="' . $w . '" customWidth="1"/>';
        }

        $body = '';
        foreach ($rows as $r => $cells) {
            $rowNum = $r + 1;
            $isHeader = ($r === 0);
            $body .= '<row r="' . $rowNum . '">';
            foreach ($cells as $c => $cell) {
                $ref = self::colLetter($c + 1) . $rowNum;
                if ($isHeader) {
                    // 見出し行: 太字スタイル(s=1)
                    $body .= '<c r="' . $ref . '" t="inlineStr" s="1"><is><t xml:space="preserve">'
                        . self::xmlEsc((string) $cell['v']) . '</t></is></c>';
                } elseif ($cell['t'] === 'num' && $cell['v'] !== '' && $cell['v'] !== null) {
                    $body .= '<c r="' . $ref . '"><v>' . (int) $cell['v'] . '</v></c>';
                } else {
                    $v = (string) $cell['v'];
                    if ($v === '') {
                        // 空セルは省略（ファイルを軽く）
                        continue;
                    }
                    // 折り返し表示スタイル(s=2)
                    $body .= '<c r="' . $ref . '" t="inlineStr" s="2"><is><t xml:space="preserve">'
                        . self::xmlEsc($v) . '</t></is></c>';
                }
            }
            $body .= '</row>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            . '<cols>' . $cols . '</cols>'
            . '<sheetData>' . $body . '</sheetData>'
            . '</worksheet>';
    }

    private static function contentTypesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>';
    }

    private static function rootRelsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }

    private static function workbookXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="字幕" sheetId="1" r:id="rId1"/></sheets>'
            . '</workbook>';
    }

    private static function workbookRelsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>';
    }

    /** 見出し=太字＋薄い背景(s=1)、本文=上寄せ折り返し(s=2) の最小スタイル。 */
    private static function stylesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2">'
            . '<font><sz val="11"/><name val="Yu Gothic"/></font>'
            . '<font><b/><sz val="11"/><name val="Yu Gothic"/></font>'
            . '</fonts>'
            . '<fills count="3">'
            . '<fill><patternFill patternType="none"/></fill>'
            . '<fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFEDF1F7"/><bgColor indexed="64"/></patternFill></fill>'
            . '</fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="3">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf>'
            . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }

    // ---------------------------------------------------------------------
    // 取り込み
    // ---------------------------------------------------------------------

    /**
     * 取り込みファイルを読み、行の配列（内部キー付き）に変換する。
     * 対応: .xlsx（Excelで再保存したものも可）/ .csv（UTF-8, BOM可）。
     *
     * @return array<int,array{
     *   id:?int, start_ms:?int, end_ms:?int,
     *   source_text:?string, machine_translation:?string,
     *   edited_translation:?string, is_approved:?int, approved_by:?string
     * }>
     */
    public static function parse(string $path, string $originalName = ''): array
    {
        $ext = strtolower(pathinfo($originalName !== '' ? $originalName : $path, PATHINFO_EXTENSION));
        $grid = ($ext === 'csv' || $ext === 'txt')
            ? self::readCsvGrid($path)
            : self::readXlsxGrid($path);

        if (count($grid) < 2) {
            throw new \RuntimeException('取り込めるデータ行がありません。1行目に見出し、2行目以降にデータが必要です。');
        }

        // 見出し行 → 列インデックス→内部キー の対応表を作る
        $header = $grid[0];
        $map = [];
        foreach ($header as $i => $name) {
            $key = self::headerToKey((string) $name);
            if ($key !== null) {
                $map[$i] = $key;
            }
        }
        if (!$map) {
            throw new \RuntimeException('見出し行を認識できませんでした。「原文」「編集訳」などの列がある書き出し用のシートをご利用ください。');
        }

        $out = [];
        $n = count($grid);
        for ($r = 1; $r < $n; $r++) {
            $cells = $grid[$r];
            $rec = [];
            foreach ($map as $i => $key) {
                $rec[$key] = array_key_exists($i, $cells) ? (string) $cells[$i] : '';
            }
            // 完全な空行はスキップ
            $joined = trim(implode('', $rec));
            if ($joined === '') {
                continue;
            }
            $out[] = self::normalizeRecord($rec);
        }
        return $out;
    }

    /** 生の見出し/値マップ1行を、型を整えた内部レコードにする。 */
    private static function normalizeRecord(array $rec): array
    {
        $id = isset($rec['id']) && trim((string) $rec['id']) !== ''
            ? (int) preg_replace('/[^0-9]/', '', (string) $rec['id'])
            : null;

        $startMs = self::toMs($rec['start_ms'] ?? '', $rec['start_clock'] ?? '');
        $endMs   = self::toMs($rec['end_ms'] ?? '', $rec['end_clock'] ?? '');

        $approved = null;
        if (array_key_exists('is_approved', $rec)) {
            $approved = self::truthy((string) $rec['is_approved']) ? 1 : 0;
        }

        return [
            'id'                  => $id,
            'start_ms'            => $startMs,
            'end_ms'              => $endMs,
            'source_text'         => array_key_exists('source_text', $rec) ? (string) $rec['source_text'] : null,
            'machine_translation' => array_key_exists('machine_translation', $rec) ? (string) $rec['machine_translation'] : null,
            'edited_translation'  => array_key_exists('edited_translation', $rec) ? (string) $rec['edited_translation'] : null,
            'is_approved'         => $approved,
            'approved_by'         => array_key_exists('approved_by', $rec) ? (string) $rec['approved_by'] : null,
        ];
    }

    /** ms列を優先し、無ければ時刻表記(hh:mm:ss.mmm / mm:ss.mmm)を解釈する。判定不能はnull。 */
    private static function toMs(string $ms, string $clock): ?int
    {
        $ms = trim($ms);
        if ($ms !== '' && preg_match('/^-?\d+(\.\d+)?$/', $ms)) {
            return max(0, (int) round((float) $ms));
        }
        $clock = trim($clock);
        if ($clock === '') {
            return null;
        }
        // hh:mm:ss.mmm / hh:mm:ss,mmm / mm:ss.mmm / mm:ss
        if (preg_match('/^(?:(\d+):)?(\d{1,2}):(\d{1,2})(?:[.,](\d{1,3}))?$/', $clock, $m)) {
            $h = $m[1] !== '' ? (int) $m[1] : 0;
            $min = (int) $m[2];
            $s = (int) $m[3];
            $milli = isset($m[4]) && $m[4] !== '' ? (int) str_pad(substr($m[4], 0, 3), 3, '0') : 0;
            return (($h * 3600 + $min * 60 + $s) * 1000) + $milli;
        }
        return null;
    }

    private static function truthy(string $v): bool
    {
        $v = mb_strtolower(trim($v));
        return in_array($v, ['1', '○', '◯', 'o', 'x', '✓', 'v', 'true', 'yes', 'y', 'はい', '済', '確認済', 'ok'], true);
    }

    /** 見出し名を内部キーに対応付ける（表記ゆれ・空白・全半角に寛容）。 */
    private static function headerToKey(string $name): ?string
    {
        $h = mb_strtolower(trim($name));
        $h = str_replace(['（', '）', '(', ')', ' ', '　', '_'], '', $h);
        if ($h === '') {
            return null;
        }
        // ms列を先に判定（「開始ms」より前に「開始」で拾わないため）
        if ($h === 'id' || $h === '＃' || $h === 'no' || $h === '番号') return 'id';
        if (str_contains($h, '開始') && str_contains($h, 'ms')) return 'start_ms';
        if (str_contains($h, '終了') && str_contains($h, 'ms')) return 'end_ms';
        if (str_contains($h, 'startms')) return 'start_ms';
        if (str_contains($h, 'endms')) return 'end_ms';
        if (str_contains($h, '開始') || $h === 'start' || str_contains($h, '開始時刻')) return 'start_clock';
        if (str_contains($h, '終了') || $h === 'end' || str_contains($h, '終了時刻')) return 'end_clock';
        if (str_contains($h, '原文') || $h === 'source' || str_contains($h, 'sourcetext')) return 'source_text';
        if (str_contains($h, '機械翻訳') || str_contains($h, 'machine') || $h === 'mt') return 'machine_translation';
        if (str_contains($h, '編集訳') || str_contains($h, '日本語') || str_contains($h, '訳文')
            || $h === '訳' || $h === 'translation' || str_contains($h, 'edited')) return 'edited_translation';
        if (str_contains($h, '確認者') || str_contains($h, 'approvedby') || str_contains($h, '承認者')) return 'approved_by';
        if (str_contains($h, '確認') || str_contains($h, '承認') || str_contains($h, 'approved')) return 'is_approved';
        return null;
    }

    // ---- CSV 読み取り ----

    /** @return array<int,array<int,string>> 2次元配列 */
    private static function readCsvGrid(string $path): array
    {
        $fh = @fopen($path, 'r');
        if ($fh === false) {
            throw new \RuntimeException('CSVファイルを開けませんでした。');
        }
        $grid = [];
        $first = true;
        while (($row = fgetcsv($fh, 0, ',')) !== false) {
            if ($first) {
                // 先頭セルの UTF-8 BOM を除去
                if (isset($row[0])) {
                    $row[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $row[0]);
                }
                $first = false;
            }
            $grid[] = array_map(static fn($c) => (string) $c, $row);
        }
        fclose($fh);
        return $grid;
    }

    // ---- XLSX 読み取り ----

    /** @return array<int,array<int,string>> 行→（列インデックス→文字列）の2次元配列 */
    private static function readXlsxGrid(string $path): array
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('この環境では ZipArchive が使えないため .xlsx を読み込めません。CSVでの取り込みをご利用ください。');
        }
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            throw new \RuntimeException('.xlsx を開けませんでした。ファイルが壊れていないか確認してください。');
        }
        try {
            $shared = self::readSharedStrings($zip);
            $sheetPath = self::firstSheetPath($zip);
            $xml = $zip->getFromName($sheetPath);
            if ($xml === false) {
                throw new \RuntimeException('シートを読み込めませんでした。');
            }
            return self::parseSheetXml($xml, $shared);
        } finally {
            $zip->close();
        }
    }

    /** @return array<int,string> 共有文字列テーブル */
    private static function readSharedStrings(\ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false || $xml === '') {
            return [];
        }
        $sx = @simplexml_load_string($xml);
        if ($sx === false) {
            return [];
        }
        $out = [];
        foreach ($sx->si as $si) {
            $out[] = self::siText($si);
        }
        return $out;
    }

    /** <si> 要素（<t> 直下、または複数 <r><t>）からテキストを結合する。 */
    private static function siText(\SimpleXMLElement $si): string
    {
        if (isset($si->t)) {
            return (string) $si->t;
        }
        $buf = '';
        foreach ($si->r as $r) {
            $buf .= (string) $r->t;
        }
        return $buf;
    }

    /** workbook のリレーションを辿って先頭シートの実パスを求める（無理ならsheet1.xml）。 */
    private static function firstSheetPath(\ZipArchive $zip): string
    {
        $wb = $zip->getFromName('xl/workbook.xml');
        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($wb !== false && $rels !== false) {
            $wbx = @simplexml_load_string($wb);
            $relx = @simplexml_load_string($rels);
            if ($wbx !== false && $relx !== false && isset($wbx->sheets->sheet[0])) {
                // r:id を取り出す
                $rid = '';
                foreach ($wbx->sheets->sheet[0]->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships') as $k => $v) {
                    if ($k === 'id') {
                        $rid = (string) $v;
                    }
                }
                if ($rid !== '') {
                    foreach ($relx->Relationship as $rel) {
                        if ((string) $rel['Id'] === $rid) {
                            $target = ltrim((string) $rel['Target'], '/');
                            // Target は xl/ からの相対（例 worksheets/sheet1.xml）
                            if (!str_starts_with($target, 'xl/')) {
                                $target = 'xl/' . $target;
                            }
                            if ($zip->locateName($target) !== false) {
                                return $target;
                            }
                        }
                    }
                }
            }
        }
        return 'xl/worksheets/sheet1.xml';
    }

    /**
     * sheetXml をパースして 2次元配列にする。空セルは間を詰めず列位置を保持する。
     * @param array<int,string> $shared
     * @return array<int,array<int,string>>
     */
    private static function parseSheetXml(string $xml, array $shared): array
    {
        $sx = @simplexml_load_string($xml);
        if ($sx === false || !isset($sx->sheetData)) {
            return [];
        }
        $grid = [];
        foreach ($sx->sheetData->row as $row) {
            $cells = [];
            $maxCol = -1;
            foreach ($row->c as $c) {
                $ref = (string) $c['r'];
                $col = self::refToColIndex($ref); // 0始まり
                $type = (string) $c['t'];
                $val = '';
                if ($type === 's') {
                    $idx = (int) $c->v;
                    $val = $shared[$idx] ?? '';
                } elseif ($type === 'inlineStr') {
                    $val = isset($c->is) ? self::siText($c->is) : '';
                } elseif ($type === 'str') {
                    $val = (string) $c->v;
                } else {
                    $val = isset($c->v) ? (string) $c->v : '';
                }
                $cells[$col] = $val;
                if ($col > $maxCol) {
                    $maxCol = $col;
                }
            }
            // 抜けている列を空文字で埋め、0..maxCol の連番配列にする
            $line = [];
            for ($i = 0; $i <= $maxCol; $i++) {
                $line[$i] = $cells[$i] ?? '';
            }
            $grid[] = $line;
        }
        return $grid;
    }

    /** セル参照(例 "AB12") を 0始まりの列インデックスに変換 */
    private static function refToColIndex(string $ref): int
    {
        if (!preg_match('/^([A-Z]+)/', strtoupper($ref), $m)) {
            return 0;
        }
        $letters = $m[1];
        $n = 0;
        $len = strlen($letters);
        for ($i = 0; $i < $len; $i++) {
            $n = $n * 26 + (ord($letters[$i]) - ord('A') + 1);
        }
        return $n - 1;
    }

    /** 0始まりの列インデックス → Excelの列名（A, B, ..., AA） */
    private static function colLetter(int $n): string
    {
        $s = '';
        while ($n > 0) {
            $mod = ($n - 1) % 26;
            $s = chr(65 + $mod) . $s;
            $n = intdiv($n - 1, 26);
        }
        return $s === '' ? 'A' : $s;
    }

    /** XMLテキストのエスケープ＋不正制御文字の除去 */
    private static function xmlEsc(string $s): string
    {
        // 改行は LF に正規化（セル内改行は wrapText 表示で有効）
        $s = str_replace(["\r\n", "\r"], "\n", $s);
        // XML1.0 で許可されない制御文字を除去（タブ・改行は残す）
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $s) ?? $s;
        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
    }
}
