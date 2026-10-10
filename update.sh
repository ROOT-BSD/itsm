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
#   19. Додає таблицю вкладень до тікетів і задач, створює storage/uploads, показує поточні PHP-ліміти завантаження
#   20. Додає колонку attachments.source (звідки вкладення: web / портал / лист)
#   21. Створює таблиці вікі й завантажує в неї гайди користувача та адміністратора з docs/
#   22. Створює таблицю учасників проєктів (надання доступу до проєкту обраним користувачам)
#   23. Створює таблиці REST API і вебхуків (API за замовчуванням вимкнене)
#   24. Створює таблицю лічильників обмеження частоти запитів до порталу /support
#   25. Створює таблиці бібліотеки документів (розділ «Документи»)
#   26. Створює таблиці форуму (розділ «Форум»)
#   27. Вікі: додає батьківську сторінку (ієрархія) і таблицю вкладень (міграція 026)
#   28. Додає прапорці ручного перевизначення ролі/деактивації AD-користувачів (міграція 027)
#   29. Додає стабільний ідентифікатор AD-користувача objectGUID (міграція 028)
#   30. Додає роль «Адміністратор Підрозділу» (міграція 029)
#   31. Додає підрозділ (unit_ou) для локальних користувачів (міграція 030)
#   32. Створює таблицю підписок на розділи форуму (міграція 031)
#   33. Створює таблиці позначок «непрочитане» форуму (міграція 033)
#   34. Створює таблицю вкладень форуму (міграція 034)
#   35. Видаляє невикористане поле «Видимість» проєкту (міграція 032)
#   36. Видаляє застарілий кеш метрик шрифтів PDF-звітів (якщо він містить шлях з іншого сервера)
#   37. Перевстановлює права доступу на файли для веб-сервера
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
        ok "Додано — тепер доступне блокування облікового запису після невдалих спроб входу (Адмін-панель → Налаштування → Безпека входу)"
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
    info "Унікальність sla_policies.queue_id не знайдено — додаю (потрібна для керування нормативами через Адмін-панель → Керування → Черги тікетів)..."
    info "Якщо на якусь чергу було кілька нормативів — залишиться лише найновіший."

    MIGRATION_SLA="${SCRIPT_DIR}/database/migrations/010_add_sla_policies_unique_queue.sql"
    if [ ! -f "$MIGRATION_SLA" ]; then
        fail "Файл ${MIGRATION_SLA} не знайдено"
        info "Переконайтесь, що ви скопіювали ВСЮ папку database/ з нового архіву, і запустіть update.sh ще раз."
        exit 1
    fi

    if $MYSQL < "$MIGRATION_SLA" >/dev/null 2>&1; then
        ok "Додано — SLA-нормативи тепер редагуються в Адмін-панель → Керування → Черги тікетів"
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
        ok "Додано — сторінка Адмін-панель → Налаштування → Пошта → тікети готова до налаштування"
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
        ok "Додано — автовідповідь можна ввімкнути в Адмін-панель → Налаштування → Пошта → тікети (після налаштування SMTP)"
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
        ok "Додано — email-сповіщення можна ввімкнути в Адмін-панель → Налаштування → Пошта → тікети (після налаштування SMTP)"
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
        ok "Додано — тікети/проєкти/задачі/нагадування тепер вмикаються окремо в Адмін-панель → Налаштування → Пошта → тікети"
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
        ok "Додано — автопризначення оператора налаштовується в Адмін-панель → Керування → Черги тікетів"
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
        ok "Додано — Active Directory налаштовується в Адмін-панель → Налаштування → Active Directory (після AD_* у .env)"
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

# ---------- 19. Вкладення до тікетів і задач ----------
section "19. Перевірка таблиці вкладень і директорії для файлів"

