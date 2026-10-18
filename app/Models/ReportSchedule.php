<?php

namespace App\Models;

use App\Core\Database;

/** Розклади звітів обліку часу (міграція 042). Див. App\Services\ReportScheduleService. */
class ReportSchedule
{
    public const WEEKDAYS = [1 => 'понеділок', 2 => 'вівторок', 3 => 'середа', 4 => 'четвер', 5 => 'пʼятниця', 6 => 'субота', 7 => 'неділя'];
    public const FORMATS = ['pdf' => 'PDF', 'xlsx' => 'Excel (XLSX)', 'csv' => 'CSV'];

    public static function ready(): bool
    {
        static $ready = null;
        if ($ready === null) {
            $ready = (int) Database::connection()->query(
                "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'report_schedules'"
            )->fetchColumn() > 0;
        }
        return $ready;
    }

    private const SELECT = "SELECT s.*, u.full_name AS owner_name, u.email AS owner_email, u.is_active AS owner_active, r.code AS owner_role,
                                   p.name AS project_name, lu.full_name AS log_user_name
                            FROM report_schedules s
                            JOIN users u ON u.id = s.user_id
                            JOIN roles r ON r.id = u.role_id
                            LEFT JOIN projects p ON p.id = s.project_id
                            LEFT JOIN users lu ON lu.id = s.log_user_id";

    /** @return array<int, array<string, mixed>> усі (для адміна) або лише свої */
    public static function listFor(int $userId, bool $all): array
    {
        $sql = self::SELECT . ($all ? '' : ' WHERE s.user_id = :uid') . ' ORDER BY s.id DESC';
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($all ? [] : ['uid' => $userId]);
        return $stmt->fetchAll();
    }

    /** @return array<int, array<string, mixed>> */
    public static function active(): array
    {
        return Database::connection()->query(self::SELECT . ' WHERE s.is_active = 1 ORDER BY s.id')->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare(self::SELECT . ' WHERE s.id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public static function create(array $d): int
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO report_schedules (user_id, name, frequency, weekday, month_day, send_hour, format, project_id, log_user_id, category, extra_recipients, last_run_at)
             VALUES (:user_id, :name, :frequency, :weekday, :month_day, :send_hour, :format, :project_id, :log_user_id, :category, :extra, NOW())'
        );
        $stmt->execute([
            'user_id' => $d['user_id'], 'name' => $d['name'], 'frequency' => $d['frequency'], 'weekday' => $d['weekday'],
            'month_day' => $d['month_day'], 'send_hour' => $d['send_hour'], 'format' => $d['format'],
            'project_id' => $d['project_id'], 'log_user_id' => $d['log_user_id'], 'category' => $d['category'], 'extra' => $d['extra_recipients'],
        ]);
        return (int) Database::connection()->lastInsertId();
    }

    public static function setActive(int $id, bool $on): void
    {
        $stmt = Database::connection()->prepare('UPDATE report_schedules SET is_active = :a WHERE id = :id');
        $stmt->execute(['a' => $on ? 1 : 0, 'id' => $id]);
    }

    public static function delete(int $id): void
    {
        Database::connection()->prepare('DELETE FROM report_schedules WHERE id = :id')->execute(['id' => $id]);
    }

    /** Результат спроби: успіх зсуває last_run_at, помилка — ні (наступний запуск cron спробує знову). */
    public static function markResult(int $id, bool $ok, string $status): void
    {
        $sql = $ok ? 'UPDATE report_schedules SET last_run_at = NOW(), last_status = :s WHERE id = :id'
                   : 'UPDATE report_schedules SET last_status = :s WHERE id = :id';
        Database::connection()->prepare($sql)->execute(['s' => mb_substr($status, 0, 250), 'id' => $id]);
    }
}
