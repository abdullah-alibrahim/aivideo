<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Recover renders that were interrupted by a worker restart or a server crash.
// Runs every minute so a dead worker is detected within its heartbeat window.
Schedule::command('reels:recover-stale')->everyMinute()->withoutOverlapping();

// Shared hosting runs one bounded render per scheduler invocation.
$workerTimeout = (int) config('studio.engine.queue_worker_timeout_seconds', 1200);
$workerMaxTime = $workerTimeout + 120;
Schedule::command("queue:work --stop-when-empty --max-jobs=1 --timeout={$workerTimeout} --max-time={$workerMaxTime}")
    ->everyMinute()
    ->withoutOverlapping((int) ceil($workerMaxTime / 60) + 2);

// Remove old failed renders and orphaned media once a day.
Schedule::command('reels:prune --days=30')->dailyAt('03:15');
