#!/usr/bin/env bash
#
# ITSM System — скрипт інсталяції з перевіркою залежностей
# Підтримка: Debian/Ubuntu, RHEL/CentOS/Rocky/Alma, macOS (dev-режим)
#
# Використання:
#   sudo ./install.sh                    # повна інсталяція (перевірка + БД + права доступу)
#   ./install.sh --check-only            # тільки перевірка, без будь-яких змін
#   sudo ./install.sh --skip-db          # пропустити створення БД (якщо вже створена)
#   sudo ./install.sh --skip-perms       # не чіпати власника/права файлів
#   sudo ./install.sh --web-user=nginx   # явно задати користувача веб-сервера
#                                         # (якщо автовизначення не підійшло)
#
set -uo pipefail

APP_NAME="ITSM System"
MIN_PHP_VERSION="8.1"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_VERSION="$(cat "${SCRIPT_DIR}/VERSION" 2>/dev/null || echo "?")"

# ---------- Кольори виводу ----------
if [ -t 1 ]; then
    RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'
    BLUE='\033[0;34m'; BOLD='\033[1m'; NC='\033[0m'
else
    RED=''; GREEN=''; YELLOW=''; BLUE=''; BOLD=''; NC=''
fi

ERRORS=0
WARNINGS=0

ok()      { echo -e "  ${GREEN}[ OK ]${NC} $1"; }
fail()    { echo -e "  ${RED}[FAIL]${NC} $1"; ERRORS=$((ERRORS+1)); }
warn()    { echo -e "  ${YELLOW}[WARN]${NC} $1"; WARNINGS=$((WARNINGS+1)); }
info()    { echo -e "  ${BLUE}[INFO]${NC} $1"; }
section() { echo -e "\n${BOLD}$1${NC}"; }

# ---------- Розбір аргументів ----------
CHECK_ONLY=0
SKIP_DB=0
SKIP_PERMS=0
WEB_USER_OVERRIDE=""
for arg in "$@"; do
    case "$arg" in
        --check-only)     CHECK_ONLY=1 ;;
        --skip-db)        SKIP_DB=1 ;;
        --skip-perms)     SKIP_PERMS=1 ;;
        --web-user=*)     WEB_USER_OVERRIDE="${arg#--web-user=}" ;;
        -h|--help)
            grep '^#' "$0" | head -12 | sed 's/^# \{0,1\}//'
            exit 0
            ;;
        *) echo "Невідомий аргумент: $arg (див. --help)"; exit 1 ;;
    esac
done

echo -e "${BOLD}=========================================="
echo -e " ${APP_NAME} v${APP_VERSION} — інсталяція"
echo -e "==========================================${NC}"

# ---------- 1. Визначення ОС ----------
section "1. Визначення операційної системи"

OS_FAMILY="unknown"
PKG_MANAGER=""

if [ -f /etc/os-release ]; then
    # shellcheck disable=SC1091
    . /etc/os-release
    info "Виявлено: ${PRETTY_NAME:-$NAME}"
    case "${ID_LIKE:-$ID}" in
        *debian*|*ubuntu*) OS_FAMILY="debian"; PKG_MANAGER="apt-get" ;;
        *rhel*|*fedora*|*centos*) OS_FAMILY="rhel"; PKG_MANAGER="dnf"; command -v dnf >/dev/null || PKG_MANAGER="yum" ;;
    esac
elif [ "$(uname -s)" = "Darwin" ]; then
    OS_FAMILY="macos"; PKG_MANAGER="brew"
    info "Виявлено: macOS $(sw_vers -productVersion 2>/dev/null || echo '')"
    warn "macOS підтримується лише для локальної розробки, не для продуктивного розгортання"
fi

if [ "$OS_FAMILY" = "unknown" ]; then
    warn "Не вдалося визначити ОС — автовстановлення пакетів буде недоступне"
