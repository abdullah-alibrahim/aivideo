#!/usr/bin/env python3
"""Compatibility launcher for the maintained engine in python_engine."""

from pathlib import Path
import runpy


ENGINE_PATH = Path(__file__).resolve().parent / "python_engine" / "smart_reels.py"

if not ENGINE_PATH.is_file():
    raise SystemExit(f"Video engine not found: {ENGINE_PATH}")

runpy.run_path(str(ENGINE_PATH), run_name="__main__")
