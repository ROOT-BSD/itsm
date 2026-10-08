<?php

namespace App\Controllers\Api;

use App\Core\ApiResponse;
use App\Models\Task;
use App\Models\Ticket;
use App\Models\User;

/** Довідники, потрібні для створення записів: черги, статуси й типи задач, користувачі. */
class LookupsApiController extends ApiController
{
    public function queues(): void
    {
        ApiResponse::ok(array_map(
            static fn(array $q) => ['id' => (int) $q['id'], 'name' => $q['name'], 'description' => $q['description'] ?? null],
            Ticket::queues()
        ));
    }

    public function taskStatuses(): void
    {
        ApiResponse::ok(array_map(
            static fn(array $s) => ['id' => (int) $s['id'], 'code' => $s['code'], 'name' => $s['name'], 'is_closed' => (bool) $s['is_closed']],
            Task::statuses()
        ));
    }

    public function taskTypes(): void
    {
        ApiResponse::ok(array_map(
            static fn(array $t) => ['id' => (int) $t['id'], 'code' => $t['code'], 'name' => $t['name']],
            Task::types()
        ));
    }

    /** Активні користувачі. Email бачать лише адміністратор і керівник ІТ — як і в адмінці; решті достатньо імені для вибору виконавця. */
    public function users(): void
    {
        $showEmail = in_array($this->role, ['admin', 'it_manager'], true);
        $users = array_map(static fn(array $u) => array_filter([
            'id' => (int) $u['id'],
            'full_name' => $u['full_name'],
            'email' => $showEmail ? $u['email'] : null,
        ], static fn($v) => $v !== null), User::allActive());
        $this->paginate($users, static fn($u) => $u);
    }
}
