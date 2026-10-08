<?php

namespace App\Controllers\Api;

use App\Core\ApiResponse;
use App\Models\Project;
use App\Models\Task;
use App\Services\ApiSerializer;

/** Проєкти (лише читання): ті, що бачить власник токена. */
class ProjectsApiController extends ApiController
{
    public function index(): void
    {
        $status = $_GET['status'] ?? null;
        $query = mb_strtolower(trim((string) ($_GET['q'] ?? '')));
        if ($status !== null && !in_array($status, ['active', 'archived', 'closed'], true)) {
            $this->validationFailed(['status' => 'Допустимі значення: active, archived, closed.']);
        }

        $projects = array_values(array_filter(
            Project::allVisibleTo($this->userId, $this->isAdmin),
            static fn(array $p): bool => ($status === null || $p['status'] === $status)
                && ($query === '' || str_contains(mb_strtolower($p['name']), $query))
        ));
        $this->paginate($projects, static fn(array $p) => ApiSerializer::project($p));
    }

    public function show(array $params): void
    {
        ApiResponse::ok(ApiSerializer::project($this->visibleProject((int) $params['id'])));
    }

    /** Задачі проєкту (усі статуси; ?status_id= і ?open=1 звужують вибірку). */
    public function tasks(array $params): void
    {
        $project = $this->visibleProject((int) $params['id']);
        $statusId = isset($_GET['status_id']) ? (int) $_GET['status_id'] : null;
        $openOnly = ($_GET['open'] ?? '') === '1';

        $tasks = array_values(array_filter(
            Task::forProject((int) $project['id']),
            static fn(array $t): bool => ($statusId === null || (int) $t['status_id'] === $statusId)
                && (!$openOnly || !(int) ($t['is_closed'] ?? 0))
        ));
        $this->paginate($tasks, static fn(array $t) => ApiSerializer::task($t + ['project_name' => $project['name']]));
    }

    /** Учасники проєкту (автор і відповідальний показані окремо в самому проєкті). */
    public function members(array $params): void
    {
        $project = $this->visibleProject((int) $params['id']);
        ApiResponse::ok(array_map(
            static fn(array $m) => ['id' => (int) $m['id'], 'full_name' => $m['full_name'], 'added_at' => ApiSerializer::iso($m['created_at'])],
            Project::members((int) $project['id'])
        ));
    }

    /** Проєкт, якщо він існує й видимий власнику токена; інакше 404 (щоб не підтверджувати існування прихованого). */
    private function visibleProject(int $id): array
    {
        $project = Project::find($id);
        if (!$project || !Project::isVisibleTo($project, $this->userId, $this->isAdmin)) {
            $this->notFound('Проєкт');
        }
        return $project;
    }
}
