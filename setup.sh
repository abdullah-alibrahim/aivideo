#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LARAVEL="$ROOT/laravel-app"
VENV="$ROOT/venv"

command -v php >/dev/null 2>&1 || { echo "Missing PHP 8.3+. Install it with Homebrew or your PHP manager." >&2; exit 1; }
command -v composer >/dev/null 2>&1 || { echo "Missing Composer." >&2; exit 1; }
command -v node >/dev/null 2>&1 || { echo "Missing Node.js 20+. Install it with Homebrew or nvm." >&2; exit 1; }
command -v npm >/dev/null 2>&1 || { echo "Missing npm." >&2; exit 1; }
command -v python3 >/dev/null 2>&1 || { echo "Missing Python 3.11+." >&2; exit 1; }

cd "$ROOT"
[ -f "$LARAVEL/.env" ] || cp "$LARAVEL/.env.example" "$LARAVEL/.env"
[ -x "$VENV/bin/python3" ] || python3 -m venv "$VENV"
"$VENV/bin/python3" -m pip install --upgrade pip
"$VENV/bin/python3" -m pip install -r "$ROOT/requirements.lock.txt"

cd "$LARAVEL"
composer install --no-interaction
php artisan key:generate --ansi
php artisan migrate --force
npm ci
npm run build
php artisan storage:link
php artisan studio:doctor

echo "Setup complete. Run ./start.sh and open http://127.0.0.1:8000"
