<?php

declare(strict_types=1);

namespace App\Services\Office;

/**
 * Вбудований конвертер DOCX → PDF без зовнішніх програм. Підтримує: абзаци (вирівнювання, відступи, інтервали), заголовки,
 * напівжирний/курсив/підкреслення/колір/розмір, списки (маркери й нумерація), таблиці (колонки, об'єднання по горизонталі,
 * заливка, рамки), малюнки PNG/JPEG, розриви рядка й сторінки, розмір і поля сторінки. Не підтримує: колонтитули, виноски,
 * текстові рамки/фігури, формули, зміст, GIF/EMF/WMF, складні обтікання — такий вміст пропускається, текст документа лишається.
 * Для точного відтворення використовується LibreOffice, якщо він встановлений (див. LibraryPreview).
 */
final class DocxToPdf
{
    private const W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    private const MAX_PAGES = 300;
    private const MAX_IMAGES = 60;

    private OfficeZip $zip;
    private OfficePdf $pdf;
    private TextLayout $layout;
    private \DOMXPath $xp;
    /** @var array<string, array<string, mixed>> */
    private array $styles = [];
    /** @var array<string, array{fmt: string}> numId:ilvl => формат */
    private array $numFormats = [];
    /** @var array<string, int> */
    private array $counters = [];
    /** @var array<string, string> rId => шлях частини */
    private array $rels = [];
    /** @var string[] */
    private array $tmpFiles = [];
    private int $images = 0;

    private float $pageW = 210.0;
    private float $pageH = 297.0;
    private float $mL = 25.0;
    private float $mR = 20.0;
    private float $mT = 20.0;
    private float $mB = 20.0;
    private float $y = 0.0;
    private float $defaultPt = 11.0;
    private float $defaultAfter = 0.0;
    private float $defaultFactor = 1.0;
    private float $deadline = 0.0;

    public static function convert(string $src, string $dest): void
    {
        $self = new self();
        try {
            $self->run($src, $dest);
        } finally {
            foreach ($self->tmpFiles as $f) {
                @unlink($f);
            }
        }
    }

    private function run(string $src, string $dest): void
    {
        $this->deadline = microtime(true) + 60;
        $this->zip = OfficeZip::open($src);
        $doc = $this->zip->xml('word/document.xml');
        if ($doc === null) {
            throw new OfficeException('У файлі немає тексту документа Word.');
        }
        $this->xp = new \DOMXPath($doc);
        $this->xp->registerNamespace('w', self::W);
        $this->xp->registerNamespace('a', 'http://schemas.openxmlformats.org/drawingml/2006/main');
        $this->xp->registerNamespace('wp', 'http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing');
        $this->xp->registerNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');

        $this->loadStyles();
        $this->loadNumbering();
        $this->loadRels();
        $this->readSection($doc);

        $this->pdf = new OfficePdf('P');
        $this->pdf->SetCreator('ITSM System');
        $this->pdf->SetMargins($this->mL, $this->mT, $this->mR);
        $this->layout = new TextLayout($this->pdf);
        $this->newPage();

        $body = $this->xp->query('/w:document/w:body')->item(0);
        if ($body instanceof \DOMElement) {
            foreach ($body->childNodes as $node) {
                $this->guard();
                if (!$node instanceof \DOMElement || $node->namespaceURI !== self::W) {
                    continue;
                }
                if ($node->localName === 'p') {
                    $this->paragraph($node, $this->mL, $this->contentW(), true);
                } elseif ($node->localName === 'tbl') {
                    $this->table($node);
                }
            }
        }
        $pdf = $this->pdf->Output('S');
        if (@file_put_contents($dest, $pdf) === false) {
            throw new OfficeException('Не вдалося записати PDF.');
        }
    }

    private function guard(): void
    {
        if (microtime(true) > $this->deadline) {
            throw new OfficeException('Конвертація зайняла надто багато часу.');
        }
        if (isset($this->pdf) && $this->pdf->PageNo() > self::MAX_PAGES) {
            throw new OfficeException('Документ завеликий для перегляду (понад ' . self::MAX_PAGES . ' сторінок). Завантажте файл.');
        }
    }

