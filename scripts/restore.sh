#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
APP="$ROOT/laravel-app"
BACKUP="${1:?Usage: scripts/restore.sh /path/to/backup}"
[ -f "$BACKUP/database.sqlite" ] || { echo "Missing database.sqlite" >&2; exit 1; }
read -r -p "Restore will replace the local database and media. Type RESTORE to continue: " answer
[ "$answer" = "RESTORE" ] || { echo "Cancelled"; exit 1; }

if [ -n "${AIVIDEO_DATA_DIR:-}" ]; then
  DATA_DIR="$AIVIDEO_DATA_DIR"
else
  case "$(uname -s)" in
    Darwin) DATA_DIR="$HOME/Library/Application Support/AI Reels Studio" ;;
    MINGW*|MSYS*|CYGWIN*) DATA_DIR="${APPDATA:-$HOME/AppData/Roaming}/AI Reels Studio" ;;
    *) DATA_DIR="${XDG_DATA_HOME:-$HOME/.local/share}/AI Reels Studio" ;;
  esac
fi

mkdir -p "$DATA_DIR/database" "$DATA_DIR/uploads" "$DATA_DIR/videos"
cp "$BACKUP/database.sqlite" "$DATA_DIR/database/database.sqlite"
[ -f "$BACKUP/media.tar.gz" ] && tar -xzf "$BACKUP/media.tar.gz" -C "$DATA_DIR"
[ -f "$BACKUP/media-legacy.tar.gz" ] && tar -xzf "$BACKUP/media-legacy.tar.gz" -C "$DATA_DIR/uploads"
[ -f "$BACKUP/reels.tar.gz" ] && tar -xzf "$BACKUP/reels.tar.gz" -C "$DATA_DIR/videos"
[ -f "$BACKUP/derived-media.tar.gz" ] && tar -xzf "$BACKUP/derived-media.tar.gz" -C "$DATA_DIR/videos"
chmod 600 "$DATA_DIR/database/database.sqlite"
(cd "$APP" && php artisan optimize:clear)
echo "Restore completed into $DATA_DIR. Verify APP_KEY before opening the application."
