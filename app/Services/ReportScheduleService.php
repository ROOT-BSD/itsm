<?php

namespace App\Services;

use App\Core\PdfReport;
use App\Models\Project;
use App\Models\ReportSchedule;
use App\Models\Setting;
use App\Models\Task;
use App\Models\TicketTimeLog;

/**
 * Звіти за розкладом: побудова звіту обліку часу (PDF/XLSX/CSV) за попередній тиждень чи місяць і надсилання вкладенням.
 * Той самий звіт, що й «Звіти → Сформувати PDF», але без браузера. Звіт будується з правами власника розкладу.
 */
class ReportScheduleService
{
    /** Попередній повний тиждень (пн–нд) або місяць відносно $now. @return array{0: string, 1: string, 2: string} [від, до, підпис] */
    public static function period(string $frequency, \DateTimeImmutable $now): array
    {
        if ($frequency === 'monthly') {
            $first = $now->modify('first day of last month');
            return [$first->format('Y-m-01'), $first->format('Y-m-t'), 'місяць ' . $first->format('Y-m')];
        }
        $monday = $now->modify('monday this week')->modify('-7 days');
        return [$monday->format('Y-m-d'), $monday->modify('+6 days')->format('Y-m-d'), 'тиждень ' . $monday->format('o-\WW')];
    }

    /** Момент, з якого розклад «має спрацювати» у поточному періоді. */
    public static function scheduledAt(array $s, \DateTimeImmutable $now): \DateTimeImmutable
    {
        if ($s['frequency'] === 'monthly') {
            $day = max(1, min(28, (int) $s['month_day']));
            return $now->setDate((int) $now->format('Y'), (int) $now->format('n'), $day)->setTime((int) $s['send_hour'], 0);
        }
        $monday = $now->modify('monday this week');
        return $monday->modify('+' . (max(1, min(7, (int) $s['weekday'])) - 1) . ' days')->setTime((int) $s['send_hour'], 0);
    }

    /** Пора надсилати: час настав, а попередній успішний запуск був раніше за нього (є «наздоганяння» пропущеного cron). */
    public static function isDue(array $s, \DateTimeImmutable $now): bool
    {
        if (!(int) $s['is_active'] || !(int) $s['owner_active']) {
            return false;
        }
        $at = self::scheduledAt($s, $now);
        if ($now < $at) {
            return false;
        }
        return empty($s['last_run_at']) || new \DateTimeImmutable($s['last_run_at']) < $at;
    }