else
    ok "Сімейство ОС: $OS_FAMILY (менеджер пакетів: $PKG_MANAGER)"
fi

# ---------- 2. Перевірка прав ----------
section "2. Перевірка прав доступу"

IS_ROOT=0
if [ "$(id -u)" -eq 0 ]; then
    IS_ROOT=1
    ok "Скрипт запущено з правами root"
else
    if [ "$CHECK_ONLY" -eq 1 ]; then
        info "Режим перевірки — права root не потрібні"
    else
        warn "Скрипт запущено без root. Встановлення пакетів та налаштування може не спрацювати."
        warn "Рекомендовано: sudo ./install.sh"
    fi
fi

# ---------- 3. Перевірка PHP ----------
section "3. Перевірка PHP (мінімум ${MIN_PHP_VERSION})"

if command -v php >/dev/null 2>&1; then
    PHP_VERSION=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
    if php -r 'exit(version_compare(PHP_VERSION, "'"$MIN_PHP_VERSION"'", ">=") ? 0 : 1);'; then
        ok "PHP $PHP_VERSION (відповідає вимозі >= $MIN_PHP_VERSION)"
    else
        fail "PHP $PHP_VERSION застарий — потрібен >= $MIN_PHP_VERSION"
    fi
else
    fail "PHP не знайдено в системі"
    case "$OS_FAMILY" in
        debian) info "Встановити: sudo apt-get install -y php8.3-cli php8.3-fpm php8.3-mysql php8.3-mbstring" ;;
        rhel)   info "Встановити: sudo $PKG_MANAGER install -y php-cli php-fpm php-mysqlnd php-mbstring" ;;
        macos)  info "Встановити: brew install php" ;;
    esac
fi

# ---------- 4. Перевірка розширень PHP ----------
section "4. Перевірка розширень PHP"

# Обов'язкові розширення для роботи застосунку
REQUIRED_EXT="pdo pdo_mysql mbstring json session"
# Знадобляться на наступних етапах (вкладення, LDAP/AD-інтеграція)
OPTIONAL_EXT="fileinfo ldap openssl"

if command -v php >/dev/null 2>&1; then
    # Зчитуємо список розширень один раз у змінну.
    # Важливо: не використовувати "php -m | grep -q" у циклі — grep завершується
    # достроково, php отримує SIGPIPE, і через set -o pipefail перевірка
    # хибно повертає помилку.
    PHP_MODULES=$(php -m 2>/dev/null)

    has_ext() {
        printf '%s\n' "$PHP_MODULES" | grep -qi "^$1$"
    }

    for ext in $REQUIRED_EXT; do
        if has_ext "$ext"; then
            ok "Розширення ${ext}"
        else
            fail "Відсутнє обов'язкове розширення PHP: ${ext}"
        fi
    done

    for ext in $OPTIONAL_EXT; do
        if has_ext "$ext"; then
            ok "Розширення ${ext} (опційне)"
        else
            case "$ext" in
                ldap) warn "Відсутнє розширення ${ext} — знадобиться для інтеграції з Active Directory (Епік 13)" ;;
                fileinfo) warn "Відсутнє розширення ${ext} — знадобиться для завантаження вкладень (Епік 8)" ;;
                *) warn "Відсутнє опційне розширення ${ext}" ;;
            esac
        fi
    done
else
    fail "Перевірку розширень пропущено — PHP не встановлено"
fi

# ---------- 5. Перевірка MySQL / MariaDB ----------
section "5. Перевірка СУБД (MySQL 8.x / MariaDB 10.6+)"

DB_CLIENT=""
if command -v mysql >/dev/null 2>&1; then
    DB_CLIENT="mysql"
elif command -v mariadb >/dev/null 2>&1; then
    DB_CLIENT="mariadb"
fi

