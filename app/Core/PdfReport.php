<?php

namespace App\Core;

require_once __DIR__ . '/../Vendor/tfpdf/tfpdf.php';

/**
 * Тонка обгортка над tFPDF (бібліотека без Composer, підключена напряму
 * в app/Vendor/tfpdf/) — генерує PDF-звіт обліку часу з підтримкою
 * кирилиці через вбудований шрифт DejaVu Sans.
 */
class PdfReport
{
    private \tFPDF $pdf;

    public function __construct(string $title, array $filterLines)
    {
        $this->pdf = new \tFPDF('P', 'mm', 'A4');
        $this->pdf->AddFont('DejaVu', '', 'DejaVuSans.ttf', true);
        $this->pdf->AddFont('DejaVu', 'B', 'DejaVuSans-Bold.ttf', true);
        $this->pdf->SetMargins(15, 15, 15);
        $this->pdf->SetAutoPageBreak(true, 15);
        $this->pdf->AddPage();

        $this->pdf->SetFont('DejaVu', 'B', 16);
        $this->pdf->Cell(0, 10, $title, 0, 1);

        if (!empty($filterLines)) {
            $this->pdf->SetFont('DejaVu', '', 10);
            $this->pdf->SetTextColor(90, 90, 90);
            foreach ($filterLines as $line) {
                $this->pdf->Cell(0, 5, $line, 0, 1);
            }
            $this->pdf->SetTextColor(0, 0, 0);
        }
        $this->pdf->Ln(4);
    }

    /**
     * Горизонтальна стовпчикова діаграма розподілу годин по користувачах
     * (у шапці звіту, під фільтрами) — довжина смуги пропорційна кількості
     * годин, поруч підписані ім'я та точна сума.
     *
     * @param array<array{name: string, hours: float}> $data
     */
    public function userChart(array $data): void
    {
        if (empty($data)) {
            return;
        }

        $this->pdf->SetFont('DejaVu', 'B', 12);
        $this->pdf->Cell(0, 8, "Розподіл часу за користувачами", 0, 1);

        $maxHours = max(array_column($data, 'hours'));
        $maxHours = $maxHours > 0 ? $maxHours : 1.0;

        $labelWidth = 45;
        $maxBarWidth = 95;
        $barHeight = 5;
        $rowHeight = 7;

        $this->pdf->SetFont('DejaVu', '', 9);
        foreach ($data as $row) {
            $x = $this->pdf->GetX();
            $y = $this->pdf->GetY();

            $this->pdf->Cell($labelWidth, $rowHeight, mb_strimwidth($row['name'], 0, 22, '…'), 0, 0);

            $barWidth = max(($row['hours'] / $maxHours) * $maxBarWidth, 0.5);
            $this->pdf->SetFillColor(13, 110, 253);
            $this->pdf->Rect($x + $labelWidth, $y + ($rowHeight - $barHeight) / 2, $barWidth, $barHeight, 'F');

            $this->pdf->SetXY($x + $labelWidth + $maxBarWidth + 2, $y);
            $this->pdf->Cell(30, $rowHeight, number_format($row['hours'], 2) . ' год.', 0, 1);
        }
        $this->pdf->Ln(3);
    }

    /**
     * Таблиця записів обліку часу, розбита на секції по проєктах — кожен
     * проєкт зі своїм заголовком-плашкою і проміжним підсумком годин.
     *
     * @param array<array{header: string, width: float}> $columns
     * @param array<array{project_name: string, rows: array, subtotal: float}> $groups
     */
    public function groupedTable(array $columns, array $groups): void
    {
        $totalWidth = array_sum(array_column($columns, 'width'));
        $lastColWidth = end($columns)['width'];
        $restWidth = $totalWidth - $lastColWidth;

        foreach ($groups as $group) {
            $this->pdf->SetFont('DejaVu', 'B', 10);
            $this->pdf->SetFillColor(210, 225, 245);
            $this->pdf->Cell($totalWidth, 7, 'Проєкт: ' . $group['project_name'], 1, 1, 'L', true);

            $this->pdf->SetFont('DejaVu', 'B', 9);
            $this->pdf->SetFillColor(230, 230, 230);
            foreach ($columns as $col) {
                $this->pdf->Cell($col['width'], 7, $col['header'], 1, 0, 'L', true);
            }
            $this->pdf->Ln();

            $this->pdf->SetFont('DejaVu', '', 9);
            foreach ($group['rows'] as $row) {
                foreach ($columns as $i => $col) {
                    $this->pdf->Cell($col['width'], 6, (string) ($row[$i] ?? ''), 1);
                }
                $this->pdf->Ln();
            }

            $this->pdf->SetFont('DejaVu', 'B', 9);
            $this->pdf->SetFillColor(245, 245, 245);
            $this->pdf->Cell($restWidth, 6, 'Витрачено на опрацювання проєкту:', 1, 0, 'R', true);
            $this->pdf->Cell($lastColWidth, 6, number_format($group['subtotal'], 2), 1, 1, 'L', true);
            $this->pdf->Ln(3);
        }
    }

    public function heading(string $text): void
    {
        $this->pdf->Ln(3);
        $this->pdf->SetFont('DejaVu', 'B', 12);
        $this->pdf->Cell(0, 8, $text, 0, 1);
    }

    public function line(string $text): void
    {
        $this->pdf->SetFont('DejaVu', '', 11);
        $this->pdf->Cell(0, 6, $text, 0, 1);
    }

    /** Віддає готовий PDF браузеру як файл для завантаження. */
    public function download(string $filename): void
    {
        $this->pdf->Output('D', $filename);
    }
}