# Перевіряємо саме НОВУ структуру (стовпець stored_name): у старих схемах уже була порожня
# заготовка attachments з іншими стовпцями — перевірка лише «таблиця існує» її б пропустила.
ATTACHMENTS_TABLE_EXISTS=$(echo "
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attachments' AND COLUMN_NAME = 'stored_name';
" | $MYSQL -N 2>/dev/null || echo "0")

if [ "${ATTACHMENTS_TABLE_EXISTS:-0}" -eq 0 ]; then
    info "Таблицю вкладень нової структури не знайдено — створюю (стару порожню заготовку, якщо є, міграція замінить)..."

    MIGRATION_ATTACHMENTS="${SCRIPT_DIR}/database/migrations/018_add_attachments.sql"
    if [ ! -f "$MIGRATION_ATTACHMENTS" ]; then
        fail "Файл ${MIGRATION_ATTACHMENTS} не знайдено"
        info "Переконайтесь, що ви скопіювали ВСЮ папку database/ з нового архіву, і запустіть update.sh ще раз."
        exit 1
    fi

    if $MYSQL < "$MIGRATION_ATTACHMENTS" >/dev/null 2>&1; then
        ok "Таблицю attachments створено — на сторінках тікета й задачі з'явився блок «Вкладення»"
    else
        fail "Помилка створення таблиці attachments"
        exit 1
    fi
else
    ok "Таблиця attachments вже є — нічого робити не треба"
fi

# Файли вкладень лежать у storage/uploads (поза веб-коренем) — директорія має існувати.
mkdir -p "${SCRIPT_DIR}/storage/uploads" && ok "Директорія storage/uploads на місці"

# Журнал необроблених помилок застосунку (App\Core\ErrorHandler): у нього потрапляє причина кожної «помилки 500».
mkdir -p "${SCRIPT_DIR}/storage/logs" && ok "Директорія storage/logs на місці (журнал помилок застосунку)"

# Підказка: PHP за замовчуванням дозволяє файли лише до 2 МБ на файл (upload_max_filesize).
# CLI-php може мати інші налаштування, ніж PHP-FPM/Apache — показуємо як орієнтир, не як вирок.
PHP_UPLOAD_MAX="$(php -r 'echo ini_get("upload_max_filesize");' 2>/dev/null)"
PHP_POST_MAX="$(php -r 'echo ini_get("post_max_size");' 2>/dev/null)"
info "PHP (CLI) зараз: upload_max_filesize=${PHP_UPLOAD_MAX:-?}, post_max_size=${PHP_POST_MAX:-?}"
info "Застосунок дозволяє вкладення до 10 МБ (змінюється: ATTACHMENT_MAX_MB у .env), але не більше за ці PHP-ліміти."
info "Щоб приймати файли більші за ліміт PHP, збільшіть upload_max_filesize і post_max_size у php.ini вашого веб-сервера (PHP-FPM/Apache) і перезапустіть його."

# ---------- 20. Джерело вкладення (web / портал / лист) ----------
section "20. Перевірка колонки джерела вкладення"

ATTACHMENT_SOURCE_EXISTS=$(echo "
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attachments' AND COLUMN_NAME = 'source';
" | $MYSQL -N 2>/dev/null || echo "0")

if [ "${ATTACHMENT_SOURCE_EXISTS:-0}" -eq 0 ]; then
    info "Колонку attachments.source не знайдено — додаю..."

    MIGRATION_ATT_SOURCE="${SCRIPT_DIR}/database/migrations/019_add_attachment_source.sql"
    if [ ! -f "$MIGRATION_ATT_SOURCE" ]; then
        fail "Файл ${MIGRATION_ATT_SOURCE} не знайдено"
        info "Переконайтесь, що ви скопіювали ВСЮ папку database/ з нового архіву, і запустіть update.sh ще раз."
        exit 1
    fi

    if $MYSQL < "$MIGRATION_ATT_SOURCE" >/dev/null 2>&1; then
        ok "Додано — на сторінці тікета видно, чи файл з порталу, з листа чи доданий вручну"
    else
        fail "Помилка додавання колонки attachments.source"
        exit 1
    fi
else
    ok "Колонка attachments.source вже є — нічого робити не треба"
fi

# ---------- 21. Вікі ----------
section "21. Перевірка таблиць вікі й завантаження документації"

WIKI_TABLE_EXISTS=$(echo "
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wiki_pages';
" | $MYSQL -N 2>/dev/null || echo "0")

if [ "${WIKI_TABLE_EXISTS:-0}" -eq 0 ]; then
    info "Таблиці вікі не знайдено — створюю..."

    MIGRATION_WIKI="${SCRIPT_DIR}/database/migrations/020_add_wiki.sql"
    if [ ! -f "$MIGRATION_WIKI" ]; then
        fail "Файл ${MIGRATION_WIKI} не знайдено"
        info "Переконайтесь, що ви скопіювали ВСЮ папку database/ з нового архіву, і запустіть update.sh ще раз."
        exit 1
    fi

    if $MYSQL < "$MIGRATION_WIKI" >/dev/null 2>&1; then
        ok "Таблиці вікі створено — у меню з'явився розділ «Вікі»"
    else
        fail "Помилка створення таблиць вікі"
        exit 1
    fi
else
    ok "Таблиці вікі вже є — нічого створювати не треба"
fi

# Гайди користувача й адміністратора (docs/*.md) → вікі. Імпорт безпечний для повторів: нові сторінки створює,
# невідредаговані — оновлює до свіжої версії документації, а ті, що правили у вікі вручну, НЕ чіпає.
if command -v php >/dev/null 2>&1; then
    WIKI_IMPORT_OUT="$(php "${SCRIPT_DIR}/bin/import-wiki-docs.php" 2>&1)"
    WIKI_IMPORT_CODE=$?
    echo "$WIKI_IMPORT_OUT" | sed 's/^\[[^]]*\] \[itsm-wiki-import\] /  [INFO] /'
    if [ "$WIKI_IMPORT_CODE" -eq 0 ]; then
        ok "Документацію у вікі актуалізовано"
    else
        warn "Імпорт документації у вікі завершився з помилкою — повторіть: php bin/import-wiki-docs.php"
    fi
else
    warn "php не знайдено в PATH — документацію у вікі не завантажено (php bin/import-wiki-docs.php)"
fi

# ---------- 22. Учасники проєктів ----------
section "22. Перевірка таблиці учасників проєктів"

PROJECT_MEMBERS_EXISTS=$(echo "
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'project_members';
" | $MYSQL -N 2>/dev/null || echo "0")

if [ "${PROJECT_MEMBERS_EXISTS:-0}" -eq 0 ]; then
    info "Таблицю project_members не знайдено — створюю..."

    MIGRATION_MEMBERS="${SCRIPT_DIR}/database/migrations/021_add_project_members.sql"
    if [ ! -f "$MIGRATION_MEMBERS" ]; then
        fail "Файл ${MIGRATION_MEMBERS} не знайдено"
        info "Переконайтесь, що ви скопіювали ВСЮ папку database/ з нового архіву, і запустіть update.sh ще раз."
        exit 1
    fi

    if $MYSQL < "$MIGRATION_MEMBERS" >/dev/null 2>&1; then
        ok "Таблицю створено — на сторінці проєкту з'явився блок «Доступ до проєкту» (надання доступу обраним користувачам)"
    else
        fail "Помилка створення таблиці project_members"
        exit 1
    fi
else
    ok "Таблиця project_members вже є — нічого робити не треба"
fi

# ---------- 23. REST API і вебхуки ----------
section "23. Перевірка таблиць REST API і вебхуків"

API_TABLES=$(echo "
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME IN ('api_tokens', 'api_rate_limits', 'webhook_endpoints', 'webhook_deliveries');
" | $MYSQL -N 2>/dev/null || echo "0")

if [ "${API_TABLES:-0}" -lt 4 ]; then
    info "Таблиці REST API і вебхуків не знайдено (або знайдено не всі) — створюю..."

    MIGRATION_API="${SCRIPT_DIR}/database/migrations/022_add_api_and_webhooks.sql"
    if [ ! -f "$MIGRATION_API" ]; then
        fail "Файл ${MIGRATION_API} не знайдено"
        info "Переконайтесь, що ви скопіювали ВСЮ папку database/ з нового архіву, і запустіть update.sh ще раз."
        exit 1
    fi

    if $MYSQL < "$MIGRATION_API" >/dev/null 2>&1; then
        ok "Таблиці створено — REST API (за замовчуванням ВИМКНЕНО) і вебхуки з'явились у Адмін-панель → Налаштування"
    else
        fail "Помилка створення таблиць REST API і вебхуків"
        exit 1
    fi
else
    ok "Таблиці REST API і вебхуків вже є — нічого створювати не треба"
fi

# ---------- 24. Обмеження частоти запитів до порталу ----------
section "24. Перевірка таблиці обмеження частоти запитів"

RL_TABLE=$(echo "
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rate_limits';
" | $MYSQL -N 2>/dev/null || echo "0")

if [ "${RL_TABLE:-0}" -lt 1 ]; then
    info "Таблицю rate_limits не знайдено — створюю..."

    MIGRATION_RL="${SCRIPT_DIR}/database/migrations/023_add_rate_limits.sql"
    if [ ! -f "$MIGRATION_RL" ]; then
        fail "Файл ${MIGRATION_RL} не знайдено"
        info "Переконайтесь, що ви скопіювали ВСЮ папку database/ з нового архіву, і запустіть update.sh ще раз."
        exit 1
    fi

    if $MYSQL < "$MIGRATION_RL" >/dev/null 2>&1; then
        ok "Таблицю створено — портал /support тепер захищений від флуду (10 звернень за 10 хв і 30 за добу з однієї IP)"
        info "Якщо сайт стоїть за зворотним проксі/балансувальником — вкажіть його адресу в .env: TRUSTED_PROXIES=10.0.0.5"
    else
        fail "Помилка створення таблиці rate_limits"
        exit 1
    fi
else
    ok "Таблиця rate_limits вже є — нічого створювати не треба"
fi

# ---------- 25. Бібліотека документів ----------
section "25. Перевірка таблиць бібліотеки документів"

LIB_TABLES=$(echo "
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME IN ('library_categories', 'library_documents', 'library_versions');
" | $MYSQL -N 2>/dev/null || echo "0")

if [ "${LIB_TABLES:-0}" -lt 3 ]; then
    info "Таблиці бібліотеки документів не знайдено (або знайдено не всі) — створюю..."

    MIGRATION_LIB="${SCRIPT_DIR}/database/migrations/024_add_document_library.sql"
    if [ ! -f "$MIGRATION_LIB" ]; then
        fail "Файл ${MIGRATION_LIB} не знайдено"
        info "Переконайтесь, що ви скопіювали ВСЮ папку database/ з нового архіву, і запустіть update.sh ще раз."
        exit 1
    fi

    if $MYSQL < "$MIGRATION_LIB" >/dev/null 2>&1; then
        ok "Таблиці створено — у верхньому меню з'явився розділ «Документи»"
        info "Розмір файлу за замовчуванням — до 25 МБ (LIBRARY_MAX_MB в .env), але не більше PHP-лімітів upload_max_filesize і post_max_size."
    else
        fail "Помилка створення таблиць бібліотеки документів"
        exit 1
    fi
else
    ok "Таблиці бібліотеки документів вже є — нічого створювати не треба"
fi

# ---------- 26. Форум ----------
section "26. Перевірка таблиць форуму"

FORUM_TABLES=$(echo "
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME IN ('forum_boards', 'forum_topics', 'forum_posts');
" | $MYSQL -N 2>/dev/null || echo "0")

if [ "${FORUM_TABLES:-0}" -lt 3 ]; then
    info "Таблиці форуму не знайдено (або знайдено не всі) — створюю..."

    MIGRATION_FORUM="${SCRIPT_DIR}/database/migrations/025_add_forum.sql"
    if [ ! -f "$MIGRATION_FORUM" ]; then
        fail "Файл ${MIGRATION_FORUM} не знайдено"
        info "Переконайтесь, що ви скопіювали ВСЮ папку database/ з нового архіву, і запустіть update.sh ще раз."
        exit 1
    fi

    if $MYSQL < "$MIGRATION_FORUM" >/dev/null 2>&1; then
        ok "Таблиці створено — у верхньому меню з'явився «Форум» (спершу адміністратор створює в ньому розділи)"
    else
        fail "Помилка створення таблиць форуму"
        exit 1
    fi
else
    ok "Таблиці форуму вже є — нічого створювати не треба"
fi

# ---------- 27. Вікі: ієрархія сторінок і вкладення ----------
section "27. Перевірка ієрархії сторінок і вкладень вікі"

WIKI_V2=$(echo "
    SELECT (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wiki_pages' AND COLUMN_NAME = 'parent_id')
         + (SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wiki_attachments');
" | $MYSQL -N 2>/dev/null || echo "0")

if [ "${WIKI_V2:-0}" -lt 2 ]; then
    info "Ієрархії сторінок або вкладень вікі ще немає — додаю..."

    MIGRATION_WIKI2="${SCRIPT_DIR}/database/migrations/026_add_wiki_hierarchy_and_attachments.sql"
    if [ ! -f "$MIGRATION_WIKI2" ]; then
        fail "Файл ${MIGRATION_WIKI2} не знайдено"
        info "Переконайтесь, що ви скопіювали ВСЮ папку database/ з нового архіву, і запустіть update.sh ще раз."
        exit 1
    fi

    if $MYSQL < "$MIGRATION_WIKI2" >/dev/null 2>&1; then
        ok "Готово — у вікі можна вкладати сторінки одна в одну та додавати зображення й файли"
    else
        fail "Помилка міграції вікі (ієрархія й вкладення)"
        exit 1
    fi
else
    ok "Ієрархія сторінок і вкладення вікі вже є — нічого створювати не треба"
fi

# ---------- 28. AD: ручне перевизначення ролі та деактивації ----------
section "28. Перевірка прапорців ручного перевизначення для AD-користувачів"

AD_OVR=$(echo "
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME IN ('ad_role_locked', 'ad_blocked');
" | $MYSQL -N 2>/dev/null || echo "0")

if [ "${AD_OVR:-0}" -lt 2 ]; then
    info "Прапорців ручного перевизначення ще немає — додаю..."

    MIGRATION_ADOVR="${SCRIPT_DIR}/database/migrations/027_add_ad_manual_overrides.sql"
    if [ ! -f "$MIGRATION_ADOVR" ]; then
        fail "Файл ${MIGRATION_ADOVR} не знайдено"
        info "Переконайтесь, що ви скопіювали ВСЮ папку database/ з нового архіву, і запустіть update.sh ще раз."
        exit 1
    fi

    if $MYSQL < "$MIGRATION_ADOVR" >/dev/null 2>&1; then
        ok "Готово — роль і деактивацію AD-користувача можна закріпити вручну"
    else
        fail "Помилка міграції 027 (ручне перевизначення для AD)"
        exit 1
    fi
else
    ok "Прапорці ручного перевизначення вже є — нічого створювати не треба"
fi

# ---------- 29. AD: objectGUID ----------
section "29. Перевірка колонки objectGUID для AD-користувачів"

AD_GUID=$(echo "
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'ad_guid';
" | $MYSQL -N 2>/dev/null || echo "0")

if [ "${AD_GUID:-0}" -lt 1 ]; then
    info "Колонки objectGUID ще немає — додаю..."

    MIGRATION_ADGUID="${SCRIPT_DIR}/database/migrations/028_add_ad_guid.sql"
    if [ ! -f "$MIGRATION_ADGUID" ]; then
        fail "Файл ${MIGRATION_ADGUID} не знайдено"
        info "Переконайтесь, що ви скопіювали ВСЮ папку database/ з нового архіву, і запустіть update.sh ще раз."
        exit 1
    fi

    if $MYSQL < "$MIGRATION_ADGUID" >/dev/null 2>&1; then
        ok "Готово — GUID наявних AD-користувачів збережеться під час наступної синхронізації"
    else
        fail "Помилка міграції 028 (objectGUID)"
        exit 1
    fi
else
    ok "Колонка objectGUID вже є — нічого створювати не треба"
fi

# ---------- 30. Роль «Адміністратор Підрозділу» ----------
section "30. Перевірка ролі «Адміністратор Підрозділу»"

UNIT_ROLE=$(echo "SELECT COUNT(*) FROM roles WHERE code = 'unit_admin';" | $MYSQL -N 2>/dev/null || echo "0")

if [ "${UNIT_ROLE:-0}" -lt 1 ]; then
    info "Ролі «Адміністратор Підрозділу» ще немає — додаю..."

    MIGRATION_UNIT="${SCRIPT_DIR}/database/migrations/029_add_unit_admin_role.sql"
    if [ ! -f "$MIGRATION_UNIT" ]; then
        fail "Файл ${MIGRATION_UNIT} не знайдено"
        info "Переконайтесь, що ви скопіювали ВСЮ папку database/ з нового архіву, і запустіть update.sh ще раз."
        exit 1
    fi

    if $MYSQL < "$MIGRATION_UNIT" >/dev/null 2>&1; then
        ok "Готово — роль можна призначати користувачам AD (Користувачі) або зіставляти з групою AD"
    else
        fail "Помилка міграції 029 (роль «Адміністратор Підрозділу»)"
        exit 1
    fi
else
    ok "Роль «Адміністратор Підрозділу» вже є — нічого створювати не треба"
fi

# ---------- 31. Підрозділ локальних користувачів ----------
section "31. Перевірка колонки підрозділу для локальних користувачів"

UNIT_COL=$(echo "
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'unit_ou';
" | $MYSQL -N 2>/dev/null || echo "0")

if [ "${UNIT_COL:-0}" -lt 1 ]; then
    info "Колонки підрозділу для локальних користувачів ще немає — додаю..."

    MIGRATION_UNITCOL="${SCRIPT_DIR}/database/migrations/030_add_local_user_unit.sql"
    if [ ! -f "$MIGRATION_UNITCOL" ]; then
        fail "Файл ${MIGRATION_UNITCOL} не знайдено"
        info "Переконайтесь, що ви скопіювали ВСЮ папку database/ з нового архіву, і запустіть update.sh ще раз."
        exit 1
    fi

    if $MYSQL < "$MIGRATION_UNITCOL" >/dev/null 2>&1; then
        ok "Готово — локальному користувачеві можна задати підрозділ (Користувачі → Редагувати)"
    else
        fail "Помилка міграції 030 (підрозділ локальних користувачів)"
        exit 1
    fi
else
    ok "Колонка підрозділу локальних користувачів вже є — нічого створювати не треба"
fi

# ---------- 32. Підписки на розділи форуму ----------
section "32. Перевірка таблиці підписок на розділи форуму"

SUB_TABLE=$(echo "
    SELECT COUNT(*) FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'forum_board_subscriptions';
" | $MYSQL -N 2>/dev/null || echo "0")
FORUM_TABLE=$(echo "
    SELECT COUNT(*) FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'forum_boards';
" | $MYSQL -N 2>/dev/null || echo "0")

if [ "${FORUM_TABLE:-0}" -lt 1 ]; then
    warn "Таблиць форуму ще немає (крок 26) — підписки створити неможливо"
elif [ "${SUB_TABLE:-0}" -lt 1 ]; then
    info "Таблиці підписок на розділи форуму ще немає — створюю..."

    MIGRATION_FSUB="${SCRIPT_DIR}/database/migrations/031_add_forum_board_subscriptions.sql"
    if [ ! -f "$MIGRATION_FSUB" ]; then
        fail "Файл ${MIGRATION_FSUB} не знайдено"
        info "Переконайтесь, що ви скопіювали ВСЮ папку database/ з нового архіву, і запустіть update.sh ще раз."
        exit 1
    fi

    if $MYSQL < "$MIGRATION_FSUB" >/dev/null 2>&1; then
        ok "Готово — на сторінці розділу форуму з'явиться кнопка «Підписатися»"
    else
        fail "Помилка міграції 031 (підписки на розділи форуму)"
        exit 1
    fi
else
    ok "Таблиця підписок на розділи форуму вже є — нічого створювати не треба"
fi

# ---------- 33. Позначки «непрочитане» форуму ----------
section "33. Позначки «непрочитане» форуму"

if [ "$(echo "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('forum_topic_reads','forum_read_marks');" | $MYSQL -N 2>/dev/null || echo 0)" -lt 2 ]; then
    info "Таблиць відміток прочитання ще немає — створюю..."
    MIGRATION_UNREAD="${SCRIPT_DIR}/database/migrations/033_add_forum_unread.sql"
    if [ ! -f "$MIGRATION_UNREAD" ]; then
        fail "Файл ${MIGRATION_UNREAD} не знайдено"
        info "Переконайтесь, що ви скопіювали ВСЮ папку database/ з нового архіву, і запустіть update.sh ще раз."
        exit 1
    fi
    if $MYSQL < "$MIGRATION_UNREAD" >/dev/null 2>&1; then
        ok "Готово — позначки «непрочитане» на форумі працюють"
    else
        fail "Помилка міграції 033 (позначки «непрочитане»)"
        exit 1
    fi
else
    ok "Таблиці відміток прочитання вже є — нічого створювати не треба"
fi

# ---------- 34. Вкладення в повідомленнях форуму ----------
section "34. Вкладення в повідомленнях форуму"

if [ "$(echo "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'forum_attachments';" | $MYSQL -N 2>/dev/null || echo 0)" -lt 1 ]; then
    info "Таблиці вкладень форуму ще немає — створюю..."
    MIGRATION_FATT="${SCRIPT_DIR}/database/migrations/034_add_forum_attachments.sql"
    if [ ! -f "$MIGRATION_FATT" ]; then
        fail "Файл ${MIGRATION_FATT} не знайдено"
        info "Переконайтесь, що ви скопіювали ВСЮ папку database/ з нового архіву, і запустіть update.sh ще раз."
        exit 1
    fi
    if $MYSQL < "$MIGRATION_FATT" >/dev/null 2>&1; then
        ok "Готово — до повідомлень форуму можна прикріплювати файли"
    else
        fail "Помилка міграції 034 (вкладення форуму)"
        exit 1
    fi
else
    ok "Таблиця вкладень форуму вже є — нічого створювати не треба"
fi

# ---------- 35. Видалення поля «Видимість» проєкту ----------
section "35. Видалення невикористаного поля «Видимість» проєкту"

VIS_COL=$(echo "
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'projects' AND COLUMN_NAME = 'visibility';
" | $MYSQL -N 2>/dev/null || echo "0")

if [ "${VIS_COL:-0}" -gt 0 ]; then
    info "Стовпець projects.visibility ще є (він ні на що не впливав) — видаляю..."

    MIGRATION_PVIS="${SCRIPT_DIR}/database/migrations/032_drop_project_visibility.sql"
    if [ ! -f "$MIGRATION_PVIS" ]; then
        fail "Файл ${MIGRATION_PVIS} не знайдено"
        info "Переконайтесь, що ви скопіювали ВСЮ папку database/ з нового архіву, і запустіть update.sh ще раз."
        exit 1
    fi

    if $MYSQL < "$MIGRATION_PVIS" >/dev/null 2>&1; then
        ok "Готово — поле «Видимість» проєкту прибрано"
    else
        fail "Помилка міграції 032 (видалення поля «Видимість» проєкту)"
        exit 1
    fi
else
    ok "Стовпця projects.visibility вже немає — нічого робити не треба"
fi

# ---------- 36. Очищення застарілого кешу PDF-шрифтів ----------
section "36. Очищення кешу метрик шрифтів PDF-звітів"

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

# ---------- 37. Права доступу ----------
section "37. Права доступу до файлів"

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
        # storage/logs: веб-сервер дописує в журнал помилок застосунку
if [ -d "${SCRIPT_DIR}/storage/logs" ]; then
    chmod -R u+rwX,g+rwX "${SCRIPT_DIR}/storage/logs" 2>/dev/null && ok "storage/logs: доступна для запису веб-серверу"
fi

# storage/uploads: каталоги 770, ЗАВАНТАЖЕНІ ФАЙЛИ 660 — вкладення не повинні отримувати біт виконання
        if [ -d "${SCRIPT_DIR}/storage/uploads" ]; then
            find "${SCRIPT_DIR}/storage/uploads" -type d -exec chmod 770 {} \; 2>/dev/null
            find "${SCRIPT_DIR}/storage/uploads" -type f -exec chmod 660 {} \; 2>/dev/null
        fi
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

  Вебхуки (якщо ними користуєтесь): додайте в cron щохвилини — він відправляє події, що не встигли піти одразу,
  і виконує повтори невдалих доставок (з Apache mod_php без нього вебхуки не працюватимуть):
    * * * * *  www-data  php ${SCRIPT_DIR}/bin/deliver-webhooks.php >> /var/log/itsm-webhooks.log 2>&1

  Якщо сторінка не відкривається (помилка 500):
  ${BOLD}•${NC} Увійдіть адміністратором: сторінка помилки покаже ПРИЧИНУ й код інциденту; запис із деталями —
    у storage/logs/app-РРРР-ММ-ДД.log (шукайте цей код).
  ${BOLD}•${NC} Після копіювання нових файлів перезавантажте PHP-FPM — він міг лишити в пам'яті (opcache) СТАРУ
    версію коду, і вона не збігається з новою: sudo systemctl reload php-fpm
    (на Debian/Ubuntu ім'я з версією, наприклад php8.3-fpm; для Apache з mod_php — sudo systemctl reload apache2).
  ${BOLD}•${NC} Інші поширені причини — у розділі «Усунення несправностей» у README.md.

FINAL

exit 0