    private function contentW(): float
    {
        return $this->pageW - $this->mL - $this->mR;
    }

    private function newPage(): void
    {
        $this->pdf->AddPage($this->pageW > $this->pageH ? 'L' : 'P', [$this->pageW, $this->pageH]);
        $this->y = $this->mT;
    }

    private function bottom(): float
    {
        return $this->pageH - $this->mB;
    }

    // ------------------------------------------------------------------ стилі, нумерація, зв'язки

    private function readSection(\DOMDocument $doc): void
    {
        $sect = $this->xp->query('/w:document/w:body/w:sectPr')->item(0);
        if (!$sect) {
            return;
        }
        $sz = $this->xp->query('w:pgSz', $sect)->item(0);
        if ($sz instanceof \DOMElement) {
            $w = (float) $sz->getAttributeNS(self::W, 'w') / 56.6929;
            $h = (float) $sz->getAttributeNS(self::W, 'h') / 56.6929;
            if ($w > 50 && $h > 50 && $w < 1000 && $h < 1000) {
                $this->pageW = $w;
                $this->pageH = $h;
            }
        }
        $mar = $this->xp->query('w:pgMar', $sect)->item(0);
        if ($mar instanceof \DOMElement) {
            $get = fn(string $n, float $d): float => $mar->hasAttributeNS(self::W, $n) ? max(5.0, (float) $mar->getAttributeNS(self::W, $n) / 56.6929) : $d;
            $this->mL = $get('left', $this->mL);
            $this->mR = $get('right', $this->mR);
            $this->mT = $get('top', $this->mT);
            $this->mB = $get('bottom', $this->mB);
        }
        if ($this->contentW() < 40) {
            $this->mL = $this->mR = 20.0;
        }
    }

    private function loadStyles(): void
    {
        $dom = $this->zip->xml('word/styles.xml');
        if (!$dom) {
            return;
        }
        $xp = new \DOMXPath($dom);
        $xp->registerNamespace('w', self::W);
        $d = $xp->query('/w:styles/w:docDefaults/w:rPrDefault/w:rPr')->item(0);
        if ($d) {
            $r = $this->rpr($d, $xp);
            if (isset($r['sz'])) {
                $this->defaultPt = (float) $r['sz'];
            }
        }
        $dp = $xp->query('/w:styles/w:docDefaults/w:pPrDefault/w:pPr')->item(0);
        if ($dp) {
            $p = $this->ppr($dp, $xp);
            $this->defaultAfter = $p['after'] ?? 0.0;
            $this->defaultFactor = $p['factor'] ?? 1.0;
        }
        foreach ($xp->query('/w:styles/w:style') as $st) {
            $id = $st->getAttributeNS(self::W, 'styleId');
            $based = $xp->query('w:basedOn', $st)->item(0);
            $pp = $xp->query('w:pPr', $st)->item(0);
            $rp = $xp->query('w:rPr', $st)->item(0);
            $this->styles[$id] = [
                'based' => $based instanceof \DOMElement ? $based->getAttributeNS(self::W, 'val') : null,
                'p' => $pp ? $this->ppr($pp, $xp) : [],
                'r' => $rp ? $this->rpr($rp, $xp) : [],
                'name' => ($n = $xp->query('w:name', $st)->item(0)) instanceof \DOMElement ? strtolower($n->getAttributeNS(self::W, 'val')) : '',
            ];
        }
    }

    /** Параметри стилю з ланцюжком basedOn. @return array{p: array<string, mixed>, r: array<string, mixed>} */
    private function resolveStyle(?string $id): array
    {
        $p = [];
        $r = [];
        $chain = [];
        for ($i = 0; $id !== null && $id !== '' && isset($this->styles[$id]) && $i < 8; $i++) {
            array_unshift($chain, $id);
            $id = $this->styles[$id]['based'];
        }
        foreach ($chain as $sid) {
            $p = array_replace($p, $this->styles[$sid]['p']);
            $r = array_replace($r, $this->styles[$sid]['r']);
            $name = $this->styles[$sid]['name'];
            if (preg_match('/^heading (\d)$/', $name, $m) || $name === 'title') {
                $lvl = $name === 'title' ? 0 : (int) $m[1];
                $r += ['b' => true, 'sz' => [0 => 22.0, 1 => 18.0, 2 => 15.0, 3 => 13.0, 4 => 12.0][$lvl] ?? 11.0];
                $p += ['before' => $lvl <= 1 ? 6.0 : 4.0, 'after' => 3.0];
            }
        }
        return ['p' => $p, 'r' => $r];
    }

