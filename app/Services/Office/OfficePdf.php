<?php

declare(strict_types=1);

namespace App\Services\Office;

require_once __DIR__ . '/../../Vendor/tfpdf/tfpdf.php';

/** tFPDF з шрифтом DejaVu (кирилиця), нахиленим текстом (курсив) і номером сторінки внизу. */
final class OfficePdf extends \tFPDF
{
    public bool $pageNumbers = true;

    public function __construct(string $orientation = 'P')
    {
        parent::__construct($orientation, 'mm', 'A4');
        $this->AddFont('DejaVu', '', 'DejaVuSans.ttf', true);
        $this->AddFont('DejaVu', 'B', 'DejaVuSans-Bold.ttf', true);
        $this->SetAutoPageBreak(false);
        $this->SetCompression(true);
        $this->SetCreator('ITSM System');
    }

    public function Footer(): void
    {
        if (!$this->pageNumbers) {
            return;
        }
        $this->SetFont('DejaVu', '', 7);
        $this->SetTextColor(120, 120, 120);
        $this->SetXY(0, $this->h - 8);
        $this->Cell($this->w, 4, (string) $this->PageNo(), 0, 0, 'C');
        $this->SetTextColor(0, 0, 0);
    }

    /** Текст з імітацією курсиву (зсув по горизонталі навколо базової лінії). */
    public function slantedText(float $x, float $y, string $text): void
    {
        $yPdf = ($this->h - $y) * $this->k;
        $this->_out(sprintf('q 1 0 0.2 1 %.2F 0 cm', -0.2 * $yPdf));
        $this->Text($x, $y, $text);
        $this->_out('Q');
    }
}
