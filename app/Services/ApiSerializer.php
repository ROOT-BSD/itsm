<?php

namespace App\Services;

use App\Models\Setting;

/**
 * Перетворення записів БД на JSON-представлення — ЄДИНЕ для REST API і вебхуків: підписник вебхука бачить ті самі
 * поля, що й клієнт API. Внутрішні службові значення (хеші, access_token тікета для порталу, паролі) сюди не потрапляють
 * свідомо: додаючи поле, переконайтесь, що його можна показати будь-кому, хто бачить сам запис.
 */
final class ApiSerializer
{
    /** Дата з БД → ISO 8601 із зсувом часового поясу сервера (2026-10-07T14:30:00+03:00). */
    public static function iso(?string $value): ?string
    {
        if ($value === null || $value === '' || str_starts_with($value, '0000')) {
            return null;
        }
        $ts = strtotime($value);
        return $ts === false ? null : date('c', $ts);
    }

    private static function ref(?int $id, ?string $name, string $key = 'name'): ?array
    {
        return $id ? ['id' => (int) $id, $key => $name] : null;
    }

    private static function url(string $path): string
    {
        return Setting::appUrl() . $path;
    }

    public static function user(array $u): array
    {
        return [
            'id' => (int) $u['id'],
            'full_name' => $u['full_name'],
            'email' => $u['email'] ?? null,
            'role' => $u['role_code'] ?? ($u['role'] ?? null),
        ];
    }

    public static function project(array $p): array
    {
        return [
            'id' => (int) $p['id'],
            'name' => $p['name'],
            'description' => $p['description'] ?? null,
            'status' => $p['status'],
            'visibility' => $p['visibility'],
            'parent_id' => !empty($p['parent_id']) ? (int) $p['parent_id'] : null,
            'created_by' => self::ref((int) $p['created_by'], $p['created_by_name'] ?? null, 'full_name'),
            'responsible' => self::ref(!empty($p['responsible_user_id']) ? (int) $p['responsible_user_id'] : null, $p['responsible_name'] ?? null, 'full_name'),
            'created_at' => self::iso($p['created_at'] ?? null),
            'updated_at' => self::iso($p['updated_at'] ?? null),
            'url' => self::url('/projects/' . (int) $p['id']),
        ];
    }

    public static function task(array $t): array
    {
        return [
            'id' => (int) $t['id'],
            'project' => ['id' => (int) $t['project_id'], 'name' => $t['project_name'] ?? null],
            'title' => $t['title'],
            'description' => $t['description'] ?? null,
            'type' => ['id' => (int) $t['type_id'], 'name' => $t['type_name'] ?? null],
            'status' => ['id' => (int) $t['status_id'], 'name' => $t['status_name'] ?? null, 'is_closed' => (bool) ($t['is_closed'] ?? false)],
            'priority' => $t['priority'],
            'author' => self::ref((int) $t['author_id'], $t['author_name'] ?? null, 'full_name'),
            'assignee' => self::ref(!empty($t['assignee_id']) ? (int) $t['assignee_id'] : null, $t['assignee_name'] ?? null, 'full_name'),
            'start_date' => !empty($t['start_date']) ? substr((string) $t['start_date'], 0, 10) : null,
            'due_date' => !empty($t['due_date']) ? substr((string) $t['due_date'], 0, 10) : null,
            'milestone' => !empty($t['milestone_id']) ? ['id' => (int) $t['milestone_id'], 'title' => $t['milestone_title'] ?? null] : null,
            'created_at' => self::iso($t['created_at'] ?? null),
            'updated_at' => self::iso($t['updated_at'] ?? null),
            'url' => self::url('/tasks/' . (int) $t['id']),
        ];
    }

    public static function ticket(array $t): array
    {
        return [
            'id' => (int) $t['id'],
            'subject' => $t['subject'],
            'description' => $t['description'] ?? null,
            'status' => $t['status'],
            'queue' => ['id' => (int) $t['queue_id'], 'name' => $t['queue_name'] ?? null],
            'requester' => [
                'user_id' => !empty($t['requester_user_id']) ? (int) $t['requester_user_id'] : null,
                'name' => $t['requester_name'],
                'email' => $t['requester_email'],
            ],
            'operator' => self::ref(!empty($t['assigned_operator_id']) ? (int) $t['assigned_operator_id'] : null, $t['operator_name'] ?? null, 'full_name'),
            'project' => !empty($t['project_id']) ? ['id' => (int) $t['project_id'], 'name' => $t['project_name'] ?? null] : null,
            'csat_score' => isset($t['csat_score']) ? (int) $t['csat_score'] : null,
            'first_response_at' => self::iso($t['first_response_at'] ?? null),
            'resolved_at' => self::iso($t['resolved_at'] ?? null),
            'created_at' => self::iso($t['created_at'] ?? null),
            'updated_at' => self::iso($t['updated_at'] ?? null),
            'url' => self::url('/tickets/' . (int) $t['id']),
        ];
    }

    public static function ticketComment(array $c): array
    {
        return [
            'id' => (int) $c['id'],
            'author_type' => $c['author_type'],
            'author' => self::ref(!empty($c['author_id']) ? (int) $c['author_id'] : null, $c['author_name'] ?? null, 'full_name'),
            'body' => $c['body'],
            'created_at' => self::iso($c['created_at'] ?? null),
        ];
    }

    public static function taskComment(array $c): array
    {
        return [
            'id' => (int) $c['id'],
            'author' => self::ref(!empty($c['author_id']) ? (int) $c['author_id'] : null, $c['author_name'] ?? null, 'full_name'),
            'body' => $c['body'],
            'created_at' => self::iso($c['created_at'] ?? null),
        ];
    }
}
