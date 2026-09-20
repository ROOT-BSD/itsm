#!/usr/bin/env bash
#
# ITSM System — скрипт ОНОВЛЕННЯ вже розгорнутої системи (не первинної інсталяції).
#
# Робить усе в одну команду, без повторного введення паролів БД:
#   1. Читає підключення до БД з вашого існуючого .env
#   2. Перевіряє й, за потреби, виправляє пошкоджену кирилицю (подвійне UTF-8)
#   3. Накочує нову колонку responsible_user_id (якщо її ще немає)
#   4. Перевстановлює права доступу на файли для веб-сервера
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

# ---------- 4. Права доступу ----------
section "4. Права доступу до файлів"

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
