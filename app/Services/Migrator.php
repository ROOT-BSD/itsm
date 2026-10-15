<?php

namespace App\Services;

use App\Core\Database;

/**
 * Застосування міграцій БД і облік застосованих у таблиці schema_migrations.
 *
 * Два набори файлів:
 *   database/migrations/legacy/001…040_*.sql — «стиснена» історія до версії 0.3.0. Не змінюються. Кожен файл сам перевіряє, чи
 *       потрібна зміна, але не всі безпечні для повтору (напр. 018 замінює стару заготовку вкладень), тому для старої БД без
 *       запису про міграцію спершу перевіряється ознака «вже застосовано» (GATES) і застосовуються лише справді відсутні.
 *   database/migrations/NNN_*.sql (NNN ≥ 041) — нові міграції; застосовуються в порядку номерів, кожна один раз, з контрольною сумою.
 *
 * Нова інсталяція (schema.sql) уже містить усі таблиці й записи про legacy-міграції в schema_migrations.
 * Викликається з bin/migrate.php (його запускає update.sh) і з діагностики «Стан системи».
 */
class Migrator
{
    public const LEGACY_LAST = 40;

    private \PDO $pdo;
    private string $dir;
    /** @var callable(string): void */
    private $out;

    /** @param callable(string): void|null $out куди друкувати хід роботи */
    public function __construct(?\PDO $pdo = null, ?string $dir = null, ?callable $out = null)
    {
        $this->pdo = $pdo ?? Database::connection();
        $this->dir = $dir ?? dirname(__DIR__, 2) . '/database/migrations';
        $this->out = $out ?? static function (string $line): void {};
    }

