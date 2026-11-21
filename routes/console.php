<?php

use App\Jobs\RecordQueueWorkerHeartbeat;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('monitors:dispatch-due')->everyMinute()->withoutOverlapping();
Schedule::job(new RecordQueueWorkerHeartbeat)->everyMinute()->withoutOverlapping();
Schedule::command('monitors:dispatch-tls')->dailyAt('03:00')->withoutOverlapping();
Schedule::command('monitors:dispatch-domain-expiry')->dailyAt('04:00')->withoutOverlapping();
Schedule::command('monitors:dispatch-robots')->dailyAt('05:00')->withoutOverlapping();
Schedule::command('monitors:send-dns-summary')->dailyAt('06:00')->withoutOverlapping();
Schedule::command('monitors:prune')->dailyAt('02:05')->withoutOverlapping();
Schedule::command('auth:clear-resets')->everyFifteenMinutes()->withoutOverlapping();
