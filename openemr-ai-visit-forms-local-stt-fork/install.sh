#!/usr/bin/env bash
set -euo pipefail

OPENEMR_ROOT="${1:-/var/www/html/openemr}"
PACKAGE_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TARGET="$OPENEMR_ROOT/interface/forms/ai_visit_forms"

if [[ ! -d "$OPENEMR_ROOT/interface/forms" ]]; then
  echo "OpenEMR root not found or invalid: $OPENEMR_ROOT" >&2
  exit 1
fi

install -d "$TARGET"
cp -a "$PACKAGE_ROOT/files/ai_visit_forms/." "$TARGET/"
php "$TARGET/install_db.php"
chown -R www-data:www-data "$TARGET" 2>/dev/null || true

echo "Installed AI Visit Forms into $TARGET"
