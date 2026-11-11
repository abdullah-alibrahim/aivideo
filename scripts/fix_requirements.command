#!/usr/bin/env bash
# Companion for first_run_mac.sh --fix: runs the installer inside a visible
# Terminal window so the user can watch progress.
set -e
SCRIPT_DIR="${1:-}"
[ -n "$SCRIPT_DIR" ] || SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
clear
echo "AI Reels Studio — installing missing requirements..."
echo
bash "$SCRIPT_DIR/first_run_mac.sh" --fix
echo
echo "Done. You can close this window."
