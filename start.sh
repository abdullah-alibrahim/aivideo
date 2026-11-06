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
cd "$DIR/laravel-app"

echo "=================================================="
echo "🚀 Starting AI Reel Studio (Laravel 13 + Python)"
echo "=================================================="
echo "Local Server URL: http://127.0.0.1:8000"
echo "Press Ctrl+C to stop the server."
echo "=================================================="

php artisan serve --port=8000