if [ -n "$DB_CLIENT" ]; then
    DB_VERSION_RAW=$($DB_CLIENT --version 2>/dev/null)
    ok "Клієнт СУБД знайдено: $DB_VERSION_RAW"

    # Перевірка доступності сервера
    if $DB_CLIENT --protocol=socket -u root -e "SELECT 1" >/dev/null 2>&1 \
       || $DB_CLIENT -u root -e "SELECT 1" >/dev/null 2>&1; then
        ok "Сервер СУБД запущено і доступний під root без пароля"
        DB_ROOT_ACCESS="nopass"
    elif mysqladmin ping >/dev/null 2>&1; then
        ok "Сервер СУБД запущено (потрібен пароль root)"
        DB_ROOT_ACCESS="pass"
    else
        warn "Сервер СУБД не відповідає — переконайтеся, що службу запущено"
        case "$OS_FAMILY" in
            debian) info "Запустити: sudo systemctl start mariadb  (або mysql)" ;;
            rhel)   info "Запустити: sudo systemctl start mariadb  (або mysqld)" ;;
            macos)  info "Запустити: brew services start mysql" ;;
        esac
        DB_ROOT_ACCESS="none"
    fi
else
    fail "Клієнт MySQL/MariaDB не знайдено"
    case "$OS_FAMILY" in
        debian) info "Встановити: sudo apt-get install -y mariadb-server" ;;
        rhel)   info "Встановити: sudo $PKG_MANAGER install -y mariadb-server" ;;
        macos)  info "Встановити: brew install mysql" ;;
    esac
    DB_ROOT_ACCESS="none"
fi

# ---------- 6. Перевірка веб-сервера ----------
section "6. Перевірка веб-сервера"

WEB_SERVER=""
if command -v nginx >/dev/null 2>&1; then
    WEB_SERVER="nginx"
    ok "Знайдено Nginx: $(nginx -v 2>&1 | sed 's/nginx version: //')"
elif command -v apache2 >/dev/null 2>&1; then
    WEB_SERVER="apache2"
    ok "Знайдено Apache: $(apache2 -v 2>/dev/null | head -1 | sed 's/Server version: //')"
elif command -v httpd >/dev/null 2>&1; then
    WEB_SERVER="httpd"
    ok "Знайдено Apache (httpd): $(httpd -v 2>/dev/null | head -1 | sed 's/Server version: //')"
else
    warn "Веб-сервер (Nginx/Apache) не знайдено"
    info "Для продуктивного розгортання встановіть Nginx + PHP-FPM або Apache"
    info "Для локальної розробки достатньо вбудованого сервера PHP (див. README.md)"
fi

# Перевірка PHP-FPM для Nginx
if [ "$WEB_SERVER" = "nginx" ]; then
    if command -v php-fpm >/dev/null 2>&1 || ls /usr/sbin/php-fpm* >/dev/null 2>&1 || ls /etc/php*/fpm >/dev/null 2>&1; then
        ok "PHP-FPM присутній (потрібен для Nginx)"
    else
        fail "Nginx знайдено, але PHP-FPM відсутній — Nginx не зможе обробляти PHP"
    fi
fi

# ---------- Визначення користувача веб-сервера ----------
# Потрібно для наступної секції прав доступу (chown/chmod).
# Пріоритет: явний --web-user=... → типові облікові записи за ОС/веб-сервером → не знайдено.
WEB_USER=""
WEB_GROUP=""

if [ -n "$WEB_USER_OVERRIDE" ]; then
    if id "$WEB_USER_OVERRIDE" >/dev/null 2>&1; then
        WEB_USER="$WEB_USER_OVERRIDE"
        WEB_GROUP="$(id -gn "$WEB_USER_OVERRIDE")"
    else
        warn "Вказаний --web-user=${WEB_USER_OVERRIDE} не існує в системі — автовизначення буде проігноровано"
    fi
fi

