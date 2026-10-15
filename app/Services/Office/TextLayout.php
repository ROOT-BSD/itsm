<?php

declare(strict_types=1);

namespace App\Services\Office;

/**
 * Розкладка тексту з різним оформленням у рядки (перенесення слів, вирівнювання) і малювання рядків у PDF.
 *
 * Токен: ['k' => 'w'|'s'|'tab'|'br'|'pb'|'img', 't' => текст, 'b','i','u','x' (закреслений), 'sz' => pt, 'c' => [r,g,b],
 *         'img' => ['file','w','h'] для k=img]. Ширини рахуються тут (поле 'w' у мм).
 */
final class TextLayout
{
    public const PT = 0.352778; // мм в одному пункті

    /** @var array<string, float> */
    private array $widthCache = [];

    public function __construct(private OfficePdf $pdf)
    {
    }

    /** Розбиває текст run-у на токени слів і пробілів. @param array<string, mixed> $style */
    public function tokenize(string $text, array $style): array
    {
        $out = [];
        $parts = preg_split('/(\s+)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($parts as $p) {
            $out[] = ['k' => ctype_space($p) || preg_match('/^\s+$/u', $p) ? 's' : 'w', 't' => preg_match('/^\s+$/u', $p) ? ' ' : $p] + $style;
        }
        return $out;
    }

    public function textWidth(string $text, bool $bold, float $sizePt): float
    {
        $key = ($bold ? 'B' : 'R') . $sizePt . '|' . $text;
        if (!isset($this->widthCache[$key])) {
            if (count($this->widthCache) > 20000) {
                $this->widthCache = [];
            }
            $this->pdf->SetFont('DejaVu', $bold ? 'B' : '', $sizePt);
            $this->widthCache[$key] = (float) $this->pdf->GetStringWidth($text);
        }
        return $this->widthCache[$key];
    }

    /**
     * @param array<int, array<string, mixed>> $tokens
     * @return array<int, array{tokens: array<int, array<string, mixed>>, w: float, h: float, base: float, pb: bool, last: bool}>
     */
    public function lines(array $tokens, float $width, float $firstIndent, float $factor, float $defaultSize = 11.0, float $tabStop = 12.7): array
    {
        $width = max(10.0, $width);
        $lines = [];
        $cur = [];
        $curW = 0.0;
        $limit = $width - $firstIndent; // перший рядок абзацу вужчий на відступ
        $flush = function (bool $pb = false, bool $last = false) use (&$lines, &$cur, &$curW, &$limit, $width, $factor, $defaultSize): void {
            // Хвостові пробіли не враховуємо.
            while ($cur && $cur[count($cur) - 1]['k'] === 's') {
                $curW -= $cur[count($cur) - 1]['w'];
                array_pop($cur);
            }
            $maxPt = $defaultSize;
            $maxImg = 0.0;
            foreach ($cur as $t) {
                if ($t['k'] === 'img') {
                    $maxImg = max($maxImg, $t['h']);
                } elseif (isset($t['sz'])) {
                    $maxPt = max($maxPt, (float) $t['sz']);
                }
            }
            if (!$cur) {
                $maxPt = $defaultSize;
            }
            $textH = $maxPt * self::PT * 1.2 * $factor;
            $h = max($textH, $maxImg + 0.5);
            $lines[] = ['tokens' => $cur, 'w' => $curW, 'h' => $h, 'base' => $h - $maxPt * self::PT * 0.28, 'pb' => $pb, 'last' => $last];
            $cur = [];
            $curW = 0.0;
            $limit = $width;
        };

        foreach ($tokens as $t) {
            if ($t['k'] === 'br') {
                $flush(false, true);
                continue;
            }
            if ($t['k'] === 'pb') {
                $flush(true, true);
                continue;
            }
            if ($t['k'] === 'tab') {
                $pos = ceil(($curW + 0.01) / $tabStop) * $tabStop;
                $t['w'] = max(1.0, $pos - $curW);
            } elseif ($t['k'] === 'img') {
                $t['w'] = $t['w'] ?? 0.0;
            } else {
                $t['w'] = $this->textWidth($t['t'], !empty($t['b']), (float) $t['sz']);
            }
            if ($t['k'] === 'w' && $t['w'] > $limit) {
                // Дуже довге слово: ріжемо за символами.
                $chars = preg_split('//u', $t['t'], -1, PREG_SPLIT_NO_EMPTY) ?: [];
                $piece = '';
                foreach ($chars as $ch) {
                    if ($this->textWidth($piece . $ch, !empty($t['b']), (float) $t['sz']) > $limit - $curW && $piece !== '') {
                        $p = ['t' => $piece, 'w' => $this->textWidth($piece, !empty($t['b']), (float) $t['sz'])] + $t;
                        $cur[] = $p;
                        $curW += $p['w'];
                        $flush();
                        $piece = '';
                    }
                    $piece .= $ch;
                }
                $t['t'] = $piece;
                $t['w'] = $this->textWidth($piece, !empty($t['b']), (float) $t['sz']);
            }
            if ($t['k'] === 's' && !$cur) {
                continue; // пробіли на початку рядка
            }
            if (($t['k'] === 'w' || $t['k'] === 'img') && $cur && $curW + $t['w'] > $limit + 0.01) {
                $flush();
            }
            $cur[] = $t;
            $curW += $t['w'];
        }
        $flush(false, true);
        return $lines;
    }

