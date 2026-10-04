<?php

namespace App\Models;

use App\Core\Database;

/**
 * Відповідність групи AD (значення memberOf) локальній ролі — для
 * bin/sync-ad-users.php. Порівняння групи регістронезалежне: DN з різних
 * AD-контролерів іноді відрізняються регістром літер компонентів.
 */
class AdGroupMapping
{
    public static function all(): array
    {
        return Database::connection()->query(
            'SELECT m.*, r.name AS role_name, r.code AS role_code
             FROM ad_group_role_mapping m
             JOIN roles r ON r.id = m.role_id
             ORDER BY m.rank ASC, m.id ASC'
        )->fetchAll();
    }

    public static function create(string $adGroup, int $roleId, int $rank): int
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO ad_group_role_mapping (ad_group, role_id, rank) VALUES (:ad_group, :role_id, :rank)'
        );
        $stmt->execute(['ad_group' => $adGroup, 'role_id' => $roleId, 'rank' => $rank]);
        return (int) Database::connection()->lastInsertId();
    }

    public static function delete(int $id): void
    {
        Database::connection()->prepare('DELETE FROM ad_group_role_mapping WHERE id = :id')->execute(['id' => $id]);
    }

    /**
     * Перша відповідність за порядком rank (менше число — вищий пріоритет) серед груп,
     * у яких перебуває користувач. null, якщо жодна з його груп не має відповідності.
     *
     * @param string[] $userGroups DN груп користувача (значення атрибута memberOf)
     */
    public static function roleIdForGroups(array $userGroups): ?int
    {
        if (empty($userGroups)) {
            return null;
        }
        $normalizedUserGroups = array_map('mb_strtolower', $userGroups);

        foreach (self::all() as $mapping) {
            if (in_array(mb_strtolower($mapping['ad_group']), $normalizedUserGroups, true)) {
                return (int) $mapping['role_id'];
            }
        }
        return null;
    }
}
