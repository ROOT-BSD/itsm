<?php

namespace App\Services;

/**
 * Експорт таблиці у CSV або XLSX — без зовнішніх залежностей (розширення zip не потрібне).
 *
 * CSV: UTF-8 з BOM і роздільником «;» — так його без помилок із кодуванням відкриває Excel
 * в українській локалі. XLSX: один аркуш, жирний рядок заголовків, закріплений і з автофільтром.
 * Рядки, що починаються з = + - @, у CSV екрануються апострофом (захист від виконання формул в Excel);
 * у XLSX усі тексти записуються як текст, формулами не стають.
 */
class TableExport
{
    /** Скільки рядків даних дозволено в одному файлі (ліміт аркуша Excel — 1 048 575). */
    public const MAX_ROWS = 100000;

    public const FORMATS = ['csv', 'xlsx'];

    public static function formatFrom(?string $value): string
    {
        $value = strtolower(trim((string) $value));
        return in_array($value, self::FORMATS, true) ? $value : 'xlsx';
    }

    /** Відправляє файл у відповідь і завершує запит. */
    public static function send(string $baseName, string $format, array $headers, array $rows, string $sheetName = 'Дані'): void
    {
        $format = self::formatFrom($format);
        $rows = array_slice($rows, 0, self::MAX_ROWS);
        $file = preg_replace('/[^A-Za-z0-9_-]+/', '-', $baseName) . '-' . date('Y-m-d');

        if ($format === 'csv') {
            $body = self::csv($headers, $rows);
            $mime = 'text/csv; charset=utf-8';
        } else {
            $body = self::xlsx($headers, $rows, $sheetName);
            $mime = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: ' . $mime);
        header('Content-Disposition: attachment; filename="' . $file . '.' . $format . '"');
        header('Content-Length: ' . strlen($body));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        echo $body;
        exit;
    }

    public static function csv(array $headers, array $rows): string
    {
        $fh = fopen('php://temp', 'w+');
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, array_map([self::class, 'csvCell'], $headers), ';', '"', '\\', "\r\n");
        foreach ($rows as $row) {
            fputcsv($fh, array_map([self::class, 'csvCell'], array_values($row)), ';', '"', '\\', "\r\n");
        }
        rewind($fh);
        $out = stream_get_contents($fh);
        fclose($fh);
        return $out;
    }

    private static function csvCell(mixed $v): string
    {
        if ($v === null) {
            return '';
        }
        if (is_int($v) || is_float($v)) {
            return (string) $v;
        }
        $s = (string) $v;
        if ($s !== '' && strpbrk($s[0], "=+-@\t\r") !== false) {
            return "'" . $s;
        }
        return $s;
    }

    // ---------- XLSX ----------

