<?php

declare(strict_types=1);

namespace App\Services\Office;

/**
 * Мінімальний читач ZIP (docx/xlsx — це ZIP) без розширення PHP «zip»: центральний каталог + gzinflate.
 * Захист від «ZIP-бомб»: ліміти на розмір одного запису й сумарний розмір прочитаного.
 */
final class OfficeZip
{
    private const MAX_ENTRY = 64 * 1048576;
    private const MAX_TOTAL = 256 * 1048576;

    /** @var array<string, array{method: int, size: int, csize: int, offset: int}> */
    private array $entries = [];
    private int $read = 0;
    /** @var resource */
    private $fh;

    private function __construct($fh)
    {
        $this->fh = $fh;
    }

    public function __destruct()
    {
        if (is_resource($this->fh)) {
            fclose($this->fh);
        }
    }

    public static function open(string $path): self
    {
        $size = (int) @filesize($path);
        $fh = @fopen($path, 'rb');
        if ($fh === false || $size < 22) {
            throw new OfficeException('Файл не є коректним документом Office.');
        }
        $zip = new self($fh);
        $tailLength = min($size, 65557);
        fseek($fh, $size - $tailLength);
        $tail = (string) fread($fh, $tailLength);
        $pos = strrpos($tail, "PK\x05\x06");
        if ($pos === false || strlen($tail) - $pos < 22) {
            throw new OfficeException('Файл не є коректним документом Office.');
        }
        $e = unpack('vdisk/vcdDisk/vcdHere/vcount/VcdSize/VcdOffset', substr($tail, $pos + 4, 16));
        if ($e === false || $e['count'] < 1 || $e['count'] > 10000 || $e['cdSize'] > 8 * 1048576 || $e['cdOffset'] + $e['cdSize'] > $size) {
            throw new OfficeException('Файл не є коректним документом Office.');
        }
        fseek($fh, $e['cdOffset']);
        $cd = (string) fread($fh, $e['cdSize']);
        $off = 0;
        for ($i = 0; $i < $e['count']; $i++) {
            if (substr($cd, $off, 4) !== "PK\x01\x02" || $off + 46 > strlen($cd)) {
                throw new OfficeException('Файл пошкоджено.');
            }
            $h = unpack('vmethod/x4/Vcrc/Vcsize/Vsize/vnameLen/vextraLen/vcommentLen/x4/x4/Vlocal', substr($cd, $off + 10, 36));
            $name = substr($cd, $off + 46, $h['nameLen']);
            $zip->entries[$name] = ['method' => $h['method'], 'size' => $h['size'], 'csize' => $h['csize'], 'offset' => $h['local']];
            $off += 46 + $h['nameLen'] + $h['extraLen'] + $h['commentLen'];
        }
        return $zip;
    }

    public function has(string $name): bool
    {
        return isset($this->entries[$name]);
    }

    /** Вміст запису або null, якщо його немає. */
    public function read(string $name): ?string
    {
        $en = $this->entries[$name] ?? null;
        if ($en === null) {
            return null;
        }
        if ($en['size'] > self::MAX_ENTRY || $this->read + $en['size'] > self::MAX_TOTAL) {
            throw new OfficeException('Документ завеликий для перегляду.');
        }
        fseek($this->fh, $en['offset']);
        $header = (string) fread($this->fh, 30);
        if (strlen($header) < 30 || substr($header, 0, 4) !== "PK\x03\x04") {
            throw new OfficeException('Файл пошкоджено.');
        }
        $l = unpack('vnameLen/vextraLen', substr($header, 26, 4));
        fseek($this->fh, $en['offset'] + 30 + $l['nameLen'] + $l['extraLen']);
        $data = $en['csize'] > 0 ? (string) fread($this->fh, $en['csize']) : '';
        if ($en['method'] === 8) {
            $data = @gzinflate($data, self::MAX_ENTRY + 1);
            if ($data === false || strlen($data) > self::MAX_ENTRY) {
                throw new OfficeException('Файл пошкоджено або завеликий.');
            }
        } elseif ($en['method'] !== 0) {
            throw new OfficeException('Непідтримуваний спосіб стиснення у файлі.');
        }
        $this->read += strlen($data);
        return $data;
    }

    /** XML-частина як DOM (без завантаження зовнішніх сутностей і DTD). */
    public function xml(string $name): ?\DOMDocument
    {
        $raw = $this->read($name);
        if ($raw === null) {
            return null;
        }
        if (stripos($raw, '<!DOCTYPE') !== false || stripos($raw, '<!ENTITY') !== false) {
            throw new OfficeException('Документ містить заборонені XML-конструкції.');
        }
        $dom = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $ok = $dom->loadXML($raw, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (!$ok) {
            throw new OfficeException('Не вдалося прочитати вміст документа (XML пошкоджено).');
        }
        return $dom;
    }
}
