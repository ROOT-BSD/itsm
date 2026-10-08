<?php

namespace App\Core;

/**
 * Організаційний підрозділ для ролі «Адміністратор Підрозділу» (unit_admin).
 *
 * Підрозділ користувача = його AD OU (users.ad_ou, формат ouPath: "OU=IT,OU=Kyiv" — від листа до кореня).
 * Адміністратор підрозділу охоплює СВІЙ OU і всі вкладені: для "OU=Kyiv" це "OU=Kyiv" та "OU=IT,OU=Kyiv", але не "OU=Lviv".
 * Хто не є адміністратором підрозділу або не має OU (локальний акаунт, AD поза OU), підрозділу не має —
 * тоді всі функції тут нічого не додають до його прав.
 *
 * ЄДИНЕ місце цього правила: Project::accessCondition(), Ticket і UnitController беруть умови звідси.
 */
final class Unit
{
    public const ROLE = 'unit_admin';

    /** @var array<int, ?string> */
    private static array $ouCache = [];

    /** OU адміністратора підрозділу (активного, з непорожнім OU) або null, якщо користувач таким не є. */
    public static function scopeOu(int $userId): ?string
    {
        if (!array_key_exists($userId, self::$ouCache)) {
            $stmt = Database::connection()->prepare(
                "SELECT " . self::ouExpr('u') . " AS ou, u.is_active, r.code FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = :id"
            );
            $stmt->execute(['id' => $userId]);
            $row = $stmt->fetch();
            $ou = null;
            if ($row && $row['code'] === self::ROLE && (int) $row['is_active'] === 1 && trim((string) $row['ou']) !== '') {
                $ou = trim((string) $row['ou']);
            }
            self::$ouCache[$userId] = $ou;
        }
        return self::$ouCache[$userId];
    }

    /** Скидає кеш (для тестів і після зміни ролі/OU у межах одного запиту). */
    public static function reset(): void
    {
        self::$ouCache = [];
    }

    /**
     * SQL-умова «користувач з псевдонімом $alias у таблиці users належить підрозділу $ou».
     * Збіг без урахування регістру; вкладені OU — за суфіксом ",<ou>" (LIKE свідомо не використовується:
     * у DN трапляються символи % і _, що були б шаблонами).
     *
     * @return array{0: string, 1: array<string, string>}
     */
    public static function matchSql(string $alias, string $prefix, string $ou): array
    {
        self::assertIdent($alias);
        self::assertIdent($prefix);
        $e = self::ouExpr($alias);
        $sql = "({$e} IS NOT NULL AND ("
            . "LOWER({$e}) = LOWER(:{$prefix}o1) "
            . "OR (LOWER(RIGHT({$e}, CHAR_LENGTH(:{$prefix}o2) + 1)) = LOWER(CONCAT(',', :{$prefix}o3)) "
            // Кома перед суфіксом не має бути екранованою ("\\,"): OU із назвою «A,OU=Kyiv» — це один OU, а не вкладений у Kyiv.
            . "AND SUBSTRING({$e}, CHAR_LENGTH({$e}) - CHAR_LENGTH(:{$prefix}o4) - 1, 1) <> '\\\\')))";
        return [$sql, ["{$prefix}o1" => $ou, "{$prefix}o2" => $ou, "{$prefix}o3" => $ou, "{$prefix}o4" => $ou]];
    }

    /**
     * SQL-вираз ефективного підрозділу користувача: для AD — OU із синхронізації (ad_ou), для локального —
     * підрозділ, який задав адміністратор (unit_ou, міграція 030). Без міграції 030 локальні підрозділу не мають.
     */
    public static function ouExpr(string $alias): string
    {
        self::assertIdent($alias);
        return self::localReady()
            ? "NULLIF(TRIM(IF({$alias}.auth_source = 'ad', {$alias}.ad_ou, {$alias}.unit_ou)), '')"
            : "NULLIF(TRIM(IF({$alias}.auth_source = 'ad', {$alias}.ad_ou, NULL)), '')";
    }

    private static ?bool $localReady = null;

    /** Чи є колонка users.unit_ou (міграція 030). */
    public static function localReady(): bool
    {
        return self::$localReady ??= Database::columnExists('users', 'unit_ou');
    }

    /**
     * Умова «користувач із id-виразом $idExpr (напр. "t.requester_user_id") належить підрозділу адміністратора $adminId».
     * null, якщо $adminId не адміністратор підрозділу — виклик тоді нічого не додає.
     *
     * @param string $idExpr лише внутрішній SQL-вираз (стовпець), не ввід користувача
     * @return array{0: string, 1: array<string, string>}|null
     */
    public static function userCondition(string $idExpr, string $prefix, int $adminId): ?array
    {
        $ou = self::scopeOu($adminId);
        if ($ou === null) {
            return null;
        }
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_.]*$/', $idExpr)) {
            throw new \InvalidArgumentException('Недопустимий SQL-вираз');
        }
        [$match, $params] = self::matchSql("{$prefix}_uu", $prefix, $ou);
        return ["EXISTS (SELECT 1 FROM users {$prefix}_uu WHERE {$prefix}_uu.id = {$idExpr} AND {$match})", $params];
    }

    /** Чи належить користувач $userId підрозділу адміністратора $adminId (сам адміністратор теж належить). */
    public static function containsUser(int $adminId, int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }
        if (self::scopeOu($adminId) === null) {
            return false;
        }
        if ($adminId === $userId) {
            return true;
        }
        [$match, $mParams] = self::matchSql('x', 'cu', (string) self::scopeOu($adminId));
        $stmt = Database::connection()->prepare("SELECT 1 FROM users x WHERE x.id = :uid AND {$match}");
        $stmt->execute($mParams + ['uid' => $userId]);
        return (bool) $stmt->fetchColumn();
    }

    /** Користувачі підрозділу (для сторінки «Мій підрозділ»), крім самого адміністратора. */
    public static function users(int $adminId): array
    {
        $ou = self::scopeOu($adminId);
        if ($ou === null) {
            return [];
        }
        [$match, $params] = self::matchSql('u', 'ul', $ou);
        $stmt = Database::connection()->prepare(
            "SELECT u.*, " . self::ouExpr('u') . " AS unit_path, r.name AS role_name, r.code AS role_code
               FROM users u JOIN roles r ON r.id = u.role_id
              WHERE {$match} AND u.id <> :me
              ORDER BY unit_path, u.full_name"
        );
        $stmt->execute($params + ['me' => $adminId]);
        return $stmt->fetchAll();
    }

    /** Підрозділи, що вже існують у системі (AD і локальні) — підказка для форми користувача. @return string[] шляхи OU */
    public static function knownOus(): array
    {
        $e = self::ouExpr('u');
        $rows = Database::connection()->query("SELECT DISTINCT {$e} AS ou FROM users u ORDER BY ou")->fetchAll(\PDO::FETCH_COLUMN);
        return array_values(array_filter($rows, fn($v) => $v !== null && $v !== ''));
    }

    private static function assertIdent(string $s): void
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $s)) {
            throw new \InvalidArgumentException('Недопустимий псевдонім чи префікс');
        }
    }
}
