@echo off
setlocal
cd /d "%~dp0"

echo ========================================
echo       AI Reels Studio - Windows Setup
echo ========================================
echo.

powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0setup.ps1"
if errorlevel 1 (
  echo.
  echo Setup failed. Read the error above.
  pause
  exit /b 1
)

echo.
echo Setup complete. Starting AI Reels Studio...
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0start.ps1"
if errorlevel 1 pause