    public function ensureTable(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                version VARCHAR(20) NOT NULL PRIMARY KEY,
                name VARCHAR(150) NOT NULL,
                checksum CHAR(40) NULL,
                applied_via VARCHAR(10) NOT NULL DEFAULT \'run\',
                applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB'
        );
    }

    /** @return array<string, array{version: string, name: string, file: string, legacy: bool}> усі файли міграцій за номером */
    public function files(): array
    {
        $found = [];
        foreach (['legacy' => true, '' => false] as $sub => $legacy) {
            foreach (glob($this->dir . ($sub !== '' ? '/' . $sub : '') . '/[0-9][0-9][0-9]_*.sql') ?: [] as $file) {
                $base = basename($file, '.sql');
                $version = substr($base, 0, 3);
                $found[$version] = ['version' => $version, 'name' => substr($base, 4), 'file' => $file, 'legacy' => $legacy];
            }
        }
        ksort($found);
        return $found;
    }

    /** @return array<string, array<string, mixed>> застосовані: версія => рядок schema_migrations */
    public function applied(): array
    {
        if (!$this->tableExists('schema_migrations')) {
            return []; // читання (діагностика) не створює таблицю; її створює run()
        }
        $rows = [];
        foreach ($this->pdo->query('SELECT * FROM schema_migrations')->fetchAll() as $r) {
            $rows[$r['version']] = $r;
        }
        return $rows;
    }

    /** @return array<int, array{version: string, name: string, file: string, legacy: bool}> міграції, яких ще немає в обліку */
    public function pending(): array
    {
        $applied = $this->applied();
        return array_values(array_filter($this->files(), static fn(array $f) => !isset($applied[$f['version']])));
    }

    /**
     * Застосовує все, чого бракує. $dryRun — лише показати. $baseline — позначити все як застосоване, нічого не виконуючи.
     *
     * @return int кількість виконаних міграцій
     * @throws \RuntimeException на першій помилці (решта не застосовується)
     */
    public function run(bool $dryRun = false, bool $baseline = false): int
    {
        if (!$this->tableExists('users')) {
            throw new \RuntimeException('У базі немає схеми застосунку (таблиці users). Для нової бази використайте install.sh.');
        }
        if (!$dryRun) {
            $this->ensureTable();
        }
        $executed = 0;
        foreach ($this->pending() as $m) {
            $v = $m['version'];
            if ($baseline) {
                $this->record($m, 'baseline', $dryRun);
                ($this->out)("  [INFO] {$v} {$m['name']}: позначено як застосовану");
                continue;
            }
            if ($m['legacy']) {
                $gate = $this->legacyApplied($v);
                if ($gate === true) {
                    $this->record($m, 'baseline', $dryRun);
                    ($this->out)("  [ OK ] {$v} {$m['name']}: вже застосована");
                    continue;
                }
                if ($gate === null) { // 002 — виправлення кирилиці: не потрібне, коли пошкоджень немає
                    $this->record($m, 'baseline', $dryRun);
                    ($this->out)("  [ OK ] {$v} {$m['name']}: пошкоджень кирилиці немає");
                    continue;
                }
            }
            ($this->out)("  [INFO] {$v} {$m['name']}: " . ($dryRun ? 'буде застосована' : 'застосовую…'));
            if (!$dryRun) {
                $this->execute($m['file']);
                $executed++;
                ($this->out)("  [ OK ] {$v} {$m['name']}: готово");
            }
            $this->record($m, 'run', $dryRun);
        }
        // Контрольні суми вже застосованих нових міграцій: файл змінили після застосування — попередження.
        foreach ($this->applied() as $v => $row) {
            $f = $this->files()[$v] ?? null;
            if ($f !== null && !$f['legacy'] && $row['checksum'] !== null && $row['checksum'] !== sha1_file($f['file'])) {
                ($this->out)("  [WARN] {$v} {$f['name']}: файл змінено після застосування — зміни до БД НЕ внесено (створюйте нову міграцію)");
            }
        }
        return $executed;
    }

    private function record(array $m, string $via, bool $dryRun): void
    {
        if ($dryRun) {
            return;
        }
        $this->ensureTable();
        $this->pdo->prepare(
            'INSERT INTO schema_migrations (version, name, checksum, applied_via) VALUES (:v, :n, :c, :via)
             ON DUPLICATE KEY UPDATE name = VALUES(name)'
        )->execute([
            'v' => $m['version'], 'n' => $m['name'], 'via' => $via,
            'c' => $m['legacy'] ? null : sha1_file($m['file']),
        ]);
    }

    /**
     * Ознака «legacy-міграцію вже застосовано»: true — так, false — ні, null — міграція не потрібна взагалі (002).
     * Це ті самі умови, які раніше перевіряв update.sh перед кожною міграцією.
     */
    public function legacyApplied(string $version): ?bool
    {
        $col = fn(string $t, string $c) => $this->columnExists($t, $c);
        $tab = fn(string $t) => $this->tableExists($t);
        switch ($version) {
            case '001': return $col('projects', 'responsible_user_id');
            case '002':
                $bad = (int) $this->scalar("SELECT COUNT(*) FROM roles WHERE name COLLATE utf8mb4_bin LIKE '%Ð%' OR description COLLATE utf8mb4_bin LIKE '%Ð%'"); // _bin: без нього «Ð» збігається з «D»
                return $bad > 0 ? false : null;
            case '003': return $col('tasks', 'start_date');
            case '004': return $tab('milestones');
            case '005': return $this->indexExists('time_logs', 'idx_time_logs_log_date');
            case '006': return $this->indexExists('audit_log', 'idx_audit_log_created_at');
            case '007': return $col('users', 'locked_until');
            case '008': return $col('tickets', 'project_id');
            case '009': return $col('tickets', 'access_token');
            case '010': return (int) $this->scalar(
                "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sla_policies' AND COLUMN_NAME = 'queue_id' AND NON_UNIQUE = 0"
            ) > 0;
            case '011': return $tab('email_ingest_log');
            case '012': return $this->settingExists('email_autoreply_enabled');
            case '013': return $tab('task_due_reminders');
            case '014': return $this->settingExists('email_notify_tickets_enabled');
            case '015': return $col('ticket_queues', 'default_operator_id');
            case '016': return $col('users', 'ad_username');
            case '017': return $col('users', 'ad_ou');
            case '018': return $col('attachments', 'stored_name'); // стара порожня заготовка має інші стовпці
            case '019': return $col('attachments', 'source');
            case '020': return $tab('wiki_pages');
            case '021': return $tab('project_members');
            case '022': return $tab('webhook_endpoints');
            case '023': return $tab('rate_limits');
            case '024': return $tab('library_versions');
            case '025': return $tab('forum_posts');
            case '026': return $tab('wiki_attachments');
            case '027': return $col('users', 'ad_role_locked');
            case '028': return $col('users', 'ad_guid');
            case '029': return (int) $this->scalar("SELECT COUNT(*) FROM roles WHERE code = 'unit_admin'") > 0;
            case '030': return $col('users', 'unit_ou');
            case '031': return $tab('forum_board_subscriptions');
            case '032': return !$col('projects', 'visibility');
            case '033': return $tab('forum_read_marks');
            case '034': return $tab('forum_attachments');
            case '035': return $tab('forum_topic_mutes');
            case '036': return $col('library_documents', 'unit_ou');
            case '037': return $this->attachmentMimeWide();
            case '038': return $tab('task_checklist_items') && $col('tasks', 'parent_task_id');
            case '039': return $tab('template_items');
            case '040': return $tab('user_sessions') && $tab('login_log');
        }
        return false;
    }

    /** 037: стовпець attachments.mime_type розширено (≥ 100 символів) для Office-типів. */
    private function attachmentMimeWide(): bool
    {
        $len = (int) $this->scalar(
            "SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attachments' AND COLUMN_NAME = 'mime_type'"
        );
        return $len >= 100;
    }

    // ------------------------------------------------------------------ виконання SQL-файлу

    private function execute(string $file): void
    {
        $sql = @file_get_contents($file);
        if ($sql === false) {
            throw new \RuntimeException("Не вдалося прочитати {$file}");
        }
        $this->pdo->exec("SET NAMES utf8mb4");
        foreach (self::splitStatements($sql) as $i => $statement) {
            try {
                $stmt = $this->pdo->query($statement);
                if ($stmt instanceof \PDOStatement) {
                    $stmt->fetchAll();
                    $stmt->closeCursor();
                }
            } catch (\PDOException $e) {
                throw new \RuntimeException(basename($file) . ', оператор №' . ($i + 1) . ': ' . $e->getMessage() . "\n    " . mb_substr(preg_replace('/\s+/', ' ', $statement), 0, 160), 0, $e);
            }
        }
    }

    /**
     * Розбиває SQL-скрипт на оператори по «;» з урахуванням рядків (одинарні, подвійні й зворотні лапки) та коментарів
     * (рядкові «-- …» і «# …», блокові слеш-зірочка). Порожні оператори (самі коментарі) відкидаються.
     * Команда DELIMITER не підтримується (у міграціях її немає).
     *
     * @return string[]
     */
    public static function splitStatements(string $sql): array
    {
        $out = [];
        $buf = '';
        $len = strlen($sql);
        for ($i = 0; $i < $len; $i++) {
            $c = $sql[$i];
            $n = $i + 1 < $len ? $sql[$i + 1] : '';
            if ($c === '-' && $n === '-' && ($i + 2 >= $len || ctype_space($sql[$i + 2]))) { // -- коментар
                $e = strpos($sql, "\n", $i);
                $i = $e === false ? $len : $e;
                $buf .= "\n";
                continue;
            }
            if ($c === '#') {
                $e = strpos($sql, "\n", $i);
                $i = $e === false ? $len : $e;
                $buf .= "\n";
                continue;
            }
            if ($c === '/' && $n === '*') { // /* … */ (версійні /*! … */ вважаємо коментарем — у міграціях їх немає)
                $e = strpos($sql, '*/', $i + 2);
                $i = $e === false ? $len : $e + 1;
                $buf .= ' ';
                continue;
            }
            if ($c === "'" || $c === '"' || $c === '`') {
                $q = $c;
                $buf .= $c;
                for ($i++; $i < $len; $i++) {
                    $buf .= $sql[$i];
                    if ($sql[$i] === '\\' && $q !== '`' && $i + 1 < $len) {
                        $buf .= $sql[++$i];
                    } elseif ($sql[$i] === $q) {
                        if ($i + 1 < $len && $sql[$i + 1] === $q) { // подвоєна лапка всередині рядка
                            $buf .= $sql[++$i];
                        } else {
                            break;
                        }
                    }
                }
                continue;
            }
            if ($c === ';') {
                if (trim($buf) !== '') {
                    $out[] = trim($buf);
                }
                $buf = '';
                continue;
            }
            $buf .= $c;
        }
        if (trim($buf) !== '') {
            $out[] = trim($buf);
        }
        return $out;
    }

    // ------------------------------------------------------------------ допоміжні перевірки

    private function scalar(string $sql): mixed
    {
        try {
            return $this->pdo->query($sql)->fetchColumn();
        } catch (\PDOException) {
            return 0; // немає таблиці/стовпця — «ознаки немає»
        }
    }

    public function tableExists(string $t): bool
    {
        $s = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t');
        $s->execute(['t' => $t]);
        return (int) $s->fetchColumn() > 0;
    }

    private function columnExists(string $t, string $c): bool
    {
        $s = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c');
        $s->execute(['t' => $t, 'c' => $c]);
        return (int) $s->fetchColumn() > 0;
    }

    private function indexExists(string $t, string $i): bool
    {
        $s = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND INDEX_NAME = :i');
        $s->execute(['t' => $t, 'i' => $i]);
        return (int) $s->fetchColumn() > 0;
    }

    private function settingExists(string $key): bool
    {
        if (!$this->tableExists('app_settings')) {
            return false;
        }
        $s = $this->pdo->prepare('SELECT COUNT(*) FROM app_settings WHERE setting_key = :k');
        $s->execute(['k' => $key]);
        return (int) $s->fetchColumn() > 0;
    }
}
