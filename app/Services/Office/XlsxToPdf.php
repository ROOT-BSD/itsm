<?php

declare(strict_types=1);

namespace App\Services\Office;

/**
 * Вбудований конвертер XLSX → PDF без зовнішніх програм. Кожен видимий аркуш — окремі сторінки: ширина колонок і висота рядків
 * із файлу, об'єднані комірки, переноси тексту, вирівнювання, напівжирний, колір тексту й заливка (RGB), формати чисел
 * (кількість знаків, %, розділювач тисяч, дати). Формули показуються кешованими значеннями. Широкі аркуші зменшуються до
 * ширини сторінки (А4 портрет/альбом), а дуже широкі розбиваються на смуги колонок. Графіки, малюнки й умовне форматування не
 * переносяться. Для точного відтворення використовується LibreOffice, якщо він встановлений.
 */
final class XlsxToPdf
{
    private const MAX_ROWS = 5000;
    private const MAX_COLS = 80;
    private const MAX_PAGES = 300;
    private const NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    private const RNS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    private const MONTHS = ['січня', 'лютого', 'березня', 'квітня', 'травня', 'червня', 'липня', 'серпня', 'вересня', 'жовтня', 'листопада', 'грудня'];

    private OfficeZip $zip;
    private OfficePdf $pdf;
    /** @var string[] */
    private array $shared = [];
    /** @var array<int, string> */
    private array $numFmts = [];
    /** @var array<int, array<string, mixed>> */
    private array $xfs = [];
    /** @var array<int, array<string, mixed>> */
    private array $fonts = [];
    /** @var array<int, ?array<int, int>> */
    private array $fills = [];
    private float $deadline = 0.0;
    private bool $started = false;

    public static function convert(string $src, string $dest): void
    {
        (new self())->run($src, $dest);
    }

    private function run(string $src, string $dest): void
    {
        $this->deadline = microtime(true) + 60;
        $this->zip = OfficeZip::open($src);
        $wb = $this->zip->xml('xl/workbook.xml');
        if (!$wb) {
            throw new OfficeException('У файлі немає книги Excel.');
        }
        $this->loadShared();
        $this->loadStyles();
        $rels = [];
        if ($rd = $this->zip->xml('xl/_rels/workbook.xml.rels')) {
            foreach ($rd->getElementsByTagName('Relationship') as $r) {
                $t = $r->getAttribute('Target');
                $rels[$r->getAttribute('Id')] = str_starts_with($t, '/') ? ltrim($t, '/') : 'xl/' . $t;
            }
        }
        $sheets = [];
        foreach ($wb->getElementsByTagNameNS(self::NS, 'sheet') as $s) {
            $part = $rels[$s->getAttributeNS(self::RNS, 'id')] ?? null;
            if ($part !== null && $s->getAttribute('state') !== 'hidden' && $s->getAttribute('state') !== 'veryHidden') {
                $sheets[] = [$s->getAttribute('name'), $part];
            }
        }
        if (!$sheets) {
            throw new OfficeException('У книзі немає видимих аркушів.');
        }

        $this->pdf = new OfficePdf('P');
        foreach ($sheets as [$name, $part]) {
            $this->sheet($name, $part);
        }
        if (!$this->started) {
            $this->pdf->AddPage();
        }
        if (@file_put_contents($dest, $this->pdf->Output('S')) === false) {
            throw new OfficeException('Не вдалося записати PDF.');
        }
    }

    private function loadShared(): void
    {
        $dom = $this->zip->xml('xl/sharedStrings.xml');
        if (!$dom) {
            return;
        }
        foreach ($dom->getElementsByTagNameNS(self::NS, 'si') as $si) {
            $t = '';
            foreach ($si->childNodes as $c) {
                if (!$c instanceof \DOMElement) {
                    continue;
                }
                if ($c->localName === 't') {
                    $t .= $c->textContent;
                } elseif ($c->localName === 'r') {
                    foreach ($c->childNodes as $rt) {
                        if ($rt instanceof \DOMElement && $rt->localName === 't') {
                            $t .= $rt->textContent;
                        }
                    }
                }
            }
            $this->shared[] = $t;
        }
    }

