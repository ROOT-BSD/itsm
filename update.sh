#!/usr/bin/env bash
#
# ITSM System — скрипт ОНОВЛЕННЯ вже розгорнутої системи (не первинної інсталяції).
#
# Це лише «диригент»: уся робота розкладена по модулях у scripts/update/ і виконується в порядку номерів:
#
#   lib.sh              спільні змінні та функції (кольори, ok/fail/warn/info/section)
#   10-environment.sh   1. читання підключення до БД з існуючого .env, перевірка з'єднання
#   20-database.sh      2. міграції БД: php bin/migrate.php (застосовує лише відсутнє, веде облік у schema_migrations;
#                          включно з виправленням пошкодженої кирилиці — міграція 002)
#   30-directories.sh   3. каталоги storage/uploads і storage/logs, підказка про ліміти PHP, очищення кешу шрифтів PDF,
#                          перевірка LibreOffice
#   40-wiki.sh          4. завантаження гайдів з docs/ у вікі, перенесення тексту вікі в storage/wiki,
#                          обрізання історії вікі до 5 версій
#   90-permissions.sh   5. перевстановлення прав доступу на файли для веб-сервера (потрібен root)
#   99-summary.sh       підсумок і підказки
#
# Новий крок оновлення = новий файл scripts/update/NN-назва.sh (він підхопиться автоматично). Нова зміна схеми БД =
# новий файл database/migrations/NNN_назва.sql (див. database/migrations/README.md) — окремого кроку тут не потрібно.
#
# ВИКОРИСТАННЯ (на сервері, у корені проєкту, ПІСЛЯ того, як нові файли
# з архіву вже скопійовані поверх старих — .env при цьому НЕ чіпайте):
#
#   sudo bash update.sh
#
set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
MODULES_DIR="${SCRIPT_DIR}/scripts/update"

if [ ! -f "${MODULES_DIR}/lib.sh" ]; then
    echo "[FAIL] Не знайдено ${MODULES_DIR}/lib.sh" >&2
    echo "       Скопіюйте ВСІ файли з нового архіву (разом із папкою scripts/) і запустіть update.sh ще раз." >&2
    exit 1
fi

# shellcheck source=scripts/update/lib.sh
. "${MODULES_DIR}/lib.sh"

echo -e "${BOLD}=========================================="
echo -e " ITSM System v${APP_VERSION} — оновлення"
echo -e "==========================================${NC}"

# Модулі підключаються в тому самому процесі (мають спільні змінні: MYSQL, WEB_USER…); критична помилка модуля — exit 1.
for module in "${MODULES_DIR}"/[0-9][0-9]-*.sh; do
    # shellcheck source=/dev/null
    . "$module"
done

exit 0
