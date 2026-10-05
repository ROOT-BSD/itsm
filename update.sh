#!/usr/bin/env bash
#
# ITSM System — скрипт ОНОВЛЕННЯ вже розгорнутої системи (не первинної інсталяції).
#
# Робить усе в одну команду, без повторного введення паролів БД:
#   1. Читає підключення до БД з вашого існуючого .env
#   2. Перевіряє й, за потреби, виправляє пошкоджену кирилицю (подвійне UTF-8)
#   3. Накочує нову колонку responsible_user_id (якщо її ще немає)
#   4. Накочує нову колонку start_date для задач (діаграма Ганта), якщо її ще немає
#   5. Накочує таблицю milestones для дорожньої карти, якщо її ще немає
#   6. Додає індекс на time_logs.log_date (прискорює звіти обліку часу), якщо його ще немає
#   7. Додає індекс на audit_log.created_at (прискорює журнал аудиту), якщо його ще немає
#   8. Додає таблицю/колонки для блокування входу після невдалих спроб пароля, якщо їх ще немає
#   9. Додає колонку tickets.project_id (прив'язка тікета до проєкту), якщо її ще немає
#   10. Додає колонку tickets.access_token (портал самообслуговування /support), якщо її ще немає
#   11. Додає унікальність SLA-нормативу на чергу (керування нормативами в інтерфейсі), якщо її ще немає
#   12. Додає таблицю журналу обробки пошти для email-to-ticket, якщо її ще немає
#   13. Додає налаштування автовідповіді заявнику (email-to-ticket), якщо його ще немає
#   14. Додає таблицю нагадувань про термін і налаштування email-сповіщень, якщо їх ще немає
#   15. Додає гранульовані перемикачі email-сповіщень (тікети/проєкти/задачі/нагадування) замість однієї спільної галочки
#   16. Додає колонку автопризначення оператора для черги тікетів, якщо її ще немає
#   17. Додає users.ad_username, таблицю відповідності груп AD ролям і налаштування синхронізації для Active Directory
#   18. Додає users.ad_ou для групування AD-користувачів за організаційним підрозділом (OU) на сторінці «Користувачі»
#   19. Видаляє застарілий кеш метрик шрифтів PDF-звітів (якщо він містить шлях з іншого сервера)
#   20. Перевстановлює права доступу на файли для веб-сервера
#
# ВИКОРИСТАННЯ (на сервері, у корені проєкту, ПІСЛЯ того, як нові файли
# з архіву вже скопійовані поверх старих — .env при цьому НЕ чіпайте):
#
#   sudo bash update.sh
#
set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ENV_FILE="${SCRIPT_DIR}/.env"
APP_VERSION="$(cat "${SCRIPT_DIR}/VERSION" 2>/dev/null || echo "?")"

if [ -t 1 ]; then
    RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'
    BLUE='\033[0;34m'; BOLD='\033[1m'; NC='\033[0m'
else
    RED=''; GREEN=''; YELLOW=''; BLUE=''; BOLD=''; NC=''
fi

ok()      { echo -e "  ${GREEN}[ OK ]${NC} $1"; }
fail()    { echo -e "  ${RED}[FAIL]${NC} $1"; }
warn()    { echo -e "  ${YELLOW}[WARN]${NC} $1"; }
info()    { echo -e "  ${BLUE}[INFO]${NC} $1"; }
section() { echo -e "\n${BOLD}$1${NC}"; }

echo -e "${BOLD}=========================================="
echo -e " ITSM System v${APP_VERSION} — оновлення"
echo -e "==========================================${NC}"

# ---------- 1. Читання .env ----------
section "1. Читання налаштувань підключення до БД"

if [ ! -f "$ENV_FILE" ]; then
    fail ".env не знайдено в ${SCRIPT_DIR}"
    info "Цей скрипт призначений для ОНОВЛЕННЯ вже розгорнутої системи."
    info "Якщо це нова інсталяція — використовуйте install.sh, не update.sh."
    exit 1
fi

