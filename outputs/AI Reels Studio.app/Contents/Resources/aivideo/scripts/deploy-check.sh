#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
APP="$ROOT/laravel-app"

command -v php >/dev/null 2>&1 || { echo "Missing php" >&2; exit 1; }
[ -f "$APP/vendor/autoload.php" ] || { echo "Run composer install in laravel-app" >&2; exit 1; }
[ -x "$ROOT/venv/bin/python3" ] || { echo "Missing $ROOT/venv/bin/python3" >&2; exit 1; }

(cd "$APP" && php artisan migrate --force && php artisan studio:doctor)
(cd "$APP" && npm run build)

echo "Deployment checks passed."
