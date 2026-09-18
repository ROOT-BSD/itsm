<?php

namespace App\Models;

use App\Core\Database;

class Audit
{
    public static function log(string $entityType, int $entityId, string $action, ?int $userId, ?array $changes = null): void
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO audit_log (entity_type, entity_id, action, user_id, changes)
             VALUES (:entity_type, :entity_id, :action, :user_id, :changes)'
        );
        $stmt->execute([
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'action' => $action,
            'user_id' => $userId,
            'changes' => $changes ? json_encode($changes, JSON_UNESCAPED_UNICODE) : null,
        ]);
    }
}