# Той самий простий парсер, що й у config/config.php — без залежностей.
DB_HOST=""; DB_PORT="3306"; DB_DATABASE=""; DB_USERNAME=""; DB_PASSWORD=""
while IFS= read -r line || [ -n "$line" ]; do
    line="$(echo "$line" | sed 's/^[[:space:]]*//;s/[[:space:]]*$//')"
    [ -z "$line" ] && continue
    case "$line" in \#*) continue ;; esac
    case "$line" in
        DB_HOST=*) DB_HOST="${line#DB_HOST=}" ;;
        DB_PORT=*) DB_PORT="${line#DB_PORT=}" ;;
        DB_DATABASE=*) DB_DATABASE="${line#DB_DATABASE=}" ;;
        DB_USERNAME=*) DB_USERNAME="${line#DB_USERNAME=}" ;;
        DB_PASSWORD=*) DB_PASSWORD="${line#DB_PASSWORD=}" ;;
    esac
done < "$ENV_FILE"
# Прибираємо можливі лапки навколо значень
DB_HOST="${DB_HOST%\"}"; DB_HOST="${DB_HOST#\"}"
DB_DATABASE="${DB_DATABASE%\"}"; DB_DATABASE="${DB_DATABASE#\"}"
DB_USERNAME="${DB_USERNAME%\"}"; DB_USERNAME="${DB_USERNAME#\"}"
DB_PASSWORD="${DB_PASSWORD%\"}"; DB_PASSWORD="${DB_PASSWORD#\"}"

if [ -z "$DB_DATABASE" ] || [ -z "$DB_USERNAME" ]; then
    fail "Не вдалося прочитати DB_DATABASE/DB_USERNAME з .env"
    exit 1
fi

ok "База даних: ${DB_DATABASE}, користувач: ${DB_USERNAME}@${DB_HOST:-127.0.0.1}"

# ВАЖЛИВО: --default-character-set=utf8mb4 обов'язковий у кожному виклику нижче —
# саме його відсутність і спричиняє пошкодження кирилиці, яке цей скрипт виправляє.
MYSQL="mysql --default-character-set=utf8mb4 -h ${DB_HOST:-127.0.0.1} -P ${DB_PORT:-3306} -u ${DB_USERNAME} -p${DB_PASSWORD} ${DB_DATABASE}"

if ! echo "SELECT 1;" | $MYSQL >/dev/null 2>&1; then
    fail "Не вдалося підключитися до БД з даними із .env"
    info "Перевірте, що СУБД запущена і дані в .env досі правильні."
    exit 1
fi
ok "З'єднання з БД встановлено"

# ---------- 2. Перевірка та виправлення кирилиці ----------
section "2. Перевірка кодування кирилиці в довідниках"

CORRUPTED=$(echo "SELECT COUNT(*) FROM roles WHERE name LIKE '%Ð%' OR description LIKE '%Ð%';" | $MYSQL -N 2>/dev/null || echo "0")

if [ "${CORRUPTED:-0}" -gt 0 ]; then
    warn "Знайдено ${CORRUPTED} пошкоджених рядків у довідниках (подвійне UTF-8-кодування)"

    MIGRATION_FIX="${SCRIPT_DIR}/database/migrations/002_fix_utf8_double_encoding.sql"
    if [ ! -f "$MIGRATION_FIX" ]; then
        fail "Файл ${MIGRATION_FIX} не знайдено"
        info "Переконайтесь, що ви скопіювали ВСЮ папку database/ з нового архіву, і запустіть update.sh ще раз."
        exit 1
    fi

    info "Застосовую скрипт відновлення..."
    if $MYSQL < "$MIGRATION_FIX" >/dev/null 2>&1; then
        ok "Кирилицю відновлено"
    else
        fail "Помилка застосування скрипта відновлення"
        exit 1
    fi
else
    ok "Пошкоджень не виявлено — довідники в порядку"
fi

# ---------- 3. Колонка responsible_user_id ----------
section "3. Перевірка колонки 'відповідальний за проєкт'"