if [ -z "$WEB_USER" ]; then
    # Кандидати в порядку ймовірності залежно від ОС/веб-сервера
    CANDIDATES=""
    case "$OS_FAMILY" in
        debian) CANDIDATES="www-data" ;;
        rhel)
            if [ "$WEB_SERVER" = "nginx" ]; then CANDIDATES="nginx apache"; else CANDIDATES="apache nginx"; fi
            ;;
        macos) CANDIDATES="_www" ;;
        *) CANDIDATES="www-data apache nginx _www" ;;
    esac

    for candidate in $CANDIDATES; do
        if id "$candidate" >/dev/null 2>&1; then
            WEB_USER="$candidate"
            WEB_GROUP="$(id -gn "$candidate")"
            break
        fi
    done
fi

# ---------- 7. Перевірка структури проєкту ----------
section "7. Перевірка структури проєкту"

REQUIRED_PATHS="
public/index.php
app/autoload.php
app/Core/Database.php
app/Core/Router.php
app/Core/Auth.php
app/Core/View.php
config/config.php
database/schema.sql
database/seed.sql
"

for rel in $REQUIRED_PATHS; do
    if [ -f "${SCRIPT_DIR}/${rel}" ]; then
        ok "Файл ${rel}"
    else
        fail "Відсутній обов'язковий файл: ${rel}"
    fi
done

# ---------- 8. Перевірка синтаксису PHP-файлів ----------
section "8. Перевірка синтаксису PHP-коду"

if command -v php >/dev/null 2>&1; then
    SYNTAX_ERRORS=0
    while IFS= read -r phpfile; do
        if ! php -l "$phpfile" >/dev/null 2>&1; then
            fail "Синтаксична помилка: ${phpfile#$SCRIPT_DIR/}"
            SYNTAX_ERRORS=$((SYNTAX_ERRORS+1))
        fi
    done < <(find "$SCRIPT_DIR" -name '*.php' -not -path '*/.git/*')

    if [ "$SYNTAX_ERRORS" -eq 0 ]; then
        ok "Усі PHP-файли пройшли перевірку синтаксису"
    fi
else
    warn "Перевірку синтаксису пропущено — PHP не встановлено"
fi

# ---------- 9. Перевірка прав доступу ----------
section "9. Перевірка прав доступу"

if [ -n "$WEB_USER" ]; then
    ok "Користувач веб-сервера визначено: ${WEB_USER}:${WEB_GROUP}"
else
    warn "Не вдалося визначити користувача веб-сервера автоматично"
    info "Задайте вручну: sudo ./install.sh --web-user=ІМ_'Я_КОРИСТУВАЧА"
fi

CURRENT_OWNER="$(stat -c '%U:%G' "$SCRIPT_DIR" 2>/dev/null || stat -f '%Su:%Sg' "$SCRIPT_DIR" 2>/dev/null)"
info "Поточний власник каталогу проєкту: ${CURRENT_OWNER}"
if [ -n "$WEB_USER" ] && [ "$CURRENT_OWNER" != "${WEB_USER}:${WEB_GROUP}" ]; then
    warn "Власник каталогу не збігається з користувачем веб-сервера"
    [ "$CHECK_ONLY" -eq 0 ] && [ "$SKIP_PERMS" -eq 0 ] && info "Буде виправлено нижче (розділ 12)"
fi

UPLOAD_DIR="${SCRIPT_DIR}/storage/uploads"
if [ ! -d "$UPLOAD_DIR" ]; then
    if [ "$CHECK_ONLY" -eq 0 ]; then
        mkdir -p "$UPLOAD_DIR" && ok "Створено директорію storage/uploads"
    else
        warn "Директорія storage/uploads відсутня (буде створена при інсталяції)"
    fi
else
    ok "Директорія storage/uploads існує"
fi

if [ -d "$UPLOAD_DIR" ] && [ -w "$UPLOAD_DIR" ]; then
    ok "storage/uploads доступна для запису поточним користувачем"
