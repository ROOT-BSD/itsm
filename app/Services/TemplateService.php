<?php

namespace App\Services;

use App\Models\Audit;
use App\Models\Task;
use App\Models\TaskChecklist;
use App\Models\Template;

/** Застосування шаблонів (створення задач, чек-листів, підзадач) і збирання шаблону з наявних задач. */
class TemplateService
{
    /** Скільки задач (разом з підзадачами) може створити одне застосування — запобіжник від випадкових гігантських шаблонів. */
    private const MAX_CREATED = 300;

    /** Рядки тексту → список непорожніх рядків без маркерів списку, кожен до $max символів. @return string[] */
    public static function lines(?string $text, int $max = 255, int $limit = 100): array
    {
        $out = [];
        foreach (preg_split('/\R/u', (string) $text) ?: [] as $line) {
            $line = trim((string) preg_replace('/^\s*(?:[-*•]|\[[ xX]?\]|\d+[.)])\s+/u', '', $line));
            if ($line !== '') {
                $out[] = mb_substr($line, 0, $max);
            }
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    /**
     * Створює в проєкті задачі шаблону. Дати початку/терміну = $baseDate + зміщення в днях (якщо зміщення задано).
     * Виконавець пункту: якщо в пункті задано роль, береться людина з $roleUsers[роль]; без ролі чи відповідності задачу лишено непризначеною (підзадачі успадковують виконавця).
     * @param array<string,int> $roleUsers роль → id користувача
     *
     * @return array{tasks: int, subtasks: int, first_task_id: ?int}
     */
    public static function apply(int $templateId, int $projectId, ?string $baseDate, int $userId, ?string $titleOverride = null, array $roleUsers = []): array
    {
        $items = Template::items($templateId);
        $statusId = self::newStatusId();
        $defaultType = (int) (Task::types()[0]['id'] ?? 0);
        $tasks = 0;
        $subs = 0;
        $first = null;
        $pdo = \App\Core\Database::connection();
        $pdo->beginTransaction();
        try {
            foreach ($items as $it) {
                $subLines = self::lines($it['subtasks']);
                if ($tasks + $subs + 1 + count($subLines) > self::MAX_CREATED) {
                    throw new \RuntimeException('Шаблон створює забагато задач (понад ' . self::MAX_CREATED . ')');
                }
                $type = (int) ($it['type_id'] ?: $defaultType);
                $assignee = !empty($it['assignee_role']) ? ($roleUsers[$it['assignee_role']] ?? null) : null;
                $taskId = Task::create([
                    'project_id' => $projectId, 'type_id' => $type, 'status_id' => $statusId,
                    'title' => ($titleOverride !== null && $titleOverride !== '' && $tasks === 0) ? $titleOverride : $it['title'], 'description' => $it['body'], 'priority' => $it['priority'],
                    'author_id' => $userId, 'assignee_id' => $assignee,
                    'start_date' => self::shift($baseDate, $it['start_offset']),
                    'due_date' => self::shift($baseDate, $it['due_offset']),
                ]);
                $first ??= $taskId;
                $tasks++;
                foreach (self::lines($it['checklist']) as $line) {
                    TaskChecklist::add($taskId, $line, $userId);
                }
                foreach ($subLines as $line) {
                    Task::create([
                        'project_id' => $projectId, 'parent_task_id' => $taskId, 'type_id' => $type, 'status_id' => $statusId,
                        'title' => $line, 'priority' => $it['priority'], 'author_id' => $userId, 'assignee_id' => $assignee,
                        'due_date' => self::shift($baseDate, $it['due_offset']),
                    ]);
                    $subs++;
                }
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        $tpl = Template::find($templateId);
        Audit::log('project', $projectId, 'template_applied', $userId, ['name' => $tpl['name'] ?? '', 'tasks' => $tasks, 'subtasks' => $subs]);
        return ['tasks' => $tasks, 'subtasks' => $subs, 'first_task_id' => $first];
    }

    /** Дата + N днів у форматі Y-m-d; null, якщо дати або зміщення немає. */
    private static function shift(?string $base, mixed $offset): ?string
    {
        if ($base === null || $offset === null || $offset === '') {
            return null;
        }
        $d = \DateTimeImmutable::createFromFormat('Y-m-d', $base);
        return $d ? $d->modify(((int) $offset >= 0 ? '+' : '') . (int) $offset . ' days')->format('Y-m-d') : null;
    }

    private static function newStatusId(): int
    {
        $id = \App\Core\Database::connection()->query("SELECT id FROM task_statuses WHERE code = 'new'")->fetchColumn();
        if (!$id) {
            throw new \RuntimeException('У системі немає статусу «new»');
        }
        return (int) $id;
    }

    /** Пункт шаблону з наявної задачі (назва, опис, тип, пріоритет, чек-лист, підзадачі). @return array<string, mixed> */
    public static function itemFromTask(array $task, ?string $baseDate = null): array
    {
        $checklist = array_map(fn($i) => $i['body'], TaskChecklist::forTask((int) $task['id']));
        $subtasks = array_map(fn($s) => $s['title'], TaskChecklist::subtasks((int) $task['id']));
        $offset = fn(?string $d) => ($baseDate && $d) ? (int) round((strtotime($d) - strtotime($baseDate)) / 86400) : null;
        return [
            'title' => $task['title'], 'body' => $task['description'] ?: null, 'type_id' => (int) $task['type_id'],
            'priority' => $task['priority'], 'assignee_role' => null,
            'start_offset' => $offset($task['start_date'] ?? null), 'due_offset' => $offset($task['due_date'] ?? null),
            'checklist' => $checklist ? implode("\n", $checklist) : null,
            'subtasks' => $subtasks ? implode("\n", $subtasks) : null,
        ];
    }
}
