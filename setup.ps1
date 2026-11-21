$ErrorActionPreference = 'Stop'
$Root = Split-Path -Parent $MyInvocation.MyCommand.Path
$Laravel = Join-Path $Root 'laravel-app'
$Venv = Join-Path $Root 'venv'

function Require-Command([string]$Name, [string]$Hint) {
    if (-not (Get-Command $Name -ErrorAction SilentlyContinue)) {
        throw "Missing '$Name'. $Hint"
    }
}

Require-Command 'php' 'Install PHP 8.3+ and add it to PATH.'
Require-Command 'composer' 'Install Composer and add it to PATH.'
Require-Command 'node' 'Install Node.js 20+ and add it to PATH.'
Require-Command 'npm' 'Install Node.js 20+; npm is included.'

if (-not (Get-Command 'py' -ErrorAction SilentlyContinue) -and -not (Get-Command 'python' -ErrorAction SilentlyContinue)) {
    throw "Missing Python 3.11+. Install Python from python.org and enable the PATH option."
}

Set-Location $Root
if (-not (Test-Path (Join-Path $Laravel '.env'))) {
    Copy-Item (Join-Path $Laravel '.env.example') (Join-Path $Laravel '.env')
}

if (-not (Test-Path (Join-Path $Venv 'Scripts/python.exe'))) {
    if (Get-Command 'py' -ErrorAction SilentlyContinue) { py -3 -m venv $Venv } else { python -m venv $Venv }
}

& (Join-Path $Venv 'Scripts/python.exe') -m pip install --upgrade pip
& (Join-Path $Venv 'Scripts/python.exe') -m pip install -r (Join-Path $Root 'requirements.lock.txt')

Set-Location $Laravel
composer install --no-interaction
php artisan key:generate --ansi
php artisan migrate --force
npm ci
npm run build
php artisan storage:link
php artisan studio:doctor

Write-Host "Setup complete. Run .\start.ps1 and open http://127.0.0.1:8000" -ForegroundColor Green