elif [ -d "$UPLOAD_DIR" ]; then
    warn "storage/uploads недоступна для запису поточним користувачем (буде виправлено при інсталяції)"
fi

ENV_FILE_CHECK="${SCRIPT_DIR}/.env"
if [ -f "$ENV_FILE_CHECK" ]; then
    ENV_PERMS="$(stat -c '%a' "$ENV_FILE_CHECK" 2>/dev/null || stat -f '%Lp' "$ENV_FILE_CHECK" 2>/dev/null)"
    if [ "$ENV_PERMS" = "600" ] || [ "$ENV_PERMS" = "640" ]; then
        ok ".env існує, права доступу ${ENV_PERMS} (обмежені)"
    else
        warn ".env існує, але права доступу занадто відкриті: ${ENV_PERMS} (очікується 600/640)"
    fi
else
    info ".env ще не створено — буде згенеровано з обмеженими правами (600)"
fi

# ---------- Підсумок перевірки ----------
section "Підсумок перевірки залежностей"

echo -e "  Помилок:      ${RED}${ERRORS}${NC}"
echo -e "  Попереджень:  ${YELLOW}${WARNINGS}${NC}"

if [ "$ERRORS" -gt 0 ]; then
    echo -e "\n${RED}${BOLD}Інсталяцію неможливо продовжити.${NC}"
    echo -e "Усуньте помилки вище та запустіть скрипт повторно.\n"
    exit 1
fi

if [ "$CHECK_ONLY" -eq 1 ]; then
    echo -e "\n${GREEN}${BOLD}Перевірку завершено. Критичних проблем не виявлено.${NC}\n"
    exit 0
fi

echo -e "\n${GREEN}Усі обов'язкові залежності наявні.${NC}"

# ---------- 10. Налаштування бази даних ----------
if [ "$SKIP_DB" -eq 1 ]; then
    section "10. Налаштування БД — пропущено (--skip-db)"
else
    section "10. Налаштування бази даних"

    if [ "$DB_ROOT_ACCESS" = "none" ]; then
        fail "Немає доступу до сервера СУБД — налаштування БД неможливе"
        info "Запустіть службу СУБД та повторіть: sudo ./install.sh"
        exit 1
    fi

    read -r -p "  Назва бази даних [itsm]: " DB_NAME
    DB_NAME=${DB_NAME:-itsm}

    read -r -p "  Користувач БД для застосунку [itsm_user]: " DB_USER
    DB_USER=${DB_USER:-itsm_user}

    read -r -s -p "  Пароль для користувача ${DB_USER}: " DB_PASS
    echo
    if [ -z "$DB_PASS" ]; then
        fail "Пароль не може бути порожнім"
        exit 1
    fi

    # Формування команди підключення під root.
    # ВАЖЛИВО: --default-character-set=utf8mb4 обов'язковий — без нього клієнт mysql
    # може використати інше клієнтське кодування (типово latin1) при читанні schema.sql/
    # seed.sql, через що кирилиця в довідниках (ролі, типи задач, статуси, ім'я адміна)
    # запишеться в БД пошкодженою (подвійне UTF-8 кодування), навіть якщо сама БД і
    # стовпці оголошені як utf8mb4. Це не проявляється відразу — виглядає нормально
    # в самому mysql client, але ламається при виводі через PHP/веб-інтерфейс.
    if [ "$DB_ROOT_ACCESS" = "pass" ]; then
        read -r -s -p "  Пароль root для СУБД: " DB_ROOT_PASS
        echo
        MYSQL_ROOT="$DB_CLIENT --default-character-set=utf8mb4 -u root -p${DB_ROOT_PASS}"
    else
        MYSQL_ROOT="$DB_CLIENT --default-character-set=utf8mb4 -u root"
    fi

    info "Створення бази даних та користувача..."

    $MYSQL_ROOT <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL

    if [ $? -eq 0 ]; then
        ok "Базу даних '${DB_NAME}' та користувача '${DB_USER}' створено"
    else
        fail "Помилка створення бази даних"
        exit 1
    fi

    # Застосування схеми
    info "Застосування схеми (schema.sql)..."
    if $MYSQL_ROOT "$DB_NAME" < "${SCRIPT_DIR}/database/schema.sql" 2>/dev/null; then
        ok "Схему застосовано"
    else
        fail "Помилка застосування схеми"
        exit 1
    fi

    # Застосування seed-даних
    TABLE_COUNT=$($MYSQL_ROOT -N -B -e "SELECT COUNT(*) FROM ${DB_NAME}.roles;" 2>/dev/null || echo 0)
    if [ "${TABLE_COUNT:-0}" -gt 0 ]; then
        warn "Довідники вже заповнені — seed.sql пропущено (щоб уникнути дублювання)"
    else
        info "Заповнення довідників (seed.sql)..."
        if $MYSQL_ROOT "$DB_NAME" < "${SCRIPT_DIR}/database/seed.sql" 2>/dev/null; then
            ok "Довідники та тестовий адміністратор створені"
        else
            fail "Помилка заповнення довідників"
        fi
    fi

    # ---------- 11. Генерація файлу оточення ----------
    section "11. Створення файлу конфігурації оточення"

    ENV_FILE="${SCRIPT_DIR}/.env"
    if [ -f "$ENV_FILE" ]; then
        warn "Файл .env вже існує — не перезаписую (збережено як .env.new)"
        ENV_FILE="${SCRIPT_DIR}/.env.new"
    fi

    cat > "$ENV_FILE" <<ENV