    private function ppr(\DOMNode $pPr, ?\DOMXPath $xp = null): array
    {
        $xp ??= $this->xp;
        $o = [];
        $jc = $xp->query('w:jc', $pPr)->item(0);
        if ($jc instanceof \DOMElement) {
            $v = $jc->getAttributeNS(self::W, 'val');
            $o['align'] = ['center' => 'center', 'right' => 'right', 'end' => 'right', 'both' => 'justify', 'distribute' => 'justify'][$v] ?? 'left';
        }
        $sp = $xp->query('w:spacing', $pPr)->item(0);
        if ($sp instanceof \DOMElement) {
            if ($sp->hasAttributeNS(self::W, 'before')) {
                $o['before'] = (float) $sp->getAttributeNS(self::W, 'before') / 20 * TextLayout::PT;
            }
            if ($sp->hasAttributeNS(self::W, 'after')) {
                $o['after'] = (float) $sp->getAttributeNS(self::W, 'after') / 20 * TextLayout::PT;
            }
            if ($sp->hasAttributeNS(self::W, 'line') && in_array($sp->getAttributeNS(self::W, 'lineRule'), ['', 'auto'], true)) {
                $o['factor'] = min(3.0, max(0.7, (float) $sp->getAttributeNS(self::W, 'line') / 240));
            }
        }
        $ind = $xp->query('w:ind', $pPr)->item(0);
        if ($ind instanceof \DOMElement) {
            foreach (['left' => 'left', 'start' => 'left', 'firstLine' => 'first', 'hanging' => 'hanging', 'right' => 'right', 'end' => 'right'] as $attr => $key) {
                if ($ind->hasAttributeNS(self::W, $attr)) {
                    $o[$key] = (float) $ind->getAttributeNS(self::W, $attr) / 56.6929;
                }
            }
        }
        return $o;
    }

    private function rpr(\DOMNode $rPr, ?\DOMXPath $xp = null): array
    {
        $xp ??= $this->xp;
        $o = [];
        foreach (['b' => 'b', 'i' => 'i', 'strike' => 'x', 'dstrike' => 'x'] as $tag => $key) {
            $n = $xp->query('w:' . $tag, $rPr)->item(0);
            if ($n instanceof \DOMElement) {
                $v = $n->getAttributeNS(self::W, 'val');
                $o[$key] = !in_array($v, ['0', 'false', 'off'], true);
            }
        }
        $u = $xp->query('w:u', $rPr)->item(0);
        if ($u instanceof \DOMElement) {
            $o['u'] = $u->getAttributeNS(self::W, 'val') !== 'none';
        }
        $sz = $xp->query('w:sz', $rPr)->item(0);
        if ($sz instanceof \DOMElement && (float) $sz->getAttributeNS(self::W, 'val') > 0) {
            $o['sz'] = min(72.0, (float) $sz->getAttributeNS(self::W, 'val') / 2);
        }
        $c = $xp->query('w:color', $rPr)->item(0);
        if ($c instanceof \DOMElement && preg_match('/^[0-9A-Fa-f]{6}$/', $c->getAttributeNS(self::W, 'val'))) {
            $h = $c->getAttributeNS(self::W, 'val');
            $o['c'] = [hexdec(substr($h, 0, 2)), hexdec(substr($h, 2, 2)), hexdec(substr($h, 4, 2))];
        }
        return $o;
    }

