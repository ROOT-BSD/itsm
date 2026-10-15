# shellcheck shell=bash
# Спільні змінні й функції оновлення. Підключається з update.sh першим.

SCRIPT_DIR="${SCRIPT_DIR:?update.sh має задати SCRIPT_DIR}"
ENV_FILE="${SCRIPT_DIR}/.env"
APP_VERSION="$(cat "${SCRIPT_DIR}/VERSION" 2>/dev/null || echo "?")"
TFPDF_CACHE_DIR="${SCRIPT_DIR}/app/Vendor/tfpdf/font/unifont"

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
