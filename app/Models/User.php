<?php

namespace App\Models;

use App\Core\Database;

class User
{
    public static function findByEmail(string $email): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT u.*, r.code AS role_code, r.name AS role_name
             FROM users u JOIN roles r ON r.id = u.role_id
             WHERE u.email = :email LIMIT 1'
        );
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();
        return $user ?: null;
    }

    public static function findById(int $id): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT u.*, r.code AS role_code, r.name AS role_name
             FROM users u JOIN roles r ON r.id = u.role_id
             WHERE u.id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $user = $stmt->fetch();
        return $user ?: null;
    }

    public static function all(): array
    {
        return Database::connection()
            ->query('SELECT u.*, r.name AS role_name FROM users u JOIN roles r ON r.id = u.role_id ORDER BY u.full_name')
            ->fetchAll();
    }

    /**
     * Активні користувачі для випадаючих списків вибору виконавця/відповідального/оператора.
     * Фільтрація на рівні SQL (а не array_filter у PHP після вибірки всіх) — раніше
     * цей самий фільтр був продубльований окремим приватним методом у трьох різних
     * контролерах (Project/Task/TicketController).
     */
    public static function allActive(): array
    {
        return Database::connection()
            ->query('SELECT u.*, r.name AS role_name FROM users u JOIN roles r ON r.id = u.role_id WHERE u.is_active = 1 ORDER BY u.full_name')
            ->fetchAll();
    }

    public static function updatePassword(int $userId, string $newPassword): bool
    {
        $user = self::findById($userId);
        if (!$user || $user['auth_source'] !== 'local') {
            // Пароль можна змінити лише для локальних облікових записів.
            // Для AD-користувачів пароль керується самим Active Directory (Епік 13).
            return false;
        }

        $stmt = Database::connection()->prepare(
            'UPDATE users SET password_hash = :hash WHERE id = :id'
        );
        $stmt->execute([
            'hash' => password_hash($newPassword, PASSWORD_DEFAULT),
            'id' => $userId,
        ]);

        return true;
    }

    public static function setActive(int $userId, bool $isActive): void
    {
        $stmt = Database::connection()->prepare('UPDATE users SET is_active = :active WHERE id = :id');
        $stmt->execute(['active' => $isActive ? 1 : 0, 'id' => $userId]);
    }

    /** Реєструє невдалу спробу входу й повертає новий лічильник — блокування прив'язане саме до цього користувача. */
    public static function registerFailedLogin(int $userId): int
    {
        $stmt = Database::connection()->prepare(
            'UPDATE users SET failed_login_attempts = failed_login_attempts + 1 WHERE id = :id'
        );
        $stmt->execute(['id' => $userId]);

        $stmt = Database::connection()->prepare('SELECT failed_login_attempts FROM users WHERE id = :id');
        $stmt->execute(['id' => $userId]);
        return (int) $stmt->fetchColumn();
    }

    public static function lockUntil(int $userId, string $lockedUntil): void
    {
        $stmt = Database::connection()->prepare('UPDATE users SET locked_until = :locked_until WHERE id = :id');
        $stmt->execute(['locked_until' => $lockedUntil, 'id' => $userId]);
    }

    /** Скидає лічильник невдалих спроб і знімає блокування — викликається і після вдалого входу, і вручну адміністратором. */
    public static function resetFailedLogins(int $userId): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE users SET failed_login_attempts = 0, locked_until = NULL WHERE id = :id'
        );
        $stmt->execute(['id' => $userId]);
    }

    public static function roles(): array
    {
        return Database::connection()->query('SELECT * FROM roles ORDER BY id')->fetchAll();
    }

    public static function create(string $fullName, string $email, string $password, int $roleId): int
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO users (full_name, email, password_hash, auth_source, role_id, is_active)
             VALUES (:full_name, :email, :password_hash, "local", :role_id, 1)'
        );
        $stmt->execute([
            'full_name' => $fullName,
            'email' => $email,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'role_id' => $roleId,
        ]);
        return (int) Database::connection()->lastInsertId();
    }

    /** Створення користувача з AD-синхронізації (bin/sync-ad-users.php) — без пароля, вхід лише через bind до AD. */
    public static function createFromAd(string $fullName, string $email, string $adUsername, int $roleId, string $adOu = '', ?string $adGuid = null): int
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO users (full_name, email, password_hash, auth_source, ad_username, ad_ou, role_id, is_active)
             VALUES (:full_name, :email, NULL, "ad", :ad_username, :ad_ou, :role_id, 1)'
        );
        $stmt->execute([
            'full_name' => $fullName,
            'email' => $email,
            'ad_username' => $adUsername,
            'ad_ou' => $adOu !== '' ? $adOu : null,
            'role_id' => $roleId,
        ]);
        $newId = (int) Database::connection()->lastInsertId();
        if ($adGuid !== null) {
            self::setAdIdentity($newId, $adGuid);
        }
        return $newId;
    }

    /** Чи є в БД колонка ad_guid (міграція 028). Без неї синхронізація зіставляє користувачів лише за email. */
    public static function guidReady(): bool
    {
        return Database::columnExists('users', 'ad_guid');
    }

    /**
     * AD-користувач за логіном AD (регістр не важливий) — для SSO. Якщо логін збігається з кількома
     * обліковими записами (неунікальне значення), повертає null: краще відмовити, ніж увійти не під тим.
     */
    public static function findAdByUsername(string $username): ?array
    {
        $stmt = Database::connection()->prepare(
            "SELECT u.*, r.code AS role_code, r.name AS role_name
               FROM users u JOIN roles r ON r.id = u.role_id
              WHERE u.auth_source = 'ad' AND LOWER(u.ad_username) = LOWER(:u) LIMIT 2"
        );
        $stmt->execute(['u' => $username]);
        $rows = $stmt->fetchAll();
        return count($rows) === 1 ? $rows[0] : null;
    }

    /** Знаходить AD-користувача за objectGUID (канонічний рядок). */
    public static function findByAdGuid(string $guid): ?array
    {
        if (!self::guidReady()) {
            return null;
        }
        $stmt = Database::connection()->prepare("SELECT * FROM users WHERE ad_guid = :g AND auth_source = 'ad' LIMIT 1");
        $stmt->execute(['g' => $guid]);
        return $stmt->fetch() ?: null;
    }

    /** Запам'ятовує objectGUID AD-користувача (і, якщо передано, його новий email — AD змінив пошту). */
    public static function setAdIdentity(int $userId, ?string $guid, ?string $email = null): void
    {
        if (!self::guidReady()) {
            return;
        }
        $sets = [];
        $params = ['id' => $userId];
        if ($guid !== null) {
            $sets[] = 'ad_guid = :g';
            $params['g'] = $guid;
        }
        if ($email !== null) {
            $sets[] = 'email = :e';
            $params['e'] = $email;
        }
        if (!$sets) {
            return;
        }
        $stmt = Database::connection()->prepare("UPDATE users SET " . implode(', ', $sets) . " WHERE id = :id AND auth_source = 'ad'");
        $stmt->execute($params);
    }

    /** Чи є в БД колонки ручного перевизначення (міграція 027). Без них синхронізація працює як раніше. */
    public static function overridesReady(): bool
    {
        return Database::columnExists('users', 'ad_role_locked') && Database::columnExists('users', 'ad_blocked');
    }

    /**
     * Оновлення вже синхронізованого AD-користувача — ім'я, роль (за групами), username і OU могли змінитись.
     * Ручні перевизначення (міграція 027) враховано: закріплену роль не чіпаємо, а вручну деактивованого
     * не вмикаємо знову. Інакше активується повторно, якщо раніше був деактивований через зникнення з AD.
     */
    public static function updateFromAd(int $userId, string $fullName, string $adUsername, int $roleId, string $adOu = ''): void
    {
        $sql = 'UPDATE users SET full_name = :full_name, ad_username = :ad_username, ad_ou = :ad_ou, role_id = :role_id, is_active = 1 WHERE id = :id';
        if (self::overridesReady()) {
            $sql = 'UPDATE users SET full_name = :full_name, ad_username = :ad_username, ad_ou = :ad_ou,
                           role_id = IF(ad_role_locked = 1, role_id, :role_id),
                           is_active = IF(ad_blocked = 1, is_active, 1)
                     WHERE id = :id';
        }
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute([
            'full_name' => $fullName,
            'ad_username' => $adUsername,
            'ad_ou' => $adOu !== '' ? $adOu : null,
            'role_id' => $roleId,
            'id' => $userId,
        ]);
    }

    /** Закріплює (або знімає закріплення) ролі AD-користувача. Нічого не робить без міграції 027. */
    public static function setAdRoleLocked(int $userId, bool $locked): void
    {
        if (!self::overridesReady()) {
            return;
        }
        $stmt = Database::connection()->prepare("UPDATE users SET ad_role_locked = :v WHERE id = :id AND auth_source = 'ad'");
        $stmt->execute(['v' => $locked ? 1 : 0, 'id' => $userId]);
    }

    /** Ставить/знімає ручне блокування AD-користувача від повторної активації синхронізацією. */
    public static function setAdBlocked(int $userId, bool $blocked): void
    {
        if (!self::overridesReady()) {
            return;
        }
        $stmt = Database::connection()->prepare("UPDATE users SET ad_blocked = :v WHERE id = :id AND auth_source = 'ad'");
        $stmt->execute(['v' => $blocked ? 1 : 0, 'id' => $userId]);
    }

    /** Усі користувачі з auth_source='ad' — для визначення, кого синхронізація більше не бачить у каталозі (деактивація). */
    public static function allAdSourced(): array
    {
        return Database::connection()->query("SELECT * FROM users WHERE auth_source = 'ad'")->fetchAll();
    }

    public static function update(int $userId, string $fullName, string $email, int $roleId): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE users SET full_name = :full_name, email = :email, role_id = :role_id WHERE id = :id'
        );
        $stmt->execute([
            'full_name' => $fullName,
            'email' => $email,
            'role_id' => $roleId,
            'id' => $userId,
        ]);
    }

    /** Задає підрозділ ЛОКАЛЬНОГО користувача (шлях у форматі ouPath; '' = без підрозділу). Нічого не робить без міграції 030. */
    public static function setUnitOu(int $userId, string $ouPath): void
    {
        if (!\App\Core\Unit::localReady()) {
            return;
        }
        $stmt = Database::connection()->prepare("UPDATE users SET unit_ou = :ou WHERE id = :id AND auth_source = 'local'");
        $stmt->execute(['ou' => $ouPath !== '' ? $ouPath : null, 'id' => $userId]);
        \App\Core\Unit::reset();
    }

    /** Змінює лише роль (для адміністратора підрозділу — ім'я та email AD-користувача він не редагує). */
    public static function setRole(int $userId, int $roleId): void
    {
        $stmt = Database::connection()->prepare('UPDATE users SET role_id = :role WHERE id = :id');
        $stmt->execute(['role' => $roleId, 'id' => $userId]);
    }

    public static function emailExists(string $email, ?int $excludeUserId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM users WHERE email = :email';
        $params = ['email' => $email];
        if ($excludeUserId !== null) {
            $sql .= ' AND id != :exclude_id';
            $params['exclude_id'] = $excludeUserId;
        }
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Повне видалення користувача. На відміну від проєктів, тут НЕМАЄ каскадного
     * видалення пов'язаних даних — таблиці tasks/comments/time_logs/audit_log
     * посилаються на users без ON DELETE CASCADE (щоб не втрачати історію, хто
     * саме створив задачу чи залишив коментар). Тому якщо користувач має пов'язані
     * записи, MySQL поверне помилку цілісності (FK constraint) — це очікувано:
     * ловимо її і повертаємо контролеру ознаку, що видалення неможливе, замість
     * того щоб мовчки ламати історичні дані.
     */
    public static function delete(int $userId): bool
    {
        try {
            $stmt = Database::connection()->prepare('DELETE FROM users WHERE id = :id');
            $stmt->execute(['id' => $userId]);
            return true;
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                // Порушення зовнішнього ключа — у користувача є пов'язані дані
                // (створені проєкти, задачі, коментарі, облік часу тощо).
                return false;
            }
            throw $e;
        }
    }
}