    private function loadStyles(): void
    {
        $dom = $this->zip->xml('xl/styles.xml');
        if (!$dom) {
            return;
        }
        $xp = new \DOMXPath($dom);
        $xp->registerNamespace('m', self::NS);
        foreach ($xp->query('/m:styleSheet/m:numFmts/m:numFmt') as $n) {
            $this->numFmts[(int) $n->getAttribute('numFmtId')] = $n->getAttribute('formatCode');
        }
        foreach ($xp->query('/m:styleSheet/m:fonts/m:font') as $f) {
            $color = null;
            $c = $xp->query('m:color', $f)->item(0);
            if ($c instanceof \DOMElement && preg_match('/^[0-9A-Fa-f]{8}$/', $c->getAttribute('rgb'))) {
                $h = substr($c->getAttribute('rgb'), 2);
                $color = [hexdec(substr($h, 0, 2)), hexdec(substr($h, 2, 2)), hexdec(substr($h, 4, 2))];
            }
            $sz = $xp->query('m:sz', $f)->item(0);
            $this->fonts[] = ['b' => $xp->query('m:b', $f)->length > 0, 'c' => $color, 'sz' => $sz instanceof \DOMElement ? (float) $sz->getAttribute('val') : 11.0];
        }
        foreach ($xp->query('/m:styleSheet/m:fills/m:fill') as $f) {
            $fg = $xp->query('m:patternFill[@patternType="solid"]/m:fgColor', $f)->item(0);
            $rgb = null;
            if ($fg instanceof \DOMElement && preg_match('/^[0-9A-Fa-f]{8}$/', $fg->getAttribute('rgb'))) {
                $h = substr($fg->getAttribute('rgb'), 2);
                $rgb = [hexdec(substr($h, 0, 2)), hexdec(substr($h, 2, 2)), hexdec(substr($h, 4, 2))];
            }
            $this->fills[] = $rgb;
        }
        foreach ($xp->query('/m:styleSheet/m:cellXfs/m:xf') as $x) {
            $al = $xp->query('m:alignment', $x)->item(0);
            $this->xfs[] = [
                'fmt' => (int) $x->getAttribute('numFmtId'),
                'font' => (int) $x->getAttribute('fontId'),
                'fill' => (int) $x->getAttribute('fillId'),
                'h' => $al instanceof \DOMElement ? $al->getAttribute('horizontal') : '',
                'wrap' => $al instanceof \DOMElement && in_array($al->getAttribute('wrapText'), ['1', 'true'], true),
            ];
        }
    }

    // ------------------------------------------------------------------ аркуш