    public static function xlsx(array $headers, array $rows, string $sheetName = 'Дані'): string
    {
        $sheetName = trim(preg_replace('/[\\\\\/\?\*\[\]:]/u', ' ', $sheetName)) ?: 'Дані';
        $sheetName = mb_substr($sheetName, 0, 31);
        $cols = count($headers);

        // Ширина колонок за найдовшим значенню (до 60 символів), перші 200 рядків.
        $widths = [];
        foreach ($headers as $i => $h) {
            $widths[$i] = mb_strlen((string) $h) + 2;
        }
        foreach (array_slice($rows, 0, 200) as $row) {
            foreach (array_values($row) as $i => $v) {
                if ($i < $cols) {
                    $widths[$i] = max($widths[$i], min(60, mb_strlen((string) $v) + 2));
                }
            }
        }

        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            . '<cols>';
        foreach ($widths as $i => $w) {
            $xml .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . min(60, max(8, $w)) . '" customWidth="1"/>';
        }
        $xml .= '</cols><sheetData>';

        $xml .= '<row r="1">';
        foreach (array_values($headers) as $i => $h) {
            $xml .= self::cell($i, 1, $h, 1);
        }
        $xml .= '</row>';

        $r = 1;
        foreach ($rows as $row) {
            $r++;
            $xml .= '<row r="' . $r . '">';
            foreach (array_values($row) as $i => $v) {
                if ($i >= $cols) {
                    break;
                }
                $xml .= self::cell($i, $r, $v, 0);
            }
            $xml .= '</row>';
        }
        $xml .= '</sheetData>';
        if ($cols > 0) {
            $xml .= '<autoFilter ref="A1:' . self::colName($cols - 1) . max(1, $r) . '"/>';
        }
        $xml .= '</worksheet>';

        $files = [
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                . '<Default Extension="xml" ContentType="application/xml"/>'
                . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
                . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
                . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
                . '</Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
                . '</Relationships>',
            'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
                . '<sheets><sheet name="' . self::esc($sheetName) . '" sheetId="1" r:id="rId1"/></sheets>'
                . ($cols > 0 ? '<definedNames><definedName name="_xlnm._FilterDatabase" localSheetId="0" hidden="1">\'' . str_replace("'", "''", self::esc($sheetName)) . '\'!$A$1:$' . self::colName($cols - 1) . '$' . max(1, $r) . '</definedName></definedNames>' : '')
                . '</workbook>',
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
                . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
                . '</Relationships>',
            'xl/styles.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
                . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
                . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
                . '<fill><patternFill patternType="solid"><fgColor rgb="FFE9ECEF"/><bgColor indexed="64"/></patternFill></fill></fills>'
                . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
                . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
                . '<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
                . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/></cellXfs>'
                . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
                . '</styleSheet>',
            'xl/worksheets/sheet1.xml' => $xml,
        ];
        return self::zip($files);
    }

    private static function cell(int $col, int $row, mixed $v, int $style): string
    {
        $ref = self::colName($col) . $row;
        $s = $style ? ' s="' . $style . '"' : '';
        if ($v === null || $v === '') {
            return '<c r="' . $ref . '"' . $s . '/>';
        }
        if (is_int($v) || is_float($v)) {
            return '<c r="' . $ref . '"' . $s . '><v>' . $v . '</v></c>';
        }
        return '<c r="' . $ref . '"' . $s . ' t="inlineStr"><is><t xml:space="preserve">' . self::esc((string) $v) . '</t></is></c>';
    }

    private static function colName(int $i): string
    {
        $n = '';
        for ($i++; $i > 0; $i = intdiv($i - 1, 26)) {
            $n = chr(65 + ($i - 1) % 26) . $n;
        }
        return $n;
    }

    /** Екранування для XML 1.0: керівні символи, недопустимі в XML, видаляються. */
    private static function esc(string $s): string
    {
        $s = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $s) ?? '';
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /** Мінімальний ZIP-записувач (метод deflate), щоб не залежати від розширення zip. */
    private static function zip(array $files): string
    {
        $out = '';
        $central = '';
        $count = 0;
        $dos = [0, 0x21]; // 1980-01-01 00:00 — вміст детермінований
        foreach ($files as $name => $data) {
            $crc = crc32($data);
            $comp = gzdeflate($data, 6);
            $method = 8;
            if ($comp === false || strlen($comp) >= strlen($data)) {
                $comp = $data;
                $method = 0;
            }
            $offset = strlen($out);
            $hdr = pack('vvvvvVVVvv', 20, 0x0800, $method, $dos[0], $dos[1], $crc, strlen($comp), strlen($data), strlen($name), 0);
            $out .= "PK\x03\x04" . $hdr . $name . $comp;
            $central .= "PK\x01\x02" . pack('vvvvvvVVVvvvvvVV', 20, 20, 0x0800, $method, $dos[0], $dos[1], $crc, strlen($comp), strlen($data), strlen($name), 0, 0, 0, 0, 0, $offset) . $name;
            $count++;
        }
        $cdOffset = strlen($out);
        return $out . $central . "PK\x05\x06" . pack('vvvvVVv', 0, 0, $count, $count, strlen($central), $cdOffset, 0);
    }
}
