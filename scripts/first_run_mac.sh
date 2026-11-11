#!/usr/bin/env bash
# AI Reels Studio — macOS First Run / Fix screen.
#
# Usage:
#   first_run_mac.sh --check      Print "name<TAB>ok|fail<TAB>detail" lines and exit
#                                 with 0 when every check passes.
#   first_run_mac.sh --fix        Install missing requirements (Homebrew, PHP, Python,
#                                 Node, FFmpeg) non-interactively.
#   first_run_mac.sh              Interactive GUI mode: shows the check results and
#                                 offers "Install / Fix Automatically".
set -euo pipefail

TITLE="AI Reels Studio — First Run"
SUPPORT_ROOT="$HOME/Library/Application Support/AI Reels Studio"

checks() {
  local name ok detail
  while IFS=$'\t' read -r name ok detail; do
    printf '%s\t%s\t%s\n' "$name" "$ok" "$detail"
  done <<EOF
$(for c in php composer node npm python3 ffmpeg; do
    if command -v "$c" >/dev/null 2>&1; then
      printf '%s\t%s\t%s\n' "$c" ok "$($c --version 2>/dev/null | head -1 || echo found)"
    else
      printf '%s\t%s\t%s\n' "$c" fail "missing"
    fi
  done)
EOF
}

all_passed() {
  ! checks | grep -q $'\tfail\t'
}

fix_requirements() {
  if ! command -v brew >/dev/null 2>&1; then
    echo "Installing Homebrew..." >&2
    NONINTERACTIVE=1 /bin/bash -c "$(curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh)" >&2 || {
      echo "Homebrew installation failed." >&2
      return 1
    }
    for brew_path in /opt/homebrew/bin/brew /usr/local/bin/brew; do
      [ -x "$brew_path" ] && eval "$("$brew_path" shellenv)" && break
    done
  fi
  for package in php python node ffmpeg; do
    if ! command -v "$package" >/dev/null 2>&1; then
      echo "Installing $package via Homebrew..." >&2
      brew install "$package" >&2 || echo "Failed to install $package; install it manually." >&2
    fi
  done
  mkdir -p "$SUPPORT_ROOT/data"
  echo "Fix run complete." >&2
}

gui() {
  if ! command -v osascript >/dev/null 2>&1; then
    exec "$0" --check
  fi

  osascript <<'APPLESCRIPT' || exit 0
display dialog "AI Reels Studio will install its local components in your user library. This may take several minutes and requires an internet connection for the first setup." with title "AI Reels Studio — First Run" buttons {"Cancel", "Continue"} default button "Continue" with icon note
APPLESCRIPT

  if ! all_passed; then
    summary=$(checks | awk -F '\t' '$2 == "fail" { printf "• %s (%s)\\n", $1, $3 }')
    choice=$(osascript -e "display dialog \"These requirements are missing or incomplete:\\n$summary\\n\\nInstall them automatically now? (Homebrew, PHP, Python, Node, FFmpeg)\" with title \"$TITLE\" buttons {\"Skip\", \"Install / Fix Automatically\"} default button \"Install / Fix Automatically\" with icon caution" \
      -e 'button returned of result' 2>/dev/null || echo "Skip")

    if [ "$choice" = "Install / Fix Automatically" ]; then
      osascript <<'APPLESCRIPT' || true
display dialog "A Terminal window will open to install the missing requirements. Wait for it to finish, then AI Reels Studio continues automatically." with title "AI Reels Studio — Installing Requirements" buttons {"OK"} default button "OK" with icon note
APPLESCRIPT
      script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
      "$script_dir/fix_requirements.command" "$script_dir" || true
      if ! all_passed; then
        osascript -e "display dialog \"Some requirements are still missing. Install them manually, then open AI Reels Studio again.\" with title \"$TITLE\" buttons {\"Quit\"} default button \"Quit\" with icon stop"
        exit 1
      fi
    fi
  fi
}

case "${1:-}" in
  --check)
    checks
    all_passed
    ;;
  --fix)
    fix_requirements
    ;;
  "")
    gui
    ;;
  *)
    echo "Usage: first_run_mac.sh [--check|--fix]" >&2
    exit 64
    ;;
esac
