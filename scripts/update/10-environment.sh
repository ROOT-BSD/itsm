# shellcheck shell=bash
# Крок 1: читання .env, перевірка з'єднання з БД (змінна $MYSQL).
# Підключається (source) з update.sh — не запускайте окремо. Змінні й функції (ok/fail/warn/info/section, MYSQL…) задають lib.sh та 10-environment.sh.

section "1. Підключення до бази даних (.env)"

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