    private function loadNumbering(): void
    {
        $dom = $this->zip->xml('word/numbering.xml');
        if (!$dom) {
            return;
        }
        $xp = new \DOMXPath($dom);
        $xp->registerNamespace('w', self::W);
        $abstract = [];
        foreach ($xp->query('/w:numbering/w:abstractNum') as $a) {
            $id = $a->getAttributeNS(self::W, 'abstractNumId');
            foreach ($xp->query('w:lvl', $a) as $lvl) {
                $fmt = $xp->query('w:numFmt', $lvl)->item(0);
                $abstract[$id][$lvl->getAttributeNS(self::W, 'ilvl')] = $fmt instanceof \DOMElement ? $fmt->getAttributeNS(self::W, 'val') : 'decimal';
            }
        }
        foreach ($xp->query('/w:numbering/w:num') as $n) {
            $numId = $n->getAttributeNS(self::W, 'numId');
            $abs = $xp->query('w:abstractNumId', $n)->item(0);
            $absId = $abs instanceof \DOMElement ? $abs->getAttributeNS(self::W, 'val') : '';
            foreach ($abstract[$absId] ?? [] as $ilvl => $fmt) {
                $this->numFormats[$numId . ':' . $ilvl] = ['fmt' => $fmt];
            }
        }
    }

    private function loadRels(): void
    {
        $dom = $this->zip->xml('word/_rels/document.xml.rels');
        if (!$dom) {
            return;
        }
        foreach ($dom->getElementsByTagName('Relationship') as $rel) {
            $target = $rel->getAttribute('Target');
            $this->rels[$rel->getAttribute('Id')] = str_starts_with($target, '/') ? ltrim($target, '/') : 'word/' . $target;
        }
    }

    // ------------------------------------------------------------------ абзаци

    /**
     * @return float висота, яку зайняв абзац (для таблиць — вимір без малювання, якщо $draw = false)
     */
    private function paragraph(\DOMElement $p, float $x, float $width, bool $draw, ?float $maxHeight = null): float
    {
        $pPr = $this->xp->query('w:pPr', $p)->item(0);
        $styleId = null;
        $direct = [];
        $numPr = null;
        if ($pPr) {
            $ps = $this->xp->query('w:pStyle', $pPr)->item(0);
            $styleId = $ps instanceof \DOMElement ? $ps->getAttributeNS(self::W, 'val') : null;
            $direct = $this->ppr($pPr);
            $numPr = $this->xp->query('w:numPr', $pPr)->item(0);
        }
        $resolved = $this->resolveStyle($styleId);
        $props = array_replace($resolved['p'], $direct);
        $baseR = array_replace(['sz' => $this->defaultPt], $resolved['r']);

        $tokens = $this->runs($p, $baseR);
        $before = (float) ($props['before'] ?? 0.0);
        $after = (float) ($props['after'] ?? $this->defaultAfter);
        $factor = (float) ($props['factor'] ?? $this->defaultFactor);
        $align = (string) ($props['align'] ?? 'left');
        $left = (float) ($props['left'] ?? 0.0);
        $first = (float) ($props['first'] ?? 0.0) - (float) ($props['hanging'] ?? 0.0);
        $right = (float) ($props['right'] ?? 0.0);

        // Список: мітка висить зліва від тексту.
        $label = null;
        if ($numPr) {
            $numIdN = $this->xp->query('w:numId', $numPr)->item(0);
            $ilvlN = $this->xp->query('w:ilvl', $numPr)->item(0);
            $numId = $numIdN instanceof \DOMElement ? $numIdN->getAttributeNS(self::W, 'val') : '0';
            $ilvl = $ilvlN instanceof \DOMElement ? $ilvlN->getAttributeNS(self::W, 'val') : '0';
            if ($numId !== '0') {
                $fmt = $this->numFormats[$numId . ':' . $ilvl]['fmt'] ?? 'bullet';
                if ($left <= 0) {
                    $left = 7.0 * ((int) $ilvl + 1);
                    $first = -5.0;
                }
                $label = $this->listLabel($numId, (int) $ilvl, $fmt);
            }
        }
        $textW = max(20.0, $width - $left - $right);
        $firstIndent = max(-$left, $first);
        $lines = $this->layout->lines($tokens, $textW, max(0.0, $firstIndent), $factor, (float) $baseR['sz']);
        $height = 0.0;
        foreach ($lines as $l) {
            $height += $l['h'];
        }
        $total = $before + $height + $after;
        if (!$draw) {
            return $maxHeight !== null ? min($total, $maxHeight) : $total;
        }

        // Малювання: переносимо рядки на нові сторінки.
        $this->y += $before;
        foreach ($lines as $i => $l) {
            $this->guard();
            if ($this->y + $l['h'] > $this->bottom() && $this->y > $this->mT + 0.5) {
                $this->newPage();
            }
            $indent = $i === 0 ? max(0.0, $firstIndent) : 0.0;
            if ($i === 0 && $label !== null) {
                $this->pdf->SetFont('DejaVu', '', (float) $baseR['sz']);
                $this->pdf->Text($x + $left + $firstIndent, $this->y + $l['base'], $label);
            }
            $this->layout->drawLine($l, $x + $left, $this->y, $textW, $align, $indent);
            $this->y += $l['h'];
            if (!empty($l['pb'])) {
                $this->newPage();
            }
        }
        $this->y += $after;
        return $total;
    }

