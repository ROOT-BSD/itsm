# shellcheck shell=bash
# Крок 5: права доступу до файлів для веб-сервера.
# Підключається (source) з update.sh — не запускайте окремо. Змінні й функції (ok/fail/warn/info/section, MYSQL…) задають lib.sh та 10-environment.sh.

section "5. Права доступу до файлів"

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

# storage/wiki: текст вікі пише веб-сервер
if [ -d "${SCRIPT_DIR}/storage/wiki" ]; then
    find "${SCRIPT_DIR}/storage/wiki" -type d -exec chmod 770 {} \; 2>/dev/null
    find "${SCRIPT_DIR}/storage/wiki" -type f -exec chmod 660 {} \; 2>/dev/null
    ok "storage/wiki: доступна для запису веб-серверу"
fi

# storage/backups: архіви резервних копій (БД + файли) створює веб-сервер або bin/backup.php; поза веб-коренем, лише власник і група
mkdir -p "${SCRIPT_DIR}/storage/backups" 2>/dev/null
if [ -d "${SCRIPT_DIR}/storage/backups" ]; then
    find "${SCRIPT_DIR}/storage/backups" -type d -exec chmod 770 {} \; 2>/dev/null
    find "${SCRIPT_DIR}/storage/backups" -type f -exec chmod 660 {} \; 2>/dev/null
    ok "storage/backups: доступна для запису веб-серверу (резервні копії з адмін-панелі)"
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
