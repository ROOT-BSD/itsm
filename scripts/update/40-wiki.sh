# shellcheck shell=bash
# Крок 4: вікі — документація з docs/ у вікі, текст сторінок у файлах storage/wiki, обрізання історії версій.
# Підключається (source) з update.sh — не запускайте окремо. Змінні й функції (ok/fail/warn/info/section, MYSQL…) задають lib.sh та 10-environment.sh.

section "4. Вікі: документація, файли сторінок, історія версій"

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

mkdir -p "${SCRIPT_DIR}/storage/wiki" && ok "Директорія storage/wiki на місці"
if [ -f "${SCRIPT_DIR}/bin/migrate-wiki-to-files.php" ]; then
    if WIKI_OUT="$(php "${SCRIPT_DIR}/bin/migrate-wiki-to-files.php" 2>&1)"; then
        ok "$WIKI_OUT"
    else
        fail "Не вдалося перенести вікі на диск: ${WIKI_OUT}"
        info "Перевірте права на storage/wiki (запис для веб-сервера) і запустіть update.sh ще раз — текст у БД не втрачено."
        exit 1
    fi
else
    warn "bin/migrate-wiki-to-files.php не знайдено — скопіюйте всі файли з нового архіву"
fi

if [ -f "${SCRIPT_DIR}/bin/prune-wiki-revisions.php" ]; then
    if PRUNE_OUT="$(php "${SCRIPT_DIR}/bin/prune-wiki-revisions.php" 2>&1)"; then
        ok "$PRUNE_OUT"
    else
        warn "Не вдалося обрізати історію вікі: ${PRUNE_OUT}"
    fi
fi