    /** Малює абзац усередині комірки таблиці в заданих координатах. */
    private function paragraphAt(\DOMElement $p, float $x, float $y, float $width, float $maxY): float
    {
        $savedY = $this->y;
        $pPr = $this->xp->query('w:pPr', $p)->item(0);
        $styleId = null;
        $direct = [];
        if ($pPr) {
            $ps = $this->xp->query('w:pStyle', $pPr)->item(0);
            $styleId = $ps instanceof \DOMElement ? $ps->getAttributeNS(self::W, 'val') : null;
            $direct = $this->ppr($pPr);
        }
        $resolved = $this->resolveStyle($styleId);
        $props = array_replace($resolved['p'], $direct);
        $baseR = array_replace(['sz' => $this->defaultPt], $resolved['r']);
        $tokens = $this->runs($p, $baseR);
        $factor = (float) ($props['factor'] ?? 1.0);
        $lines = $this->layout->lines($tokens, $width, 0.0, $factor, (float) $baseR['sz']);
        $cy = $y;
        foreach ($lines as $l) {
            if ($cy + $l['h'] > $maxY + 0.01) {
                break;
            }
            $this->layout->drawLine($l, $x, $cy, $width, (string) ($props['align'] ?? 'left'));
            $cy += $l['h'];
        }
        $this->y = $savedY;
        return $cy - $y + (float) ($props['after'] ?? 0.0) * 0.5;
    }

    private function listLabel(string $numId, int $ilvl, string $fmt): string
    {
        if ($fmt === 'bullet' || $fmt === 'none') {
            return $ilvl % 2 === 0 ? '•' : '–';
        }
        $key = $numId . ':' . $ilvl;
        $this->counters[$key] = ($this->counters[$key] ?? 0) + 1;
        // Лічильники глибших рівнів починаються заново.
        foreach (array_keys($this->counters) as $k) {
            if (str_starts_with($k, $numId . ':') && (int) substr($k, strlen($numId) + 1) > $ilvl) {
                unset($this->counters[$k]);
            }
        }
        $n = $this->counters[$key];
        return match ($fmt) {
            'lowerLetter' => chr(96 + (($n - 1) % 26) + 1) . ')',
            'upperLetter' => chr(64 + (($n - 1) % 26) + 1) . '.',
            'lowerRoman' => strtolower($this->roman($n)) . '.',
            'upperRoman' => $this->roman($n) . '.',
            default => $n . '.',
        };
    }

    private function roman(int $n): string
    {
        $out = '';
        foreach (['M' => 1000, 'CM' => 900, 'D' => 500, 'CD' => 400, 'C' => 100, 'XC' => 90, 'L' => 50, 'XL' => 40, 'X' => 10, 'IX' => 9, 'V' => 5, 'IV' => 4, 'I' => 1] as $s => $v) {
            while ($n >= $v) {
                $out .= $s;
                $n -= $v;
            }
        }
        return $out;
    }

