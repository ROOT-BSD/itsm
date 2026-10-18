<?php

namespace App\Services;

use App\Models\Ticket;

/**
 * «Звіт по тікетах»: облік часу роботи з тікетами за період створення — скільки створено/вирішено, середній і медіанний
 * час першої реакції та вирішення, у розрізі статусів, черг і операторів. Рахується в PHP по рядках Ticket::workReportRows().
 */
class TicketWorkReport
{
    /** Мінімальні хвилини → «2 д 3 г 15 хв»; null → «—». */
    public static function duration(?float $minutes): string
    {
        if ($minutes === null) {
            return '—';
        }
        $m = (int) round($minutes);
        if ($m < 1) {
            return '< 1 хв';
        }
        $d = intdiv($m, 1440);
        $h = intdiv($m % 1440, 60);
        $min = $m % 60;
        $parts = [];
        if ($d) {
            $parts[] = $d . ' д';
        }
        if ($h) {
            $parts[] = $h . ' г';
        }
        if ($min && $d === 0) {
            $parts[] = $min . ' хв';
        }
        return implode(' ', $parts) ?: '< 1 хв';
    }

    /** @param float[] $vals @return array{avg: ?float, median: ?float, max: ?float, n: int} */
    public static function stats(array $vals): array
    {
        $vals = array_values(array_filter($vals, static fn($v) => $v !== null && $v >= 0));
        $n = count($vals);
        if ($n === 0) {
            return ['avg' => null, 'median' => null, 'max' => null, 'n' => 0];
        }
        sort($vals);
        $median = $n % 2 ? $vals[intdiv($n, 2)] : ($vals[$n / 2 - 1] + $vals[$n / 2]) / 2;
        return ['avg' => array_sum($vals) / $n, 'median' => (float) $median, 'max' => (float) max($vals), 'n' => $n];
    }

    private static function group(array $rows, string $keyField, string $nameField, string $fallback): array
    {
        $groups = [];
        foreach ($rows as $r) {
            $k = $r[$keyField] ?? 0;
            $groups[$k] ??= ['name' => $r[$nameField] ?? $fallback, 'rows' => []];
            $groups[$k]['rows'][] = $r;
        }
        $out = [];
        foreach ($groups as $g) {
            $out[] = ['name' => $g['name']] + self::summarize($g['rows']);
        }
        usort($out, static fn($a, $b) => $b['total'] <=> $a['total']);
        return $out;
    }

    /** @return array{total: int, resolved: int, open: int, hours: float, response: array, resolution: array} */
    public static function summarize(array $rows): array
    {
        $resolved = 0;
        foreach ($rows as $r) {
            if (in_array($r['status'], ['resolved', 'closed'], true)) {
                $resolved++;
            }
        }
        return [
            'total' => count($rows),
            'resolved' => $resolved,
            'open' => count($rows) - $resolved,
            'hours' => array_sum(array_map(static fn($r) => (float) ($r['spent_hours'] ?? 0), $rows)),
            'response' => self::stats(array_map(static fn($r) => $r['response_min'] !== null ? (float) $r['response_min'] : null, $rows)),
            'resolution' => self::stats(array_map(static fn($r) => $r['resolution_min'] !== null ? (float) $r['resolution_min'] : null, $rows)),
        ];
    }

    /** @return array{summary: array, byStatus: array, byQueue: array, byOperator: array} */
    public static function build(array $rows): array
    {
        $byStatus = [];
        foreach (Ticket::STATUS_LABELS as $code => $label) {
            $byStatus[$code] = ['label' => $label, 'count' => 0];
        }
        foreach ($rows as $r) {
            if (isset($byStatus[$r['status']])) {
                $byStatus[$r['status']]['count']++;
            }
        }
        return [
            'summary' => self::summarize($rows),
            'byStatus' => $byStatus,
            'byQueue' => self::group($rows, 'queue_id', 'queue_name', '—'),
            'byOperator' => self::group($rows, 'operator_id', 'operator_name', 'Не призначено'),
        ];
    }
}
