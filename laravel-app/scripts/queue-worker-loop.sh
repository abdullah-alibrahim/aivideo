#!/usr/bin/env bash
#
# Persistent queue worker for shared hosting (Hostinger).
#
# Shared hosting does not allow systemd or supervisor, so this loop keeps a
# bounded queue worker alive. Each pass renders at most one job, then the loop
# sleeps briefly and checks again. The same schedule is also registered through
# `artisan schedule:run`; both are safe to run because the worker uses the
# database queue with row locking.
#
# Usage:
#   nohup bash scripts/queue-worker-loop.sh >> storage/logs/queue-worker.log 2>&1 &
#
# Stop it with:
#   pkill -f queue-worker-loop.sh

set -u

APP_DIR="${APP_DIR:-/home/u817201413/apps/ai-yellowspace/laravel-app}"
PHP_BIN="${PHP_BIN:-/opt/alt/php84/usr/bin/php}"
MAX_JOBS="${MAX_JOBS:-1}"
WORKER_TIMEOUT="${WORKER_TIMEOUT:-1200}"
SLEEP_SECONDS="${SLEEP_SECONDS:-5}"

cd "$APP_DIR" || exit 1

mkdir -p storage/logs

echo "[$(date -u '+%Y-%m-%dT%H:%M:%SZ')] worker loop started (php=$PHP_BIN max-jobs=$MAX_JOBS timeout=$WORKER_TIMEOUT)"

while true; do
    "$PHP_BIN" artisan queue:work \
        --stop-when-empty \
        --max-jobs="$MAX_JOBS" \
        --timeout="$WORKER_TIMEOUT" \
        --tries=2 \
        --sleep=2

    sleep "$SLEEP_SECONDS"
done
