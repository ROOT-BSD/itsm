# shellcheck shell=bash
# Крок 3: каталоги сховища, підказки щодо лімітів PHP, кеш шрифтів PDF, перевірка LibreOffice.
# Підключається (source) з update.sh — не запускайте окремо. Змінні й функції (ok/fail/warn/info/section, MYSQL…) задають lib.sh та 10-environment.sh.

section "3. Каталоги сховища, кеш шрифтів, LibreOffice"

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

if command -v soffice >/dev/null 2>&1 || command -v libreoffice >/dev/null 2>&1; then
    ok "LibreOffice знайдено — документи Word/Excel/PowerPoint переглядаються в браузері як PDF"
else
    ok "LibreOffice не встановлено — для DOCX і XLSX працює вбудований автономний конвертер (простіше оформлення); ODT/ODS/PPTX/ODP лише завантажуються"
    info "Для точнішого результату й інших форматів: sudo apt-get install -y libreoffice-writer libreoffice-calc libreoffice-impress"
fi
