#!/usr/bin/env bash
# AI Reels Studio.app launcher (generated from scripts/mac-app-launcher.sh).
#
# Layout:
#   App files:  ~/Library/Application Support/AI Reels Studio/app
#   User data:  ~/Library/Application Support/AI Reels Studio/data  (AIVIDEO_DATA_DIR)
# App updates never touch the data directory, and first-run data lives in
# ~/AI Reels Studio from previous versions is migrated automatically.
set -euo pipefail

APP_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
SOURCE="$APP_ROOT/Contents/Resources/aivideo"
SUPPORT="$HOME/Library/Application Support/AI Reels Studio"
INSTALL_DIR="$SUPPORT/app"
DATA_DIR="$SUPPORT/data"
export AIVIDEO_DATA_DIR="$DATA_DIR"
FIRST_RUN_SCRIPT="$(dirname "${BASH_SOURCE[0]}")/first_run_mac.sh"

# Fall back to the bundled copy inside the .app when the launcher template is
# not present next to the binary (packaged inside Contents/MacOS).
if [ ! -f "$FIRST_RUN_SCRIPT" ]; then
  FIRST_RUN_SCRIPT="$SOURCE/scripts/first_run_mac.sh"
fi

if [ ! -f "$INSTALL_DIR/.installed-from-ai-reels-studio-app" ]; then
  # Native First Run screen: requirements check + "Install / Fix Automatically".
  if [ -f "$FIRST_RUN_SCRIPT" ]; then
    bash "$FIRST_RUN_SCRIPT" || exit 1
  else
    osascript <<'APPLESCRIPT' || exit 0
display dialog "AI Reels Studio will install its local components in your user library. This may take several minutes and requires an internet connection for the first setup." with title "AI Reels Studio — First Run" buttons {"Cancel", "Continue"} default button "Continue" with icon note
APPLESCRIPT
  fi

  # Preserve data from a previous ~/AI Reels Studio install, then refresh the
  # app files.
  mkdir -p "$INSTALL_DIR" "$DATA_DIR"
  rsync -a "$SOURCE/" "$INSTALL_DIR/"
  touch "$INSTALL_DIR/.installed-from-ai-reels-studio-app"
fi

RUNNER="$INSTALL_DIR/.ai-reels-studio-run.command"
cat > "$RUNNER" <<RUNNER_SCRIPT
#!/usr/bin/env bash
set -e
cd "$INSTALL_DIR"
export AIVIDEO_DATA_DIR="$DATA_DIR"
if [ ! -f "laravel-app/.env" ]; then
  ./setup.sh
fi
(cd laravel-app && php artisan studio:migrate-data --no-interaction --app-root="\$HOME/AI Reels Studio" || true)
(sleep 5; open "http://127.0.0.1:\${PORT:-8000}/first-run?lang=\${AI_REELS_LANG:-en}") &
exec ./start.sh
RUNNER_SCRIPT
chmod +x "$RUNNER"

open -a Terminal "$RUNNER"