# Згенеровано install.sh $(date '+%Y-%m-%d %H:%M:%S')
# УВАГА: цей файл містить пароль БД — не додавайте його в git (він у .gitignore)
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=${DB_NAME}
DB_USERNAME=${DB_USER}
DB_PASSWORD=${DB_PASS}
APP_ENV=production
APP_URL=http://localhost
ENV

    chmod 600 "$ENV_FILE"
    ok "Створено ${ENV_FILE##*/} (права 600)"
fi

# ---------- 12. Налаштування прав доступу ----------
if [ "$SKIP_PERMS" -eq 1 ]; then
    section "12. Налаштування прав доступу — пропущено (--skip-perms)"
else
    section "12. Налаштування прав доступу"

    if [ -z "$WEB_USER" ]; then
        warn "Користувача веб-сервера не визначено — права доступу залишено без змін"
        info "Задайте вручну і повторіть: sudo ./install.sh --web-user=ІМ_'Я --skip-db"
        info "Або виставте права самостійно, орієнтуючись на README.md"
    elif [ "$IS_ROOT" -ne 1 ]; then
        warn "Потрібні права root, щоб змінити власника файлів — пропущено"
        info "Виконайте вручну під root:"
        info "  sudo chown -R ${WEB_USER}:${WEB_GROUP} ${SCRIPT_DIR}"
        info "  sudo find ${SCRIPT_DIR} -type d -exec chmod 750 {} \\;"
        info "  sudo find ${SCRIPT_DIR} -type f -exec chmod 640 {} \\;"
        info "  sudo chmod 770 ${SCRIPT_DIR}/storage/uploads"
        info "  sudo chmod 600 ${SCRIPT_DIR}/.env"
    else
        info "Застосовую власника ${WEB_USER}:${WEB_GROUP} до ${SCRIPT_DIR} ..."
        if chown -R "${WEB_USER}:${WEB_GROUP}" "$SCRIPT_DIR"; then
            ok "Власника файлів змінено на ${WEB_USER}:${WEB_GROUP}"
        else
            fail "Не вдалося змінити власника файлів"
        fi

        # Базові права: директорії 750 (обхід+перелік для власника/групи),
        # файли 640 (читання для власника/групи). install.sh лишаємо виконуваним
        # окремо нижче, щоб його можна було повторно запустити вручну.
        find "$SCRIPT_DIR" -type d -exec chmod 750 {} \; 2>/dev/null
        find "$SCRIPT_DIR" -type f -exec chmod 640 {} \; 2>/dev/null
        chmod 750 "${SCRIPT_DIR}/install.sh" 2>/dev/null
        ok "Базові права застосовано (директорії 750, файли 640)"

        # storage/uploads має бути доступна на запис веб-серверу (майбутні вкладення)
        if [ -d "${SCRIPT_DIR}/storage/uploads" ]; then
            chmod -R 770 "${SCRIPT_DIR}/storage/uploads"
            ok "storage/uploads: права 770 (читання/запис для веб-сервера)"
        fi

        # .env — найчутливіший файл, лише власник має право читати
        if [ -f "${SCRIPT_DIR}/.env" ]; then
            chmod 600 "${SCRIPT_DIR}/.env"
            ok ".env: права звужено до 600"
        fi
        if [ -f "${SCRIPT_DIR}/.env.new" ]; then
            chmod 600 "${SCRIPT_DIR}/.env.new"
        fi

        # Перевірка результату: чи справді користувач веб-сервера може прочитати .env
        if [ -f "${SCRIPT_DIR}/.env" ] && command -v runuser >/dev/null 2>&1; then
            if runuser -u "$WEB_USER" -- test -r "${SCRIPT_DIR}/.env" 2>/dev/null; then
                ok "Перевірка: ${WEB_USER} може прочитати .env"
            else
                fail "Перевірка: ${WEB_USER} НЕ може прочитати .env — перевірте права вручну"
            fi
        elif [ -f "${SCRIPT_DIR}/.env" ] && command -v sudo >/dev/null 2>&1; then
            if sudo -u "$WEB_USER" test -r "${SCRIPT_DIR}/.env" 2>/dev/null; then
                ok "Перевірка: ${WEB_USER} може прочитати .env"
            else
                warn "Не вдалося перевірити доступ ${WEB_USER} до .env (sudo/runuser недоступні для перевірки)"
            fi
        fi

        # SELinux (типово для RHEL/CentOS/Rocky/Alma) — окрема система прав понад Unix-права.
        if [ "$OS_FAMILY" = "rhel" ] && command -v getenforce >/dev/null 2>&1; then
            SELINUX_STATE=$(getenforce 2>/dev/null)
            if [ "$SELINUX_STATE" = "Enforcing" ]; then
                warn "SELinux у режимі Enforcing — Unix-прав може бути недостатньо"
                info "Типово потрібно: sudo semanage fcontext -a -t httpd_sys_rw_content_t '${SCRIPT_DIR}/storage/uploads(/.*)?'"
                info "                 sudo restorecon -Rv ${SCRIPT_DIR}/storage/uploads"
            fi
        fi
    fi
fi


section "Інсталяцію завершено"

cat <<FINAL

  ${BOLD}Наступні кроки:${NC}

  1. Налаштуйте веб-сервер так, щоб document root вказував на:
     ${SCRIPT_DIR}/public

  2. Файл .env застосунок читає самостійно — додаткове передавання
     змінних оточення через SetEnv/env[] у конфізі веб-сервера НЕ потрібне.

  3. Права доступу та власника файлів скрипт уже виставив автоматично
     (розділ 12 вище). Якщо там були помилки/попередження — виправте
     вручну командами, які скрипт підказав у тому розділі.

  ${BOLD}Локальний запуск для розробки (без веб-сервера):${NC}
     cd ${SCRIPT_DIR}
     set -a; source .env; set +a
     php -S 127.0.0.1:8000 -t public

  ${BOLD}Тестовий обліковий запис:${NC}
     admin@example.local / admin123
     ${YELLOW}Обов'язково змініть пароль після першого входу!${NC}

FINAL

exit 0
