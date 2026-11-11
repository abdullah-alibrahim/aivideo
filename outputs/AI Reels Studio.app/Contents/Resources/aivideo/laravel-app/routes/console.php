<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Recover renders that were interrupted by a worker restart or a server crash.
Schedule::command('reels:recover-stale')->everyFiveMinutes()->withoutOverlapping();

// Remove old failed renders and orphaned media once a day.
Schedule::command('reels:prune --days=30')->dailyAt('03:15');
