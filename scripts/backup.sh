#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
APP="$ROOT/laravel-app"
DEST="${1:-$ROOT/backups/$(date +%Y%m%d-%H%M%S)}"
mkdir -p "$DEST"
umask 077

php "$APP/artisan" down --render="Backup in progress" --secret="backup-$(date +%s)" >/dev/null 2>&1 || true
cleanup() { php "$APP/artisan" up >/dev/null 2>&1 || true; }
trap cleanup EXIT

sqlite3 "$APP/database/database.sqlite" ".backup '$DEST/database.sqlite'"
tar -czf "$DEST/media.tar.gz" -C "$APP/storage/app" input_videos bg_music 2>/dev/null || tar -czf "$DEST/media.tar.gz" -C "$APP/storage/app" --ignore-failed-read input_videos bg_music
if [ -d "$APP/storage/app/public/reels" ]; then
  tar -czf "$DEST/reels.tar.gz" -C "$APP/storage/app/public" reels
fi
if [ -d "$APP/storage/app/public/thumbnails" ] || [ -d "$APP/storage/app/public/subtitles" ] || [ -d "$APP/storage/app/public/previews" ]; then
  tar -czf "$DEST/derived-media.tar.gz" -C "$APP/storage/app/public" --ignore-failed-read thumbnails subtitles previews
fi
cat > "$DEST/README.txt" <<EOF
AI Reel Studio backup
Created: $(date -u +%Y-%m-%dT%H:%M:%SZ)
Contains: database.sqlite, media.tar.gz, reels.tar.gz and derived-media.tar.gz when present.
Keep the Laravel APP_KEY separately in a password manager or encrypted secret store.
Do not commit this directory.
EOF
chmod 600 "$DEST"/*
echo "Backup created at $DEST"