    /** @return string[] адреси: власник + додаткові (унікальні, коректні) */
    public static function recipients(array $s): array
    {
        $list = [strtolower((string) $s['owner_email'])];
        foreach (preg_split('/[\s,;]+/', (string) ($s['extra_recipients'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) as $e) {
            if (filter_var($e, FILTER_VALIDATE_EMAIL)) {
                $list[] = strtolower($e);
            }
        }
        return array_values(array_unique($list));
    }

    /** @return array{rows: array, filterLines: string[], label: string, from: string, to: string} */
    public static function data(array $s, \DateTimeImmutable $now): array
    {
        [$from, $to, $label] = self::period((string) $s['frequency'], $now);
        $isAdmin = ($s['owner_role'] ?? '') === 'admin';
        $uid = (int) $s['user_id'];
        $projectLabel = 'усі доступні проєкти';
        if (!empty($s['project_id'])) {
            $project = Project::find((int) $s['project_id']);
            $projectLabel = $project && Project::isVisibleTo($project, $uid, $isAdmin) ? $project['name'] : 'проєкт недоступний';
        }
        $filters = ['project_id' => $s['project_id'] ?: null, 'log_user_id' => $s['log_user_id'] ?: null, 'category' => $s['category'] ?: null];
        $rows = $projectLabel === 'проєкт недоступний' ? [] : self::merge(
            Task::timeLogsFilteredReport($from, $to, $filters, $uid, $isAdmin),
            TicketTimeLog::reportRows($from, $to, $filters, $uid, (string) ($s['owner_role'] ?? ''))
        );
        $lines = ["Період: {$label} ({$from} — {$to})", "Проєкт: {$projectLabel}", 'Користувач: ' . ($s['log_user_name'] ?: 'усі користувачі')];
        if (!empty($s['category'])) {
            $lines[] = 'Категорія: ' . $s['category'];
        }
        $lines[] = 'Сформовано: ' . $now->format('d.m.Y H:i');
        return ['rows' => $rows, 'filterLines' => $lines, 'label' => $label, 'from' => $from, 'to' => $to];
    }

    /** Об'єднує рядки часу по задачах і по тікетах, сортує за датою (рядкам задач додається kind = 'task'). */
    public static function merge(array $taskRows, array $ticketRows): array
    {
        foreach ($taskRows as &$r) {
            $r['kind'] = 'task';
        }
        unset($r);
        $all = array_merge($taskRows, $ticketRows);
        usort($all, static fn($a, $b) => strcmp((string) $a['log_date'], (string) $b['log_date']));
        return $all;
    }

    /** PDF звіту обліку часу за переданими рядками (використовує й ручна форма «Звіти»). */
    public static function pdf(array $rows, array $filterLines): PdfReport
    {
        $pdf = new PdfReport('Звіт з обліку часу', $filterLines);
        if (empty($rows)) {
            $pdf->line('Немає записів обліку часу за обраними фільтрами.');
            return $pdf;
        }
        $hoursByUser = [];
        foreach ($rows as $r) {
            $hoursByUser[$r['user_name']] = ($hoursByUser[$r['user_name']] ?? 0.0) + (float) $r['hours'];
        }
        arsort($hoursByUser);
        $chart = [];
        foreach ($hoursByUser as $name => $hours) {
            $chart[] = ['name' => $name, 'hours' => $hours];
        }
        $pdf->userChart($chart);
        $columns = [
            ['header' => 'Дата', 'width' => 22], ['header' => 'Задача', 'width' => 60], ['header' => 'Користувач', 'width' => 40],
            ['header' => 'Категорія', 'width' => 33], ['header' => 'Годин', 'width' => 25],
        ];
        $groups = [];
        foreach ($rows as $r) {
            $pid = (int) $r['project_id'];
            $isTicket = ($r['kind'] ?? 'task') === 'ticket';
            $groups[$pid] ??= ['project_name' => $r['project_name'], 'rows' => [], 'subtotal' => 0.0]
                + ($isTicket ? ['title' => $r['project_name'], 'subtotal_label' => 'Витрачено на тікети цієї черги:'] : []);
            $groups[$pid]['rows'][] = [$r['log_date'], mb_strimwidth($r['task_title'], 0, 30, '…'), mb_strimwidth($r['user_name'], 0, 20, '…'),
                mb_strimwidth($r['activity_category'] ?? '—', 0, 16, '…'), number_format((float) $r['hours'], 2)];
            $groups[$pid]['subtotal'] += (float) $r['hours'];
        }
        uasort($groups, fn($a, $b) => $b['subtotal'] <=> $a['subtotal']);
        $pdf->groupedTable($columns, array_values($groups));
        $taskHours = 0.0;
        $ticketHours = 0.0;
        $byTicket = [];
        foreach ($rows as $r) {
            if (($r['kind'] ?? 'task') === 'ticket') {
                $ticketHours += (float) $r['hours'];
                $byTicket[$r['ticket_id']] ??= ['title' => $r['task_title'], 'hours' => 0.0];
                $byTicket[$r['ticket_id']]['hours'] += (float) $r['hours'];
            } else {
                $taskHours += (float) $r['hours'];
            }
        }
        if ($byTicket) {
            uasort($byTicket, fn($a, $b) => $b['hours'] <=> $a['hours']);
            $pdf->table('Час по тікетах', [['header' => 'Тікет', 'width' => 150], ['header' => 'Годин', 'width' => 30]],
                array_map(fn($t) => [$t['title'], number_format($t['hours'], 2)], array_values($byTicket)));
        }
        $pdf->heading('Разом: ' . number_format($taskHours + $ticketHours, 2) . ' год.' . ($byTicket
            ? ' (задачі: ' . number_format($taskHours, 2) . '; тікети: ' . number_format($ticketHours, 2) . ')' : ''));
        return $pdf;
    }

    /**
     * Файл звіту обліку часу у вибраному форматі (pdf | xlsx | csv): спільне для ручної форми «Звіти» і розкладів.
     * @return array{name: string, mime: string, data: string}
     */
    public static function file(string $format, array $rows, array $filterLines, string $baseName): array
    {
        if ($format === 'pdf') {
            return ['name' => $baseName . '.pdf', 'mime' => 'application/pdf', 'data' => self::pdf($rows, $filterLines)->toString()];
        }
        $headers = ['Дата', 'Тип', 'Проєкт / черга', 'Задача / тікет', 'Користувач', 'Категорія', 'Годин'];
        $table = array_map(fn($r) => [$r['log_date'], ($r['kind'] ?? 'task') === 'ticket' ? 'Тікет' : 'Задача', $r['project_name'], $r['task_title'], $r['user_name'], $r['activity_category'] ?? '', round((float) $r['hours'], 2)], $rows);
        if ($format === 'csv') {
            return ['name' => $baseName . '.csv', 'mime' => 'text/csv; charset=utf-8', 'data' => TableExport::csv($headers, $table)];
        }
        return ['name' => $baseName . '.xlsx', 'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'data' => TableExport::xlsx($headers, $table, 'Облік часу')];
    }

    /** @return array{name: string, mime: string, data: string, rows: int, hours: float} */
    public static function build(array $s, \DateTimeImmutable $now): array
    {
        $d = self::data($s, $now);
        $att = self::file((string) $s['format'], $d['rows'], $d['filterLines'], 'report_' . $d['from'] . '_' . $d['to']);
        return $att + ['rows' => count($d['rows']), 'hours' => (float) array_sum(array_column($d['rows'], 'hours')), 'label' => $d['label']];
    }

    /**
     * Будує й надсилає звіт усім одержувачам розкладу; оновлює стан розкладу.
     * @return array{ok: bool, message: string}
     */
    public static function run(array $s, ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        if (!MailerService::isConfigured()) {
            return ['ok' => false, 'message' => 'Пошту не налаштовано (.env).'];
        }
        try {
            $att = self::build($s, $now);
        } catch (\Throwable $e) {
            ReportSchedule::markResult((int) $s['id'], false, 'Помилка побудови: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Помилка побудови звіту: ' . $e->getMessage()];
        }
        $subject = '[ITSM] ' . $s['name'] . ' — ' . $att['label'];
        $body = "Звіт з обліку часу за розкладом «{$s['name']}» ({$att['label']}).\n"
            . ($att['rows'] > 0 ? "Записів: {$att['rows']}, годин: " . number_format($att['hours'], 2) . ".\n" : "За цей період записів обліку часу немає.\n")
            . "Файл — у вкладенні.\n\nЛист сформовано автоматично. Керувати розкладами: " . rtrim(Setting::appUrl(), '/') . "/reports/schedules\n";
        $failed = [];
        foreach (self::recipients($s) as $to) {
            $r = MailerService::send($to, '', $subject, $body, null, [['name' => $att['name'], 'mime' => $att['mime'], 'data' => $att['data']]]);
            if (!$r['ok']) {
                $failed[] = $to . ': ' . $r['message'];
            }
        }
        $ok = !$failed;
        $status = $ok ? 'Надіслано ' . $now->format('d.m.Y H:i') . ' (' . count(self::recipients($s)) . ' одерж., записів: ' . $att['rows'] . ')' : 'Помилка: ' . implode('; ', $failed);
        ReportSchedule::markResult((int) $s['id'], $ok, $status);
        return ['ok' => $ok, 'message' => $status];
    }

    /** Для cron: надсилає всі розклади, час яких настав. @return array{due: int, sent: int, failed: int} */
    public static function runDue(?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        $out = ['due' => 0, 'sent' => 0, 'failed' => 0];
        if (!ReportSchedule::ready()) {
            return $out;
        }
        foreach (ReportSchedule::active() as $s) {
            if (!self::isDue($s, $now)) {
                continue;
            }
            $out['due']++;
            self::run($s, $now)['ok'] ? $out['sent']++ : $out['failed']++;
        }
        return $out;
    }
}
