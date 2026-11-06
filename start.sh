#!/usr/bin/env bash
set -e

DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
for command in php; do
  command -v "$command" >/dev/null 2>&1 || { echo "Missing required command: $command" >&2; exit 1; }
done
if [ ! -x "$DIR/venv/bin/python3" ]; then
  echo "Missing Python environment: $DIR/venv/bin/python3. Create it with python3 -m venv venv and install requirements.lock.txt." >&2
  exit 1
fi
if [ ! -f "$DIR/laravel-app/vendor/autoload.php" ]; then
  echo "Missing PHP dependencies: run composer install inside laravel-app." >&2
  exit 1
fi
cd "$DIR/laravel-app"

echo "=================================================="
echo "🚀 Starting AI Reel Studio (Laravel 13 + Python)"
echo "=================================================="
echo "Local Server URL: http://127.0.0.1:8000"
echo "Queue worker:    running in the background"
echo "Press Ctrl+C to stop the server and the worker."
echo "=================================================="

php artisan migrate --force >/dev/null

# Renders run in a queue worker so web requests stay responsive.
php artisan queue:work --tries=2 --timeout=1200 --sleep=1 &
WORKER_PID=$!
cleanup() {
  kill "$WORKER_PID" >/dev/null 2>&1 || true
  wait "$WORKER_PID" 2>/dev/null || true
}
trap cleanup EXIT INT TERM

PHP_CLI_SERVER_WORKERS="${PHP_CLI_SERVER_WORKERS:-4}" php artisan serve --port=8000
