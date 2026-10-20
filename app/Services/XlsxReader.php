<?php

namespace App\Services;

/**
 * Мінімальний читач першого аркуша .xlsx без розширення zip і сторонніх бібліотек (лише gzinflate):
 * текст, числа, спільні рядки, вбудовані рядки. Формули беруться за збереженим значенням; дати Excel (числа) читаються як числа.
 */
class XlsxReader
{
    private const MAX_ENTRY = 50 * 1024 * 1024;

    /** @return string[][] рядки таблиці (порожні рядки пропущено), значення — рядки */
    public static function read(string $path): array
    {
        $data = @file_get_contents($path);
        if ($data === false || strlen($data) < 22) {
            throw new \RuntimeException('Не вдалося прочитати файл.');
        }
        $files = self::entries($data);
        $sheetName = self::firstSheetPath($files);
        if (!isset($files[$sheetName])) {
            throw new \RuntimeException('У файлі XLSX не знайдено аркуша.');
        }
        $shared = [];
        if (isset($files['xl/sharedStrings.xml'])) {
            $sx = self::xml($files['xl/sharedStrings.xml']);
            foreach ($sx->si as $si) {
                $shared[] = self::textOf($si);
            }
        }
        $sheet = self::xml($files[$sheetName]);
        $rows = [];
        foreach ($sheet->sheetData->row ?? [] as $row) {
            $cells = [];
            foreach ($row->c as $c) {
                $ref = (string) $c['r'];
                $col = self::colIndex($ref);
                $t = (string) $c['t'];
                if ($t === 's') {
                    $val = $shared[(int) $c->v] ?? '';
                } elseif ($t === 'inlineStr') {
                    $val = self::textOf($c->is);
                } else {
                    $val = (string) $c->v;
                }
                $cells[$col] = $val;
            }
            if (!$cells) {
                continue;
            }
            $line = [];
            for ($i = 0; $i <= max(array_keys($cells)); $i++) {
                $line[] = $cells[$i] ?? '';
            }
            if (trim(implode('', $line)) !== '') {
                $rows[] = $line;
            }
        }
        return $rows;
    }

    private static function xml(string $s): \SimpleXMLElement
    {
        $prev = libxml_use_internal_errors(true);
        $x = simplexml_load_string($s, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        libxml_use_internal_errors($prev);
        if ($x === false) {
            throw new \RuntimeException('Файл XLSX пошкоджено.');
        }
        $x->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        return $x;
    }

    private static function textOf(\SimpleXMLElement $n): string
    {
        if (isset($n->t)) {
            return (string) $n->t;
        }
        $s = '';
        foreach ($n->r ?? [] as $r) {
            $s .= (string) $r->t;
        }
        return $s;
    }

    private static function colIndex(string $ref): int
    {
        preg_match('/^([A-Z]+)/', $ref, $m);
        $n = 0;
        foreach (str_split($m[1] ?? 'A') as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }
        return $n - 1;
    }

    private static function firstSheetPath(array $files): string
    {
        if (isset($files['xl/workbook.xml']) && isset($files['xl/_rels/workbook.xml.rels'])) {
            $wb = self::xml($files['xl/workbook.xml']);
            $rid = null;
            foreach ($wb->sheets->sheet ?? [] as $sh) {
                $attrs = $sh->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
                $rid = (string) ($attrs['id'] ?? '');
                break;
            }
            if ($rid) {
                $rels = self::xml($files['xl/_rels/workbook.xml.rels']);
                foreach ($rels->Relationship ?? [] as $rel) {
                    if ((string) $rel['Id'] === $rid) {
                        $t = ltrim((string) $rel['Target'], '/');
                        return str_starts_with($t, 'xl/') ? $t : 'xl/' . $t;
                    }
                }
            }
        }
        return 'xl/worksheets/sheet1.xml';
    }

    /** @return array<string, string> ім'я запису => вміст */
    private static function entries(string $z): array
    {
        $eocd = strrpos($z, "PK\x05\x06");
        if ($eocd === false) {
            throw new \RuntimeException('Це не файл XLSX (не zip-архів).');
        }
        $h = unpack('vdisk/vcd_disk/ventries_disk/ventries/Vcd_size/Vcd_offset', substr($z, $eocd + 4, 18));
        $pos = $h['cd_offset'];
        $out = [];
        for ($i = 0; $i < $h['entries']; $i++) {
            if (substr($z, $pos, 4) !== "PK\x01\x02") {
                break;
            }
            $c = unpack('vver/vneed/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vsize/vnlen/velen/vclen', substr($z, $pos + 4, 42));
            $name = substr($z, $pos + 46, $c['nlen']);
            $local = unpack('V', substr($z, $pos + 42, 4))[1];
            $pos += 46 + $c['nlen'] + $c['elen'] + $c['clen'];
            if (!in_array($name, ['xl/workbook.xml', 'xl/_rels/workbook.xml.rels', 'xl/sharedStrings.xml'], true) && !preg_match('#^xl/worksheets/sheet\d+\.xml$#', $name)) {
                continue;
            }
            if ($c['size'] > self::MAX_ENTRY) {
                throw new \RuntimeException('Файл XLSX занадто великий.');
            }
            $l = unpack('vnlen/velen', substr($z, $local + 26, 4));
            $raw = substr($z, $local + 30 + $l['nlen'] + $l['elen'], $c['csize']);
            if ($c['method'] === 0) {
                $out[$name] = $raw;
            } elseif ($c['method'] === 8) {
                $d = @gzinflate($raw, self::MAX_ENTRY);
                if ($d === false) {
                    throw new \RuntimeException('Файл XLSX пошкоджено.');
                }
                $out[$name] = $d;
            }
        }
        return $out;
    }
}
