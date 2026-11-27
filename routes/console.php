<?php

use App\Jobs\RecordQueueWorkerHeartbeat;
use App\Services\ProductAvailability;
use App\Services\ScheduledCommandName;
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
Schedule::command('google-analytics:sync-due')->dailyAt('06:30')->timezone('Asia/Taipei')->withoutOverlapping(120);
Schedule::command('meta-marketing:sync-due')->dailyAt('06:40')->timezone('Asia/Taipei')->withoutOverlapping(120);
Schedule::command('google-ads:sync-due')->dailyAt('06:50')->timezone('Asia/Taipei')->withoutOverlapping(120);
Schedule::command('monitors:prune')->dailyAt('02:05')->withoutOverlapping();
Schedule::command('auth:clear-resets')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('marketing-alerts:evaluate')->dailyAt('07:23')->timezone('Asia/Taipei')->withoutOverlapping(60);
Schedule::command('client-reports:send-scheduled')->dailyAt('08:41')->timezone('Asia/Taipei')->withoutOverlapping(60);
Schedule::command('billing:send-expiry-reminders')->dailyAt('09:13')->timezone('Asia/Taipei')->withoutOverlapping();

/*
 * Product-owned commands (declared in config/products.php) stop dispatching once their product is retired.
 * Central maintenance commands such as data pruning always run.
 */
foreach (Schedule::events() as $scheduledEvent) {
    $commandName = ScheduledCommandName::fromEvent($scheduledEvent);

    if ($commandName !== null) {
        $scheduledEvent->skip(static fn (): bool => ! app(ProductAvailability::class)->allowsScheduledCommand($commandName));
    }
}