    /** Токени абзацу з run-ів. @param array<string, mixed> $baseR @return array<int, array<string, mixed>> */
    private function runs(\DOMElement $container, array $baseR): array
    {
        $tokens = [];
        foreach ($container->childNodes as $n) {
            if (!$n instanceof \DOMElement || $n->namespaceURI !== self::W) {
                continue;
            }
            switch ($n->localName) {
                case 'r':
                    $style = $baseR;
                    $rPr = $this->xp->query('w:rPr', $n)->item(0);
                    if ($rPr) {
                        $rs = $this->xp->query('w:rStyle', $rPr)->item(0);
                        if ($rs instanceof \DOMElement) {
                            $style = array_replace($style, $this->resolveStyle($rs->getAttributeNS(self::W, 'val'))['r']);
                        }
                        $style = array_replace($style, $this->rpr($rPr));
                    }
                    foreach ($n->childNodes as $c) {
                        if (!$c instanceof \DOMElement) {
                            continue;
                        }
                        $base = ['b' => !empty($style['b']), 'i' => !empty($style['i']), 'u' => !empty($style['u']), 'x' => !empty($style['x']),
                                 'sz' => (float) $style['sz'], 'c' => $style['c'] ?? [0, 0, 0]];
                        switch ($c->localName) {
                            case 't':
                                foreach ($this->layout->tokenize($c->textContent, $base) as $t) {
                                    $tokens[] = $t;
                                }
                                break;
                            case 'tab':
                                $tokens[] = ['k' => 'tab'] + $base;
                                break;
                            case 'br':
                            case 'cr':
                                $type = $c->getAttributeNS(self::W, 'type');
                                $tokens[] = ['k' => $type === 'page' ? 'pb' : 'br'] + $base;
                                break;
                            case 'noBreakHyphen':
                                $tokens[] = ['k' => 'w', 't' => '-'] + $base;
                                break;
                            case 'drawing':
                                $img = $this->image($c);
                                if ($img !== null) {
                                    $tokens[] = $img;
                                }
                                break;
                        }
                    }
                    break;
                case 'hyperlink':
                case 'smartTag':
                case 'ins':
                case 'sdt':
                case 'sdtContent':
                case 'fldSimple':
                    foreach ($this->runs($n, $baseR) as $t) {
                        $tokens[] = $t;
                    }
                    break;
            }
        }
        return $tokens;
    }

