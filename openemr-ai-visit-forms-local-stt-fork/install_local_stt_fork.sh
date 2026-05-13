#!/usr/bin/env bash
set -euo pipefail

OPENEMR_ROOT="${1:-/var/www/html/clinic}"
PACKAGE_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TARGET="$OPENEMR_ROOT/interface/forms/ai_visit_forms"

if [[ ! -d "$OPENEMR_ROOT/interface/forms" ]]; then
  echo "OpenEMR root not found or invalid: $OPENEMR_ROOT" >&2
  exit 1
fi

STAMP="$(date +%Y%m%d-%H%M%S)"
BACKUP_DIR="$PACKAGE_ROOT/backups/$STAMP"
mkdir -p "$BACKUP_DIR"

if [[ -d "$TARGET" ]]; then
  cp -a "$TARGET" "$BACKUP_DIR/ai_visit_forms"
fi
if [[ -f "$OPENEMR_ROOT/library/globals.inc.php" ]]; then
  cp -a "$OPENEMR_ROOT/library/globals.inc.php" "$BACKUP_DIR/globals.inc.php"
fi

mkdir -p "$TARGET"
cp -a "$PACKAGE_ROOT/files/ai_visit_forms/." "$TARGET/"

php "$PACKAGE_ROOT/scripts/ensure_voice_dictation_globals.php" "$OPENEMR_ROOT"
php "$PACKAGE_ROOT/scripts/patch_openemr5_local_stt_globals.php" "$OPENEMR_ROOT"
php "$TARGET/install_db.php"
php "$PACKAGE_ROOT/scripts/apply_local_stt_globals.php" "$OPENEMR_ROOT"

find "$TARGET" -maxdepth 1 -type f -name '*.php' -print0 | xargs -0 -n1 php -l >/dev/null
php -l "$OPENEMR_ROOT/library/globals.inc.php" >/dev/null
if command -v node >/dev/null 2>&1; then
  node --check "$TARGET/dictation.js" >/dev/null
  node --check "$TARGET/report_letter.js" >/dev/null
fi

echo "Installed local-STT Advance Visit Form into $TARGET"
echo "Backup saved in $BACKUP_DIR"