COLUMN_EXISTS=$(echo "
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'projects' AND COLUMN_NAME = 'responsible_user_id';
" | $MYSQL -N 2>/dev/null || echo "0")

if [ "${COLUMN_EXISTS:-0}" -eq 0 ]; then
    info "Колонку responsible_user_id не знайдено — додаю..."

    MIGRATION_COL="${SCRIPT_DIR}/database/migrations/001_add_project_responsible.sql"
    if [ ! -f "$MIGRATION_COL" ]; then
        fail "Файл ${MIGRATION_COL} не знайдено"
        info "Переконайтесь, що ви скопіювали ВСЮ папку database/ з нового архіву, і запустіть update.sh ще раз."
        exit 1
    fi

    if $MYSQL < "$MIGRATION_COL" >/dev/null 2>&1; then
        ok "Колонку responsible_user_id додано — тепер можна призначати відповідального за проєкт"
    else
        fail "Помилка додавання колонки"
        exit 1
    fi
else
    ok "Колонка responsible_user_id вже є — нічого робити не треба"
fi

# ---------- 4. Колонка start_date (для діаграми Ганта) ----------
section "4. Перевірка колонки 'дата початку задачі' (Гант)"

COLUMN_EXISTS2=$(echo "
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tasks' AND COLUMN_NAME = 'start_date';
" | $MYSQL -N 2>/dev/null || echo "0")

if [ "${COLUMN_EXISTS2:-0}" -eq 0 ]; then
    info "Колонку start_date не знайдено — додаю..."

    MIGRATION_COL2="${SCRIPT_DIR}/database/migrations/003_add_task_start_date.sql"
    if [ ! -f "$MIGRATION_COL2" ]; then
        fail "Файл ${MIGRATION_COL2} не знайдено"
        info "Переконайтесь, що ви скопіювали ВСЮ папку database/ з нового архіву, і запустіть update.sh ще раз."
        exit 1
    fi

    if $MYSQL < "$MIGRATION_COL2" >/dev/null 2>&1; then
        ok "Колонку start_date додано — тепер доступна діаграма Ганта з датами початку"
    else
        fail "Помилка додавання колонки"
        exit 1
    fi
else
    ok "Колонка start_date вже є — нічого робити не треба"
fi

# ---------- 5. Таблиця milestones (дорожня карта) ----------
section "5. Перевірка таблиці 'етапи проєкту' (дорожня карта)"

TABLE_EXISTS_MS=$(echo "
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'milestones';
" | $MYSQL -N 2>/dev/null || echo "0")

if [ "${TABLE_EXISTS_MS:-0}" -eq 0 ]; then
    info "Таблицю milestones не знайдено — додаю (разом з колонкою tasks.milestone_id)..."

    MIGRATION_MS="${SCRIPT_DIR}/database/migrations/004_add_milestones.sql"
    if [ ! -f "$MIGRATION_MS" ]; then
        fail "Файл ${MIGRATION_MS} не знайдено"
        info "Переконайтесь, що ви скопіювали ВСЮ папку database/ з нового архіву, і запустіть update.sh ще раз."
        exit 1
    fi

    if $MYSQL < "$MIGRATION_MS" >/dev/null 2>&1; then
        ok "Таблицю milestones додано — тепер доступна дорожня карта проєкту"
    else
        fail "Помилка створення таблиці milestones"
        exit 1
    fi
else
    ok "Таблиця milestones вже є — нічого робити не треба"
fi

# ---------- 6. Індекс на time_logs.log_date ----------
section "6. Перевірка індексу для звітів обліку часу"

INDEX_EXISTS=$(echo "
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'time_logs' AND INDEX_NAME = 'idx_time_logs_log_date';
" | $MYSQL -N 2>/dev/null || echo "0")

if [ "${INDEX_EXISTS:-0}" -eq 0 ]; then
    info "Індекс idx_time_logs_log_date не знайдено — додаю (прискорює звіти обліку часу)..."

    MIGRATION_IDX="${SCRIPT_DIR}/database/migrations/005_add_time_logs_date_index.sql"
    if [ ! -f "$MIGRATION_IDX" ]; then
        fail "Файл ${MIGRATION_IDX} не знайдено"
        info "Переконайтесь, що ви скопіювали ВСЮ папку database/ з нового архіву, і запустіть update.sh ще раз."
        exit 1
    fi

    if $MYSQL < "$MIGRATION_IDX" >/dev/null 2>&1; then
        ok "Індекс додано — звіти обліку часу (проєкт/адмін/PDF) працюватимуть швидше на великих обсягах даних"
    else
        fail "Помилка додавання індексу"
        exit 1
    fi
else
    ok "Індекс idx_time_logs_log_date вже є — нічого робити не треба"
fi

# ---------- 7. Індекс на audit_log.created_at ----------
section "7. Перевірка індексу для журналу аудиту"

AUDIT_INDEX_EXISTS=$(echo "
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'audit_log' AND INDEX_NAME = 'idx_audit_log_created_at';
" | $MYSQL -N 2>/dev/null || echo "0")

if [ "${AUDIT_INDEX_EXISTS:-0}" -eq 0 ]; then
    info "Індекс idx_audit_log_created_at не знайдено — додаю (прискорює сторінку /admin/audit)..."

    MIGRATION_AUDIT_IDX="${SCRIPT_DIR}/database/migrations/006_add_audit_log_date_index.sql"
    if [ ! -f "$MIGRATION_AUDIT_IDX" ]; then
        fail "Файл ${MIGRATION_AUDIT_IDX} не знайдено"
        info "Переконайтесь, що ви скопіювали ВСЮ папку database/ з нового архіву, і запустіть update.sh ще раз."
        exit 1
    fi

    if $MYSQL < "$MIGRATION_AUDIT_IDX" >/dev/null 2>&1; then
        ok "Індекс додано — журнал аудиту (/admin/audit) працюватиме швидше на великих обсягах даних"
    else
        fail "Помилка додавання індексу"
        exit 1
    fi
else
    ok "Індекс idx_audit_log_created_at вже є — нічого робити не треба"
fi

# ---------- 8. Блокування входу після невдалих спроб ----------
section "8. Перевірка таблиці/колонок для блокування входу"

LOCKOUT_COL_EXISTS=$(echo "
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'locked_until';
" | $MYSQL -N 2>/dev/null || echo "0")

if [ "${LOCKOUT_COL_EXISTS:-0}" -eq 0 ]; then
    info "Колонки блокування входу не знайдено — додаю (разом з таблицею app_settings)..."

    MIGRATION_LOCKOUT="${SCRIPT_DIR}/database/migrations/007_add_login_lockout.sql"
    if [ ! -f "$MIGRATION_LOCKOUT" ]; then
        fail "Файл ${MIGRATION_LOCKOUT} не знайдено"
        info "Переконайтесь, що ви скопіювали ВСЮ папку database/ з нового архіву, і запустіть update.sh ще раз."
        exit 1
    fi

    if $MYSQL < "$MIGRATION_LOCKOUT" >/dev/null 2>&1; then
        ok "Додано — тепер доступне блокування облікового запису після невдалих спроб входу (Адмін-панель → Безпека входу)"
    else
        fail "Помилка додавання таблиці/колонок блокування входу"
        exit 1
    fi
else
    ok "Колонки блокування входу вже є — нічого робити не треба"
fi

# ---------- 9. Прив'язка тікетів до проєктів ----------
section "9. Перевірка колонки прив'язки тікета до проєкту"

TICKET_PROJECT_COL_EXISTS=$(echo "
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tickets' AND COLUMN_NAME = 'project_id';
" | $MYSQL -N 2>/dev/null || echo "0")

if [ "${TICKET_PROJECT_COL_EXISTS:-0}" -eq 0 ]; then
    info "Колонку tickets.project_id не знайдено — додаю..."

    MIGRATION_TICKET_PROJ="${SCRIPT_DIR}/database/migrations/008_add_ticket_project_link.sql"
    if [ ! -f "$MIGRATION_TICKET_PROJ" ]; then
        fail "Файл ${MIGRATION_TICKET_PROJ} не знайдено"
        info "Переконайтесь, що ви скопіювали ВСЮ папку database/ з нового архіву, і запустіть update.sh ще раз."
        exit 1
    fi

    if $MYSQL < "$MIGRATION_TICKET_PROJ" >/dev/null 2>&1; then
        ok "Додано — тепер тікети можна прив'язувати до проєктів"
    else
        fail "Помилка додавання колонки tickets.project_id"
        exit 1
    fi
else
    ok "Колонка tickets.project_id вже є — нічого робити не треба"
fi

# ---------- 10. Токен доступу для порталу самообслуговування ----------
section "10. Перевірка токена доступу для порталу самообслуговування"

ACCESS_TOKEN_COL_EXISTS=$(echo "
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tickets' AND COLUMN_NAME = 'access_token';
" | $MYSQL -N 2>/dev/null || echo "0")

if [ "${ACCESS_TOKEN_COL_EXISTS:-0}" -eq 0 ]; then
    info "Колонку tickets.access_token не знайдено — додаю (потрібна для /support — подання й відстеження звернень без входу в систему)..."

    MIGRATION_TOKEN="${SCRIPT_DIR}/database/migrations/009_add_ticket_access_token.sql"
    if [ ! -f "$MIGRATION_TOKEN" ]; then
        fail "Файл ${MIGRATION_TOKEN} не знайдено"
        info "Переконайтесь, що ви скопіювали ВСЮ папку database/ з нового архіву, і запустіть update.sh ще раз."
        exit 1
    fi

    if $MYSQL < "$MIGRATION_TOKEN" >/dev/null 2>&1; then
        ok "Додано — портал самообслуговування (/support) готовий до роботи"
    else
        fail "Помилка додавання колонки tickets.access_token"
        exit 1
    fi
else
    ok "Колонка tickets.access_token вже є — нічого робити не треба"
fi

# ---------- 11. Унікальність SLA-нормативу на чергу ----------
section "11. Перевірка унікальності SLA-нормативу на чергу"

# Перевіряємо саме NON_UNIQUE = 0, а не назву індексу: СУБД автоматично створює
# для зовнішнього ключа звичайний індекс з такою ж назвою "queue_id".
SLA_UNIQUE_EXISTS=$(echo "
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sla_policies'
      AND COLUMN_NAME = 'queue_id' AND NON_UNIQUE = 0;
" | $MYSQL -N 2>/dev/null || echo "0")

if [ "${SLA_UNIQUE_EXISTS:-0}" -eq 0 ]; then
    info "Унікальність sla_policies.queue_id не знайдено — додаю (потрібна для керування нормативами через Адмін-панель → Черги тікетів)..."
    info "Якщо на якусь чергу було кілька нормативів — залишиться лише найновіший."

    MIGRATION_SLA="${SCRIPT_DIR}/database/migrations/010_add_sla_policies_unique_queue.sql"
    if [ ! -f "$MIGRATION_SLA" ]; then
        fail "Файл ${MIGRATION_SLA} не знайдено"
        info "Переконайтесь, що ви скопіювали ВСЮ папку database/ з нового архіву, і запустіть update.sh ще раз."
        exit 1
    fi

    if $MYSQL < "$MIGRATION_SLA" >/dev/null 2>&1; then
        ok "Додано — SLA-нормативи тепер редагуються в Адмін-панель → Черги тікетів"
    else
        fail "Помилка додавання унікальності sla_policies.queue_id"
        exit 1
    fi
else
    ok "Унікальність sla_policies.queue_id вже є — нічого робити не треба"
fi

# ---------- 12. Email-to-ticket ----------
section "12. Перевірка таблиці журналу обробки пошти (email-to-ticket)"

EMAIL_TABLE_EXISTS=$(echo "
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_ingest_log';
" | $MYSQL -N 2>/dev/null || echo "0")

if [ "${EMAIL_TABLE_EXISTS:-0}" -eq 0 ]; then
    info "Таблицю email_ingest_log не знайдено — додаю (разом з налаштуваннями обробки пошти)..."

    MIGRATION_EMAIL="${SCRIPT_DIR}/database/migrations/011_add_email_to_ticket.sql"
    if [ ! -f "$MIGRATION_EMAIL" ]; then
        fail "Файл ${MIGRATION_EMAIL} не знайдено"
        info "Переконайтесь, що ви скопіювали ВСЮ папку database/ з нового архіву, і запустіть update.sh ще раз."
        exit 1
    fi

    if $MYSQL < "$MIGRATION_EMAIL" >/dev/null 2>&1; then
        ok "Додано — сторінка Адмін-панель → Пошта → тікети готова до налаштування"
        info "Далі: пропишіть MAIL_IMAP_* у .env і додайте bin/fetch-mail.php в cron (див. ту сторінку в адмін-панелі)."
    else
        fail "Помилка додавання таблиці email_ingest_log"
        exit 1
    fi
else
    ok "Таблиця email_ingest_log вже є — нічого робити не треба"
fi

# ---------- 13. Налаштування автовідповіді email-to-ticket ----------
section "13. Перевірка налаштування автовідповіді заявнику"

AUTOREPLY_SETTING_EXISTS=$(echo "
    SELECT COUNT(*) FROM app_settings WHERE setting_key = 'email_autoreply_enabled';
" | $MYSQL -N 2>/dev/null || echo "0")

if [ "${AUTOREPLY_SETTING_EXISTS:-0}" -eq 0 ]; then
    info "Налаштування email_autoreply_enabled не знайдено — додаю (вимкнено за замовчуванням)..."

    MIGRATION_AUTOREPLY="${SCRIPT_DIR}/database/migrations/012_add_email_autoreply_setting.sql"
    if [ ! -f "$MIGRATION_AUTOREPLY" ]; then
        fail "Файл ${MIGRATION_AUTOREPLY} не знайдено"
        info "Переконайтесь, що ви скопіювали ВСЮ папку database/ з нового архіву, і запустіть update.sh ще раз."
        exit 1
    fi

    if $MYSQL < "$MIGRATION_AUTOREPLY" >/dev/null 2>&1; then
        ok "Додано — автовідповідь можна ввімкнути в Адмін-панель → Пошта → тікети (після налаштування SMTP)"
    else
        fail "Помилка додавання налаштування email_autoreply_enabled"
        exit 1
    fi
else
    ok "Налаштування email_autoreply_enabled вже є — нічого робити не треба"
fi

# ---------- 14. Email-сповіщення про активність і нагадування про термін ----------
section "14. Перевірка таблиці нагадувань про термін і налаштування сповіщень"

REMINDERS_TABLE_EXISTS=$(echo "
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'task_due_reminders';
" | $MYSQL -N 2>/dev/null || echo "0")

if [ "${REMINDERS_TABLE_EXISTS:-0}" -eq 0 ]; then
    info "Таблицю task_due_reminders не знайдено — додаю (разом з налаштуванням email-сповіщень)..."

    MIGRATION_NOTIFICATIONS="${SCRIPT_DIR}/database/migrations/013_add_email_notifications.sql"
    if [ ! -f "$MIGRATION_NOTIFICATIONS" ]; then
        fail "Файл ${MIGRATION_NOTIFICATIONS} не знайдено"
        info "Переконайтесь, що ви скопіювали ВСЮ папку database/ з нового архіву, і запустіть update.sh ще раз."
        exit 1
    fi

    if $MYSQL < "$MIGRATION_NOTIFICATIONS" >/dev/null 2>&1; then
        ok "Додано — email-сповіщення можна ввімкнути в Адмін-панель → Пошта → тікети (після налаштування SMTP)"
    else
        fail "Помилка додавання таблиці task_due_reminders"
        exit 1
    fi
else
    ok "Таблиця task_due_reminders вже є — нічого робити не треба"
fi

# ---------- 15. Гранульовані перемикачі email-сповіщень ----------
section "15. Перевірка гранульованих перемикачів email-сповіщень"

GRANULAR_SETTING_EXISTS=$(echo "
    SELECT COUNT(*) FROM app_settings WHERE setting_key = 'email_notify_tickets_enabled';
" | $MYSQL -N 2>/dev/null || echo "0")

if [ "${GRANULAR_SETTING_EXISTS:-0}" -eq 0 ]; then
    info "Гранульовані налаштування email-сповіщень не знайдено — додаю (замість однієї спільної галочки)..."
    info "Якщо стара спільна галочка вже була увімкнена — усі чотири нові стануть увімкненими, нічого не вимкнеться."

    MIGRATION_GRANULAR="${SCRIPT_DIR}/database/migrations/014_add_granular_notification_settings.sql"
    if [ ! -f "$MIGRATION_GRANULAR" ]; then
        fail "Файл ${MIGRATION_GRANULAR} не знайдено"
        info "Переконайтесь, що ви скопіювали ВСЮ папку database/ з нового архіву, і запустіть update.sh ще раз."
        exit 1
    fi

    if $MYSQL < "$MIGRATION_GRANULAR" >/dev/null 2>&1; then
        ok "Додано — тікети/проєкти/задачі/нагадування тепер вмикаються окремо в Адмін-панель → Пошта → тікети"
    else
        fail "Помилка додавання гранульованих налаштувань email-сповіщень"
        exit 1
    fi
else
    ok "Гранульовані налаштування email-сповіщень вже є — нічого робити не треба"
fi

# ---------- 16. Автопризначення оператора для черги тікетів ----------
section "16. Перевірка колонки автопризначення оператора для черги"

QUEUE_OPERATOR_COLUMN_EXISTS=$(echo "
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ticket_queues' AND COLUMN_NAME = 'default_operator_id';
" | $MYSQL -N 2>/dev/null || echo "0")

if [ "${QUEUE_OPERATOR_COLUMN_EXISTS:-0}" -eq 0 ]; then
    info "Колонку ticket_queues.default_operator_id не знайдено — додаю..."

    MIGRATION_QUEUE_OPERATOR="${SCRIPT_DIR}/database/migrations/015_add_queue_default_operator.sql"
    if [ ! -f "$MIGRATION_QUEUE_OPERATOR" ]; then
        fail "Файл ${MIGRATION_QUEUE_OPERATOR} не знайдено"
        info "Переконайтесь, що ви скопіювали ВСЮ папку database/ з нового архіву, і запустіть update.sh ще раз."
        exit 1
    fi

    if $MYSQL < "$MIGRATION_QUEUE_OPERATOR" >/dev/null 2>&1; then
        ok "Додано — автопризначення оператора налаштовується в Адмін-панель → Черги тікетів"
    else
        fail "Помилка додавання колонки ticket_queues.default_operator_id"
        exit 1
    fi
else
    ok "Колонка ticket_queues.default_operator_id вже є — нічого робити не треба"
fi

# ---------- 17. Інтеграція з Active Directory ----------
section "17. Перевірка таблиць і колонок для Active Directory"

AD_COLUMN_EXISTS=$(echo "
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'ad_username';
" | $MYSQL -N 2>/dev/null || echo "0")

if [ "${AD_COLUMN_EXISTS:-0}" -eq 0 ]; then
    info "Колонку users.ad_username не знайдено — додаю (разом з таблицею відповідності груп ролям і налаштуванням синхронізації)..."

    MIGRATION_AD="${SCRIPT_DIR}/database/migrations/016_add_active_directory.sql"
    if [ ! -f "$MIGRATION_AD" ]; then
        fail "Файл ${MIGRATION_AD} не знайдено"
        info "Переконайтесь, що ви скопіювали ВСЮ папку database/ з нового архіву, і запустіть update.sh ще раз."
        exit 1
    fi

    if $MYSQL < "$MIGRATION_AD" >/dev/null 2>&1; then
        ok "Додано — Active Directory налаштовується в Адмін-панель → Active Directory (після AD_* у .env)"
    else
        fail "Помилка додавання колонок/таблиць для Active Directory"
        exit 1
    fi
else
    ok "Колонка users.ad_username вже є — нічого робити не треба"
fi

# ---------- 18. OU AD-користувача для сторінки «Користувачі» ----------
section "18. Перевірка колонки OU AD-користувача"

AD_OU_COLUMN_EXISTS=$(echo "
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'ad_ou';
" | $MYSQL -N 2>/dev/null || echo "0")

if [ "${AD_OU_COLUMN_EXISTS:-0}" -eq 0 ]; then
    info "Колонку users.ad_ou не знайдено — додаю (проміжні ad_container/ad_department зі старих варіантів, якщо є, міграція прибере)..."

    MIGRATION_AD_OU="${SCRIPT_DIR}/database/migrations/017_add_user_ad_ou.sql"
    if [ ! -f "$MIGRATION_AD_OU" ]; then
        fail "Файл ${MIGRATION_AD_OU} не знайдено"
        info "Переконайтесь, що ви скопіювали ВСЮ папку database/ з нового архіву, і запустіть update.sh ще раз."
        exit 1
    fi

    if $MYSQL < "$MIGRATION_AD_OU" >/dev/null 2>&1; then
        ok "Додано — сторінка «Користувачі» групує AD-акаунти за OU (заповниться після наступної синхронізації)"
    else
        fail "Помилка додавання колонки users.ad_ou"
        exit 1
    fi
else
    ok "Колонка users.ad_ou вже є — нічого робити не треба"
fi

# ---------- 19. Очищення застарілого кешу PDF-шрифтів ----------
section "19. Очищення кешу метрик шрифтів PDF-звітів"

TFPDF_CACHE_DIR="${SCRIPT_DIR}/app/Vendor/tfpdf/font/unifont"
if [ -d "$TFPDF_CACHE_DIR" ]; then
    STALE_CACHE=$(find "$TFPDF_CACHE_DIR" \( -name "*.mtx.php" -o -name "*.cw.dat" -o -name "*.cw127.php" \) 2>/dev/null)
    if [ -n "$STALE_CACHE" ]; then
        info "Видаляю кеш метрик шрифту (може містити шлях з попереднього сервера)..."
        find "$TFPDF_CACHE_DIR" \( -name "*.mtx.php" -o -name "*.cw.dat" -o -name "*.cw127.php" \) -delete 2>/dev/null
        ok "Кеш видалено — бібліотека tFPDF перестворить його сама з правильним шляхом при першому звіті"
    else
        ok "Застарілого кешу не знайдено — нічого робити не треба"
    fi
else
    ok "Директорія tFPDF ще не оновлена з нового архіву — пропускаю (з'явиться після копіювання файлів)"
fi

# ---------- 20. Права доступу ----------
section "20. Права доступу до файлів"

if [ "$(id -u)" -ne 0 ]; then
    warn "Скрипт запущено не від root — права доступу пропущено"
    info "Запустіть з sudo, щоб цей крок теж відпрацював: sudo bash update.sh"
else
    WEB_USER=""
    for candidate in www-data apache nginx _www; do
        if id "$candidate" >/dev/null 2>&1; then
            WEB_USER="$candidate"
            break
        fi
    done

    if [ -z "$WEB_USER" ]; then
        warn "Не вдалося визначити користувача веб-сервера — права доступу не змінено"
        info "Виставте вручну, орієнтуючись на README.md"
    else
        WEB_GROUP="$(id -gn "$WEB_USER")"
        chown -R "${WEB_USER}:${WEB_GROUP}" "$SCRIPT_DIR"
        find "$SCRIPT_DIR" -type d -exec chmod 750 {} \; 2>/dev/null
        find "$SCRIPT_DIR" -type f -exec chmod 640 {} \; 2>/dev/null
        chmod 750 "${SCRIPT_DIR}/install.sh" "${SCRIPT_DIR}/update.sh" 2>/dev/null
        [ -d "${SCRIPT_DIR}/storage/uploads" ] && chmod -R 770 "${SCRIPT_DIR}/storage/uploads"
        [ -d "$TFPDF_CACHE_DIR" ] && chmod -R 770 "$TFPDF_CACHE_DIR"
        chmod 600 "${SCRIPT_DIR}/.env"
        ok "Права доступу оновлено (власник: ${WEB_USER}:${WEB_GROUP})"
    fi
fi

# ---------- Підсумок ----------
section "Оновлення завершено"

cat <<FINAL

  Перевірте в браузері:
  ${BOLD}1.${NC} Назви ролей і статусів задач — мають бути нормальною українською.
  ${BOLD}2.${NC} З'явився пункт меню «Тікети».
  ${BOLD}3.${NC} На сторінці проєкту є поле «Відповідальний».
  ${BOLD}4.${NC} На сторінці задачі є поле «Виконавець».

  Якщо сторінка не відкривається — див. розділ «Усунення несправностей» у README.md.

FINAL

exit 0