    /** @return array<string, mixed>|null */
    private function image(\DOMElement $drawing): ?array
    {
        if ($this->images >= self::MAX_IMAGES) {
            return null;
        }
        $blip = $this->xp->query('.//a:blip', $drawing)->item(0);
        $ext = $this->xp->query('.//wp:extent', $drawing)->item(0);
        if (!$blip instanceof \DOMElement) {
            return null;
        }
        $rid = $blip->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'embed');
        $part = $this->rels[$rid] ?? null;
        $bin = $part !== null ? $this->zip->read($part) : null;
        if ($bin === null) {
            return null;
        }
        $type = str_starts_with($bin, "\x89PNG") ? 'png' : (str_starts_with($bin, "\xFF\xD8") ? 'jpg' : null);
        if ($type === null) {
            return null;
        }
        $w = $ext instanceof \DOMElement ? (float) $ext->getAttribute('cx') / 36000 : 60.0;
        $h = $ext instanceof \DOMElement ? (float) $ext->getAttribute('cy') / 36000 : 40.0;
        if ($w <= 0 || $h <= 0) {
            return null;
        }
        $max = $this->contentW();
        if ($w > $max) {
            $h *= $max / $w;
            $w = $max;
        }
        $maxH = $this->pageH - $this->mT - $this->mB - 5;
        if ($h > $maxH) {
            $w *= $maxH / $h;
            $h = $maxH;
        }
        $file = tempnam(sys_get_temp_dir(), 'itsmimg') . '.' . $type;
        if (@file_put_contents($file, $bin) === false) {
            return null;
        }
        $this->tmpFiles[] = $file;
        $this->images++;
        return ['k' => 'img', 'file' => $file, 'w' => $w, 'h' => $h];
    }

    // ------------------------------------------------------------------ таблиці

    private function table(\DOMElement $tbl): void
    {
        $grid = [];
        foreach ($this->xp->query('w:tblGrid/w:gridCol', $tbl) as $gc) {
            $grid[] = max(1.0, (float) $gc->getAttributeNS(self::W, 'w') / 56.6929);
        }
        $rows = $this->xp->query('w:tr', $tbl);
        if ($rows->length === 0) {
            return;
        }
        if (!$grid) {
            $maxCells = 1;
            foreach ($rows as $tr) {
                $maxCells = max($maxCells, $this->xp->query('w:tc', $tr)->length);
            }
            $grid = array_fill(0, $maxCells, $this->contentW() / $maxCells);
        }
        $sum = array_sum($grid);
        $avail = $this->contentW();
        if ($sum > $avail) {
            $grid = array_map(static fn($v) => $v * $avail / $sum, $grid);
        }
        $borders = $this->hasBorders($tbl);
        $pad = 1.5;

        foreach ($rows as $tr) {
            $this->guard();
            $cells = [];
            $col = 0;
            $maxH = 4.0;
            foreach ($this->xp->query('w:tc', $tr) as $tc) {
                $span = 1;
                $span = ($gs = $this->xp->query('w:tcPr/w:gridSpan', $tc)->item(0)) instanceof \DOMElement ? max(1, (int) $gs->getAttributeNS(self::W, 'val')) : 1;
                $w = array_sum(array_slice($grid, $col, $span));
                if ($w <= 0) {
                    $w = end($grid) ?: 20.0;
                }
                $paras = [];
                foreach ($this->xp->query('.//w:p', $tc) as $p) {
                    $paras[] = $p;
                }
                $fill = ($sh = $this->xp->query('w:tcPr/w:shd', $tc)->item(0)) instanceof \DOMElement ? $sh->getAttributeNS(self::W, 'fill') : '';
                $h = $pad * 2;
                foreach ($paras as $p) {
                    $h += $this->paragraph($p, 0.0, max(5.0, $w - $pad * 2), false);
                }
                $maxH = max($maxH, $h);
                $cells[] = ['x' => array_sum(array_slice($grid, 0, $col)), 'w' => $w, 'p' => $paras, 'fill' => $fill];
                $col += $span;
            }
            $pageBody = $this->bottom() - $this->mT;
            $maxH = min($maxH, $pageBody);
            if ($this->y + $maxH > $this->bottom() && $this->y > $this->mT + 0.5) {
                $this->newPage();
            }
            foreach ($cells as $c) {
                $cx = $this->mL + $c['x'];
                if (preg_match('/^[0-9A-Fa-f]{6}$/', $c['fill'])) {
                    $this->pdf->SetFillColor(hexdec(substr($c['fill'], 0, 2)), hexdec(substr($c['fill'], 2, 2)), hexdec(substr($c['fill'], 4, 2)));
                    $this->pdf->Rect($cx, $this->y, $c['w'], $maxH, 'F');
                }
                if ($borders) {
                    $this->pdf->SetDrawColor(120, 120, 120);
                    $this->pdf->SetLineWidth(0.2);
                    $this->pdf->Rect($cx, $this->y, $c['w'], $maxH);
                }
                $cy = $this->y + $pad;
                foreach ($c['p'] as $p) {
                    $cy += $this->paragraphAt($p, $cx + $pad, $cy, max(5.0, $c['w'] - $pad * 2), $this->y + $maxH);
                }
            }
            $this->y += $maxH;
        }
        $this->y += 2.0;
    }

    private function hasBorders(\DOMElement $tbl): bool
    {
        $b = $this->xp->query('w:tblPr/w:tblBorders/*', $tbl);
        foreach ($b as $side) {
            if ($side instanceof \DOMElement && !in_array($side->getAttributeNS(self::W, 'val'), ['nil', 'none', ''], true)) {
                return true;
            }
        }
        $st = $this->xp->query('w:tblPr/w:tblStyle', $tbl)->item(0);
        $id = $st instanceof \DOMElement ? $st->getAttributeNS(self::W, 'val') : '';
        return $id !== '' && (stripos($id, 'grid') !== false || stripos($id, 'table') !== false && stripos($id, 'normal') === false);
    }
}