    /**
     * Малює рядок: $x — ліва межа текстового блока, $y — верх рядка.
     *
     * @param array{tokens: array<int, array<string, mixed>>, w: float, h: float, base: float} $line
     */
    public function drawLine(array $line, float $x, float $y, float $width, string $align, float $firstIndent = 0.0): void
    {
        $startX = $x + $firstIndent;
        $avail = $width - $firstIndent;
        $extraPerSpace = 0.0;
        if ($align === 'center') {
            $startX += max(0.0, ($avail - $line['w']) / 2);
        } elseif ($align === 'right') {
            $startX += max(0.0, $avail - $line['w']);
        } elseif ($align === 'justify' && empty($line['last'])) {
            $spaces = 0;
            foreach ($line['tokens'] as $t) {
                $spaces += $t['k'] === 's' ? 1 : 0;
            }
            if ($spaces > 0) {
                $extraPerSpace = max(0.0, ($avail - $line['w']) / $spaces);
            }
        }
        $cx = $startX;
        $baseY = $y + $line['base'];
        foreach ($line['tokens'] as $t) {
            $w = $t['w'] + ($t['k'] === 's' ? $extraPerSpace : 0.0);
            if ($t['k'] === 'img') {
                try {
                    $this->pdf->Image($t['file'], $cx, $y, $t['w'], $t['h']);
                } catch (\Throwable) {
                    // Нечитаний малюнок пропускаємо.
                }
            } elseif ($t['k'] === 'w') {
                $this->pdf->SetFont('DejaVu', !empty($t['b']) ? 'B' : '', (float) $t['sz']);
                $c = $t['c'] ?? [0, 0, 0];
                $this->pdf->SetTextColor($c[0], $c[1], $c[2]);
                if (!empty($t['i'])) {
                    $this->pdf->slantedText($cx, $baseY, $t['t']);
                } else {
                    $this->pdf->Text($cx, $baseY, $t['t']);
                }
                if (!empty($t['u']) || !empty($t['x'])) {
                    $this->pdf->SetDrawColor($c[0], $c[1], $c[2]);
                    $this->pdf->SetLineWidth(max(0.1, (float) $t['sz'] * self::PT * 0.06));
                    if (!empty($t['u'])) {
                        $this->pdf->Line($cx, $baseY + 0.4, $cx + $w, $baseY + 0.4);
                    }
                    if (!empty($t['x'])) {
                        $this->pdf->Line($cx, $baseY - (float) $t['sz'] * self::PT * 0.3, $cx + $w, $baseY - (float) $t['sz'] * self::PT * 0.3);
                    }
                }
            }
            $cx += $w;
        }
        $this->pdf->SetTextColor(0, 0, 0);
    }
}
