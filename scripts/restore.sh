#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
APP="$ROOT/laravel-app"
BACKUP="${1:?Usage: scripts/restore.sh /path/to/backup}"
[ -f "$BACKUP/database.sqlite" ] || { echo "Missing database.sqlite" >&2; exit 1; }
read -r -p "Restore will replace the local database and media. Type RESTORE to continue: " answer
[ "$answer" = "RESTORE" ] || { echo "Cancelled"; exit 1; }
mkdir -p "$APP/database" "$APP/storage/app" "$APP/storage/app/public"
cp "$BACKUP/database.sqlite" "$APP/database/database.sqlite"
[ -f "$BACKUP/media.tar.gz" ] && tar -xzf "$BACKUP/media.tar.gz" -C "$APP/storage/app"
[ -f "$BACKUP/reels.tar.gz" ] && tar -xzf "$BACKUP/reels.tar.gz" -C "$APP/storage/app/public"
chmod 600 "$APP/database/database.sqlite"
(cd "$APP" && php artisan optimize:clear)
echo "Restore completed. Verify APP_KEY before opening the application."
