$ErrorActionPreference = 'Stop'
$Root = Split-Path -Parent $MyInvocation.MyCommand.Path
$Laravel = Join-Path $Root 'laravel-app'
$Port = if ($env:PORT) { $env:PORT } else { '8000' }

if (-not (Get-Command 'php' -ErrorAction SilentlyContinue)) {
    throw "PHP is missing from PATH. Run setup.ps1 after installing PHP 8.3+."
}
if (-not (Test-Path (Join-Path $Laravel 'vendor/autoload.php'))) {
    throw "PHP dependencies are missing. Run .\setup.ps1 first."
}
if (-not (Test-Path (Join-Path $Root 'venv/Scripts/python.exe'))) {
    throw "Python environment is missing. Run .\setup.ps1 first."
}

Set-Location $Laravel
php artisan migrate --force

$Worker = Start-Process -FilePath 'php' -ArgumentList @('artisan','queue:work','database','--tries=2','--timeout=1200','--sleep=1','--no-interaction') -WorkingDirectory $Laravel -PassThru
$Scheduler = Start-Process -FilePath 'php' -ArgumentList @('artisan','schedule:work','--no-interaction') -WorkingDirectory $Laravel -PassThru

try {
    Write-Host "AI Reel Studio: http://127.0.0.1:$Port" -ForegroundColor Cyan
    Write-Host "Queue worker and scheduler are running. Press Ctrl+C to stop." -ForegroundColor DarkGray
    php artisan serve --host=127.0.0.1 --port=$Port
}
finally {
    foreach ($Process in @($Worker, $Scheduler)) {
        if ($Process -and -not $Process.HasExited) {
            Stop-Process -Id $Process.Id -Force -ErrorAction SilentlyContinue
        }
    }
}
