# shellcheck shell=bash
# Крок 2: міграції БД (bin/migrate.php, облік у таблиці schema_migrations).
# Підключається (source) з update.sh — не запускайте окремо. Змінні й функції (ok/fail/warn/info/section, MYSQL…) задають lib.sh та 10-environment.sh.

section "2. Міграції бази даних"

if ! command -v php >/dev/null 2>&1; then
    fail "php не знайдено в PATH — міграції неможливо застосувати"
    info "Встановіть PHP CLI (php8.3-cli) і запустіть update.sh ще раз."
    exit 1
fi
if [ ! -f "${SCRIPT_DIR}/bin/migrate.php" ] || [ ! -d "${SCRIPT_DIR}/database/migrations" ]; then
    fail "Немає bin/migrate.php або каталогу database/migrations"
    info "Переконайтесь, що ви скопіювали ВСІ файли з нового архіву (bin/, database/, app/), і запустіть update.sh ще раз."
    exit 1
fi

# Раніше тут було 40 окремих кроків (по одному на міграцію). Тепер одна команда: вона сама визначає, що вже застосовано
# (для старої БД — за наявністю таблиць/стовпців), виконує лише відсутнє й веде облік у schema_migrations.
# Перевірити без змін: php bin/migrate.php --status  (або --dry-run).
if MIGRATE_OUT="$(php "${SCRIPT_DIR}/bin/migrate.php" 2>&1)"; then
    echo "$MIGRATE_OUT" | grep -v "вже застосована" | grep -v "пошкоджень кирилиці немає" | sed '/^$/d'
    MIGRATED_QUIET=$(echo "$MIGRATE_OUT" | grep -c "вже застосована")
    [ "${MIGRATED_QUIET:-0}" -gt 0 ] && info "Міграцій, що вже були застосовані раніше: ${MIGRATED_QUIET}"
else
    echo "$MIGRATE_OUT"
    fail "Міграції не застосовано — оновлення зупинено"
    info "Виправте причину (див. повідомлення вище) і запустіть update.sh ще раз: застосовані міграції повторно не виконуються."
    exit 1
fi