    private function sheet(string $name, string $part): void
    {
        $dom = $this->zip->xml($part);
        if (!$dom) {
            return;
        }
        $xp = new \DOMXPath($dom);
        $xp->registerNamespace('m', self::NS);

        // Ширини колонок (символи → мм).
        $colW = [];
        foreach ($xp->query('/m:worksheet/m:cols/m:col') as $c) {
            $min = (int) $c->getAttribute('min');
            $max = min((int) $c->getAttribute('max'), self::MAX_COLS);
            $w = (float) $c->getAttribute('width');
            $hidden = in_array($c->getAttribute('hidden'), ['1', 'true'], true);
            for ($i = $min; $i <= $max; $i++) {
                $colW[$i] = $hidden ? 0.0 : max(2.0, $w * 1.9);
            }
        }
        $defaultColW = 8.43 * 1.9;
        $dc = $xp->query('/m:worksheet/m:sheetFormatPr')->item(0);
        if ($dc instanceof \DOMElement && (float) $dc->getAttribute('defaultColWidth') > 0) {
            $defaultColW = (float) $dc->getAttribute('defaultColWidth') * 1.9;
        }

        // Комірки.
        $rows = [];      // [rowIndex => ['h' => ?float, 'cells' => [col => cell]]]
        $maxCol = 1;
        $truncated = false;
        foreach ($xp->query('/m:worksheet/m:sheetData/m:row') as $row) {
            if ((int) $row->getAttribute('r') > self::MAX_ROWS) {
                $truncated = true;
                break;
            }
            $ri = (int) $row->getAttribute('r');
            if (in_array($row->getAttribute('hidden'), ['1', 'true'], true)) {
                continue;
            }
            $ht = $row->getAttribute('ht') !== '' ? (float) $row->getAttribute('ht') * TextLayout::PT : null;
            $cells = [];
            foreach ($row->childNodes as $c) {
                if (!$c instanceof \DOMElement || $c->localName !== 'c') {
                    continue;
                }
                [$col] = $this->ref($c->getAttribute('r'));
                if ($col < 1 || $col > self::MAX_COLS) {
                    $truncated = $truncated || $col > self::MAX_COLS;
                    continue;
                }
                $text = $this->cellText($c, $xp);
                $s = (int) $c->getAttribute('s');
                if ($text === '' && !$this->hasFill($s)) {
                    continue;
                }
                $cells[$col] = ['t' => $text, 's' => $s, 'num' => $this->isNumeric($c)];
                $maxCol = max($maxCol, $col);
            }
            $rows[$ri] = ['h' => $ht, 'cells' => $cells];
            $this->guard();
        }
        // Об'єднані комірки.
        $merges = [];
        foreach ($xp->query('/m:worksheet/m:mergeCells/m:mergeCell') as $m) {
            $parts = explode(':', $m->getAttribute('ref'));
            if (count($parts) === 2) {
                [$c1, $r1] = $this->ref($parts[0]);
                [$c2, $r2] = $this->ref($parts[1]);
                if ($c1 >= 1 && $c2 <= self::MAX_COLS && $r2 <= self::MAX_ROWS) {
                    $merges[$r1 . ':' . $c1] = [$c2 - $c1 + 1, $r2 - $r1 + 1];
                    $maxCol = max($maxCol, $c2);
                }
            }
        }

        $widths = [];
        for ($c = 1; $c <= $maxCol; $c++) {
            $widths[$c] = $colW[$c] ?? $defaultColW;
        }
        // Колонки без заданої ширини розширюємо під вміст (шрифт DejaVu ширший за Calibri), до 70 мм.
        $this->pdf->SetFont('DejaVu', '', 9);
        foreach ($rows as $row) {
            foreach ($row['cells'] as $c => $cell) {
                if (!isset($colW[$c]) && $cell['t'] !== '' && !($this->xfs[$cell['s']]['wrap'] ?? false)) {
                    $line = mb_substr(explode("\n", $cell['t'])[0], 0, 120);
                    $widths[$c] = min(70.0, max($widths[$c], (float) $this->pdf->GetStringWidth($line) + 3.0));
                }
            }
        }
        $visible = array_filter($widths, static fn($w) => $w > 0);
        $total = array_sum($visible) ?: 1.0;

        // Орієнтація й масштаб.
        $portraitW = 210 - 20;
        $landW = 297 - 20;
        $orientation = $total <= $portraitW ? 'P' : 'L';
        $usable = $orientation === 'P' ? $portraitW : $landW;
        $scale = 1.0;
        $bands = [[1, $maxCol]];
        if ($total > $usable) {
            $scale = $usable / $total;
            if ($scale < 0.62) {
                $scale = 1.0;
                $bands = $this->bands($widths, $usable);
            }
        }
        $fontScale = $scale < 1.0 ? max(0.6, $scale + 0.1) : 1.0;

        foreach ($bands as $bi => [$from, $to]) {
            $this->band($name . (count($bands) > 1 ? ' (колонки ' . $this->colName($from) . '–' . $this->colName($to) . ')' : ''), $rows, $merges, $widths, $from, $to, $scale, $fontScale, $orientation, $truncated && $bi === 0);
        }
    }

