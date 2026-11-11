#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
APP="$ROOT/laravel-app"
DEST="${1:-$ROOT/backups/$(date +%Y%m%d-%H%M%S)}"
mkdir -p "$DEST"
umask 077

# Resolve the user-data root exactly like the app (AIVIDEO_DATA_DIR wins).
if [ -n "${AIVIDEO_DATA_DIR:-}" ]; then
  DATA_DIR="$AIVIDEO_DATA_DIR"
else
  case "$(uname -s)" in
    Darwin) DATA_DIR="$HOME/Library/Application Support/AI Reels Studio" ;;
    MINGW*|MSYS*|CYGWIN*) DATA_DIR="${APPDATA:-$HOME/AppData/Roaming}/AI Reels Studio" ;;
    *) DATA_DIR="${XDG_DATA_HOME:-$HOME/.local/share}/AI Reels Studio" ;;
  esac
fi

php "$APP/artisan" down --render="Backup in progress" --secret="backup-$(date +%s)" >/dev/null 2>&1 || true
cleanup() { php "$APP/artisan" up >/dev/null 2>&1 || true; }
trap cleanup EXIT

DB="$DATA_DIR/database/database.sqlite"
if [ ! -f "$DB" ] && [ -f "$APP/database/database.sqlite" ]; then
  DB="$APP/database/database.sqlite"
fi

if command -v sqlite3 >/dev/null 2>&1 && [ -f "$DB" ]; then
  sqlite3 "$DB" ".backup '$DEST/database.sqlite'"
else
  cp "$DB" "$DEST/database.sqlite"
fi

tar -czf "$DEST/media.tar.gz" -C "$DATA_DIR" uploads videos 2>/dev/null || true
tar -czf "$DEST/media-legacy.tar.gz" \
  -C "$APP/storage/app" --ignore-failed-read input_videos bg_music 2>/dev/null || true

if [ -d "$DATA_DIR/videos/reels" ]; then
  tar -czf "$DEST/reels.tar.gz" -C "$DATA_DIR/videos" reels
fi
if [ -d "$DATA_DIR/videos/thumbnails" ] || [ -d "$DATA_DIR/videos/subtitles" ] || [ -d "$DATA_DIR/videos/previews" ]; then
  tar -czf "$DEST/derived-media.tar.gz" -C "$DATA_DIR/videos" --ignore-failed-read thumbnails subtitles previews
fi

# Provider secrets live in the OS secret store (Keychain / Credential Manager).
# The keys/ folder on disk only holds the fallback encrypted store and is
# excluded on purpose: never ship secrets inside backups.
cat > "$DEST/README.txt" <<EOF
AI Reels Studio backup
Created: $(date -u +%Y-%m-%dT%H:%M:%SZ)
Data root: $DATA_DIR
Contains: database.sqlite, media.tar.gz, media-legacy.tar.gz, reels.tar.gz and derived-media.tar.gz when present.
Excluded on purpose: keys/ (provider secrets), settings/, logs/, tmp/.
Keep the Laravel APP_KEY and provider API keys separately in a password manager or OS secret store.
Do not commit this directory.
EOF
chmod 600 "$DEST"/* 2>/dev/null || true
echo "Backup created at $DEST (data root: $DATA_DIR)"
