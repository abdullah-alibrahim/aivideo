#!/usr/bin/env bash
#
# Deploy AI Reels Studio to Hostinger shared hosting.
#
# Prerequisites on the Hostinger account:
#   - SSH access enabled
#   - PHP CLI available (recommended /opt/alt/php84/usr/bin/php)
#   - MySQL database + user created via hPanel
#   - FFmpeg + FFprobe installed (e.g. ~/bin/ffmpeg ~/bin/ffprobe)
#   - Composer installed (composer.phar or `composer` on PATH)
#   - Node/npm available to build frontend assets (or build locally and upload)
#
# Usage (run from the repository root, i.e. the folder containing this script):
#   APP_DIR=/home/u817201413/apps/ai-yellowspace/laravel-app \
#   PHP_BIN=/opt/alt/php84/usr/bin/php \
#   bash scripts/deploy-hostinger.sh
#
# Review and edit laravel-app/.env before running (copy from .env.hostinger.example).

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
APP="${APP_DIR:-$ROOT/laravel-app}"
PHP_BIN="${PHP_BIN:-php}"
COMPOSER="${COMPOSER:-composer}"
NPM="${NPM:-npm}"
APP_URL="${APP_URL:-https://ai-yellowspace.example.com}"

echo "==> Deploy target: $APP"
echo "==> PHP binary: $PHP_BIN"

# 1. Install PHP dependencies (no dev tools in production).
echo "==> composer install"
(cd "$APP" && "$COMPOSER" install --no-dev --optimize-autoloader --no-interaction --prefer-dist)

# 2. Build frontend assets. Option A: build on the server (requires node/npm).
if [ -f "$APP/package.json" ]; then
  echo "==> npm ci + npm run build"
  (cd "$APP" && "$NPM" ci --no-audit --no-fund && "$NPM" run build)
fi

# 3. Generate application key if missing.
echo "==> key:generate"
(cd "$APP" && "$PHP_BIN" artisan key:generate --force --no-interaction || true)

# 4. Run pending migrations.
echo "==> migrate"
(cd "$APP" && "$PHP_BIN" artisan migrate --force --no-interaction)

# 5. Seed the Free plan (idempotent).
echo "==> db:seed FreePlanSeeder"
(cd "$APP" && "$PHP_BIN" artisan db:seed --class=FreePlanSeeder --force --no-interaction)

# 6. Link public storage to the videos data root (relative symlink works on shared hosting).
echo "==> storage:link"
(cd "$APP" && "$PHP_BIN" artisan storage:link --relative --no-interaction || true)

# 7. Bootstrap/promote the first Studio administrator.
#    Provide an interactive email/password, or set STUDIO_ADMIN_EMAIL and run interactively once via SSH.
echo "==> studio:make-admin (interactive once via SSH)"
echo "    Run: cd $APP && $PHP_BIN artisan studio:make-admin {--if-missing}"

# 8. Cache config, views, and routes (route:cache works now that closures are removed).
echo "==> config:cache view:cache route:cache"
(cd "$APP" && "$PHP_BIN" artisan config:cache --no-interaction)
(cd "$APP" && "$PHP_BIN" artisan view:cache --no-interaction)
(cd "$APP" && "$PHP_BIN" artisan route:cache --no-interaction)

# 9. Verify the environment.
echo "==> studio:doctor"
(cd "$APP" && "$PHP_BIN" artisan studio:doctor || true)

echo
echo "==> Deployment artifacts applied."
echo "Remaining manual steps (see docs/deployment-hostinger.md):"
echo "  - Set MySQL credentials, SMTP mailbox, CRON_SECRET, APP_KEY, STUDIO_ADMIN_EMAIL in .env"
echo "  - Point the domain document root to $APP/public"
echo "  - Add cron: * * * * * $PHP_BIN $APP/artisan schedule:run >> /dev/null 2>&1"
echo "  - Start the queue worker loop: nohup bash scripts/queue-worker-loop.sh &"
echo "  - Add Google redirect URI: $APP_URL/auth/google/callback"