    /** @param array<int, float> $widths @return array<int, array{0: int, 1: int}> */
    private function bands(array $widths, float $usable): array
    {
        $bands = [];
        $from = 1;
        $sum = 0.0;
        foreach ($widths as $c => $w) {
            if ($sum + $w > $usable && $c > $from) {
                $bands[] = [$from, $c - 1];
                $from = $c;
                $sum = 0.0;
            }
            $sum += $w;
        }
        $bands[] = [$from, array_key_last($widths)];
        return $bands;
    }

    private function band(string $title, array $rows, array $merges, array $widths, int $from, int $to, float $scale, float $fontScale, string $orientation, bool $truncated): void
    {
        $pw = $orientation === 'P' ? 210.0 : 297.0;
        $ph = $orientation === 'P' ? 297.0 : 210.0;
        $margin = 10.0;
        $bottom = $ph - 12.0;
        $this->pdf->AddPage($orientation);
        $this->started = true;
        $y = $margin;
        $this->pdf->SetFont('DejaVu', 'B', 11);
        $this->pdf->Text($margin, $y + 4, $title);
        $y += 8;

        $xs = [];
        $x = $margin;
        for ($c = $from; $c <= $to; $c++) {
            $xs[$c] = $x;
            $x += $widths[$c] * $scale;
        }
        $xs[$to + 1] = $x;

        $skip = []; // комірки, закриті об'єднанням
        $base = 9.0 * $fontScale;
        $minRowH = 5.2 * $fontScale;
        $lineH = $base * TextLayout::PT * 1.25;

        foreach ($rows as $ri => $row) {
            $this->guard();
            // Висота рядка: задана або за найвищою коміркою з переносом.
            $h = $row['h'] !== null ? max($minRowH, $row['h'] * $fontScale) : $minRowH;
            $prepared = [];
            for ($c = $from; $c <= $to; $c++) {
                if (isset($skip[$ri . ':' . $c]) || $widths[$c] <= 0) {
                    continue;
                }
                $cell = $row['cells'][$c] ?? null;
                $span = $merges[$ri . ':' . $c] ?? [1, 1];
                $cw = 0.0;
                for ($k = $c; $k < $c + $span[0] && $k <= $to; $k++) {
                    $cw += $widths[$k] * $scale;
                }
                if ($span[1] > 1 || $span[0] > 1) {
                    for ($rr = $ri; $rr < $ri + $span[1]; $rr++) {
                        for ($cc = $c; $cc < $c + $span[0]; $cc++) {
                            if ($rr !== $ri || $cc !== $c) {
                                $skip[$rr . ':' . $cc] = true;
                            }
                        }
                    }
                }
                if ($cell === null) {
                    if ($span[0] > 1 || $span[1] > 1) {
                        $cell = ['t' => '', 's' => 0, 'num' => false];
                    } else {
                        continue;
                    }
                }
                $xf = $this->xfs[$cell['s']] ?? [];
                $font = $this->fonts[(int) ($xf['font'] ?? 0)] ?? ['b' => false, 'c' => null, 'sz' => 11.0];
                $pt = max(5.0, min(18.0, (float) $font['sz'] * 9.0 / 11.0 * $fontScale));
                $lines = [$cell['t']];
                if (!empty($xf['wrap']) && $cell['t'] !== '') {
                    $lines = $this->wrap($cell['t'], $cw - 1.6, !empty($font['b']), $pt);
                    $h = max($h, count($lines) * $pt * TextLayout::PT * 1.25 + 1.6);
                }
                $prepared[] = ['c' => $c, 'w' => $cw, 'cell' => $cell, 'xf' => $xf, 'font' => $font, 'pt' => $pt, 'lines' => $lines, 'rows' => $span[1]];
            }
            $h = min($h, $bottom - $margin - 10);
            if ($y + $h > $bottom) {
                $this->pdf->AddPage($orientation);
                $y = $margin;
                if ($this->pdf->PageNo() > self::MAX_PAGES) {
                    throw new OfficeException('Таблиця завелика для перегляду. Завантажте файл.');
                }
            }
            foreach ($prepared as $p) {
                $cx = $xs[$p['c']];
                $fill = $this->fills[(int) ($p['xf']['fill'] ?? 0)] ?? null;
                $ch = $h * 1; // висота комірки (об'єднання по вертикалі — на наступних рядках буде перекрито наближено)
                if ($p['rows'] > 1) {
                    $ch = $h * $p['rows'];
                }
                if ($fill !== null) {
                    $this->pdf->SetFillColor($fill[0], $fill[1], $fill[2]);
                    $this->pdf->Rect($cx, $y, $p['w'], $ch, 'F');
                }
                $this->pdf->SetDrawColor(190, 190, 190);
                $this->pdf->SetLineWidth(0.15);
                $this->pdf->Rect($cx, $y, $p['w'], $ch);
                $color = $p['font']['c'] ?? [0, 0, 0];
                $this->pdf->SetTextColor($color[0], $color[1], $color[2]);
                $this->pdf->SetFont('DejaVu', !empty($p['font']['b']) ? 'B' : '', $p['pt']);
                $align = $p['xf']['h'] ?? '';
                if ($align === '' || $align === 'general') {
                    $align = $p['cell']['num'] ? 'right' : 'left';
                }
                $ty = $y + 0.8 + $p['pt'] * TextLayout::PT * 0.95;
                foreach ($p['lines'] as $line) {
                    $pt = $p['pt'];
                    $this->pdf->SetFont('DejaVu', !empty($p['font']['b']) ? 'B' : '', $pt);
                    $tw = (float) $this->pdf->GetStringWidth($line);
                    if ($tw > $p['w'] - 1.6 && $tw > 0 && count($p['lines']) === 1) {
                        // Не влізає: трохи зменшуємо шрифт (не більше ніж до 70%), решту обрізаємо.
                        $pt = max($p['pt'] * 0.7, $p['pt'] * ($p['w'] - 1.6) / $tw);
                        $this->pdf->SetFont('DejaVu', !empty($p['font']['b']) ? 'B' : '', $pt);
                    }
                    $line = $this->clip($line, $p['w'] - 1.6, !empty($p['font']['b']), $pt);
                    $this->pdf->SetFont('DejaVu', !empty($p['font']['b']) ? 'B' : '', $pt);
                    $tw = (float) $this->pdf->GetStringWidth($line);
                    $tx = $cx + 0.8;
                    if ($align === 'right') {
                        $tx = $cx + $p['w'] - 0.8 - $tw;
                    } elseif ($align === 'center') {
                        $tx = $cx + ($p['w'] - $tw) / 2;
                    }
                    if ($line !== '') {
                        $this->pdf->Text($tx, $ty, $line);
                    }
                    $ty += $p['pt'] * TextLayout::PT * 1.25;
                }
            }
            $this->pdf->SetTextColor(0, 0, 0);
            $y += $h;
        }
        if ($truncated) {
            $this->pdf->SetFont('DejaVu', '', 8);
            $this->pdf->SetTextColor(150, 0, 0);
            if ($y + 5 > $bottom) {
                $this->pdf->AddPage($orientation);
                $y = $margin;
            }
            $this->pdf->Text($margin, $y + 4, 'Показано не більше ' . self::MAX_ROWS . ' рядків і ' . self::MAX_COLS . ' колонок; решту дивіться в завантаженому файлі.');
            $this->pdf->SetTextColor(0, 0, 0);
        }
    }

