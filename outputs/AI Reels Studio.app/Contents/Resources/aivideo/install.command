#!/usr/bin/env bash
set -e
ROOT="$(cd "$(dirname "$0")" && pwd)"
cd "$ROOT"

echo "========================================"
echo "      AI Reels Studio - macOS Setup"
echo "========================================"
echo

"$ROOT/setup.sh"
echo
echo "Setup complete. Starting AI Reels Studio..."
exec "$ROOT/start.sh"