    private function guard(): void
    {
        if (microtime(true) > $this->deadline) {
            throw new OfficeException('Конвертація зайняла надто багато часу.');
        }
    }

    /** @return string[] */
    private function wrap(string $text, float $width, bool $bold, float $pt): array
    {
        $this->pdf->SetFont('DejaVu', $bold ? 'B' : '', $pt);
        $out = [];
        foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", $text)) as $para) {
            $line = '';
            foreach (preg_split('/\s+/u', $para, -1, PREG_SPLIT_NO_EMPTY) ?: [''] as $word) {
                $try = $line === '' ? $word : $line . ' ' . $word;
                if ($line !== '' && $this->pdf->GetStringWidth($try) > $width) {
                    $out[] = $line;
                    $line = $word;
                } else {
                    $line = $try;
                }
            }
            $out[] = $line;
            if (count($out) > 60) {
                break;
            }
        }
        return $out;
    }

    private function clip(string $text, float $width, bool $bold, float $pt): string
    {
        $this->pdf->SetFont('DejaVu', $bold ? 'B' : '', $pt);
        if ($text === '' || $this->pdf->GetStringWidth($text) <= $width) {
            return $text;
        }
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $s = '';
        foreach ($chars as $ch) {
            if ($this->pdf->GetStringWidth($s . $ch) > $width) {
                break;
            }
            $s .= $ch;
        }
        return $s;
    }

    // ------------------------------------------------------------------ значення

    /** @return array{0: int, 1: int} [колонка, рядок] */
    private function ref(string $a1): array
    {
        if (!preg_match('/^([A-Z]{1,3})(\d+)$/', strtoupper($a1), $m)) {
            return [0, 0];
        }
        $col = 0;
        foreach (str_split($m[1]) as $ch) {
            $col = $col * 26 + (ord($ch) - 64);
        }
        return [$col, (int) $m[2]];
    }

    private function colName(int $n): string
    {
        $s = '';
        while ($n > 0) {
            $s = chr(65 + ($n - 1) % 26) . $s;
            $n = intdiv($n - 1, 26);
        }
        return $s;
    }

    private function hasFill(int $s): bool
    {
        $fill = $this->xfs[$s]['fill'] ?? 0;
        return ($this->fills[(int) $fill] ?? null) !== null;
    }

    private function isNumeric(\DOMElement $c): bool
    {
        $t = $c->getAttribute('t');
        return ($t === '' || $t === 'n') && $c->getElementsByTagNameNS(self::NS, 'v')->length > 0;
    }

    private function cellText(\DOMElement $c, \DOMXPath $xp): string
    {
        $t = $c->getAttribute('t');
        $vNode = $c->getElementsByTagNameNS(self::NS, 'v')->item(0);
        $v = $vNode ? $vNode->textContent : '';
        switch ($t) {
            case 's':
                return $this->shared[(int) $v] ?? '';
            case 'inlineStr':
                $is = $c->getElementsByTagNameNS(self::NS, 'is')->item(0);
                return $is ? $is->textContent : '';
            case 'b':
                return $v === '1' ? 'TRUE' : 'FALSE';
            case 'str':
            case 'e':
                return $v;
        }
        if ($v === '' || !is_numeric($v)) {
            return $v;
        }
        $xf = $this->xfs[(int) $c->getAttribute('s')] ?? null;
        return $this->formatNumber((float) $v, $xf ? (int) $xf['fmt'] : 0);
    }

    private function formatNumber(float $v, int $fmtId): string
    {
        $builtin = [1 => '0', 2 => '0.00', 3 => '#,##0', 4 => '#,##0.00', 9 => '0%', 10 => '0.00%', 14 => 'dd.mm.yyyy', 15 => 'd-mmm-yy', 16 => 'd-mmm',
                    17 => 'mmm-yy', 18 => 'h:mm AM/PM', 19 => 'h:mm:ss AM/PM', 20 => 'h:mm', 21 => 'h:mm:ss', 22 => 'dd.mm.yyyy h:mm',
                    37 => '#,##0', 38 => '#,##0', 39 => '#,##0.00', 40 => '#,##0.00', 45 => 'mm:ss', 46 => 'h:mm:ss', 47 => 'mm:ss.0'];
        $code = $this->numFmts[$fmtId] ?? $builtin[$fmtId] ?? 'General';
        $code = explode(';', $code)[0];
        $bare = preg_replace('/"[^"]*"|\[[^\]]*\]|\\\\.|_.|\*./u', '', $code) ?? $code;
        if (preg_match('/[ymdhs]/i', $bare) && !preg_match('/^[#0,.%\s]*$/', $bare)) {
            return $this->formatDate($v, $code);
        }
        if (strtolower($code) === 'general' || $code === '@') {
            return $this->general($v);
        }
        $percent = str_contains($bare, '%');
        if ($percent) {
            $v *= 100;
        }
        $decimals = 0;
        if (preg_match('/\.([0#?]+)/', $bare, $m)) {
            $decimals = strlen($m[1]);
        }
        $thousands = (bool) preg_match('/[0#],[0#]/', $bare);
        $s = number_format($v, $decimals, '.', $thousands ? "\u{A0}" : '');
        $s = str_replace('.', ',', $s);
        // Літерали й валюта з формату: "грн", $, € тощо.
        if (preg_match_all('/"([^"]*)"|\[\$([^\]\-]*)[^\]]*\]/u', $code, $mm, PREG_SET_ORDER)) {
            $suffix = '';
            foreach ($mm as $lit) {
                $suffix .= $lit[1] !== '' ? $lit[1] : ($lit[2] ?? '');
            }
            if (trim($suffix) !== '') {
                $s .= ' ' . trim($suffix);
            }
        }
        return $s . ($percent ? '%' : '');
    }

    private function general(float $v): string
    {
        if ($v == floor($v) && abs($v) < 1e15) {
            return (string) (int) $v;
        }
        $s = rtrim(rtrim(sprintf('%.10F', $v), '0'), '.');
        return str_replace('.', ',', $s);
    }

    private function formatDate(float $serial, string $code): string
    {
        $days = (int) floor($serial);
        $secs = (int) round(($serial - $days) * 86400);
        $ts = ($days - 25569) * 86400; // 1970-01-01 = 25569
        $d = (new \DateTimeImmutable('@' . $ts))->setTimezone(new \DateTimeZone('UTC'));
        $h = intdiv($secs, 3600);
        $mi = intdiv($secs % 3600, 60);
        $se = $secs % 60;
        $code = preg_replace('/\[[^\]]*\]|"[^"]*"|\\\\./u', '', $code) ?? $code;
        $ampm = (bool) preg_match('/AM\/PM|A\/P/i', $code);
        $out = '';
        $prev = '';
        $tokens = preg_split('/(y+|m+|d+|h+|s+|AM\/PM|A\/P)/i', $code, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($tokens as $i => $tok) {
            $l = strtolower($tok);
            if (preg_match('/^y+$/', $l)) {
                $out .= strlen($l) <= 2 ? $d->format('y') : $d->format('Y');
            } elseif (preg_match('/^d+$/', $l)) {
                $out .= strlen($l) === 1 ? $d->format('j') : (strlen($l) === 2 ? $d->format('d') : $d->format('D'));
            } elseif (preg_match('/^h+$/', $l)) {
                $hh = $ampm ? (($h % 12) ?: 12) : $h;
                $out .= strlen($l) === 1 ? (string) $hh : sprintf('%02d', $hh);
            } elseif (preg_match('/^s+$/', $l)) {
                $out .= strlen($l) === 1 ? (string) $se : sprintf('%02d', $se);
            } elseif (preg_match('/^m+$/', $l)) {
                // «mm» після h або перед s — хвилини; інакше місяць.
                $before = rtrim($prev, ':. ');
                $after = $tokens[$i + 2] ?? '';
                $isMinute = preg_match('/h+$/i', $before) === 1 || preg_match('/^s/i', ltrim((string) ($tokens[$i + 1] ?? ''), ':. ') . ($after)) === 1 && str_contains((string) ($tokens[$i + 1] ?? ''), ':');
                if ($isMinute) {
                    $out .= strlen($l) === 1 ? (string) $mi : sprintf('%02d', $mi);
                } elseif (strlen($l) >= 4) {
                    $out .= self::MONTHS[(int) $d->format('n') - 1];
                } elseif (strlen($l) === 3) {
                    $out .= mb_substr(self::MONTHS[(int) $d->format('n') - 1], 0, 3);
                } else {
                    $out .= strlen($l) === 1 ? $d->format('n') : $d->format('m');
                }
            } elseif (preg_match('/^(am\/pm|a\/p)$/', $l)) {
                $out .= $h >= 12 ? 'PM' : 'AM';
            } else {
                $out .= $tok;
            }
            $prev .= $tok;
        }
        return trim($out);
    }
}
