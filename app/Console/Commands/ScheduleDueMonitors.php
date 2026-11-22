<?php

namespace App\Console\Commands;

use App\Jobs\SendNotificationOutbox;
use App\Models\CheckRun;
use App\Models\Monitor;
use App\Models\NotificationOutbox;
use App\Models\SchedulerHeartbeat;
use App\Services\IncidentManager;
use App\Services\RunScheduler;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

#[Signature('monitors:dispatch-due')]
#[Description('排入到期檢查、監測資料逾時事件與通知')]
class ScheduleDueMonitors extends Command
{
    public function handle(RunScheduler $scheduler, IncidentManager $incidents): int
    {
        $heartbeat = SchedulerHeartbeat::query()->firstOrCreate(['job_key' => 'monitor-scheduler']);
        $heartbeat->forceFill(['last_started_at' => now(), 'last_error' => null])->save();

        try {
            $queued = 0;
            $dcardCheckInFlight = CheckRun::query()
                ->whereIn('status', ['queued', 'running'])
                ->whereHas('monitor', static fn (Builder $query): Builder => $query->where('social_platform', 'dcard'))
                ->exists();
            $dueMonitorsQuery = Monitor::query()
                ->where('enabled', true)
                ->whereNotNull('next_check_at')
                ->where('next_check_at', '<=', now());

            if ($dcardCheckInFlight) {
                $dueMonitorsQuery->where(static function (Builder $query): void {
                    $query->whereNull('social_platform')
                        ->orWhere('social_platform', '!=', 'dcard');
                });
            }

            $dueMonitors = $dueMonitorsQuery
                ->orderBy('next_check_at')
                ->orderBy('id')
                ->limit(100)
                ->get();

            $dueMonitors->each(function (Monitor $monitor) use ($scheduler, &$queued, &$dcardCheckInFlight): void {
                if ($monitor->social_platform === 'dcard' && $dcardCheckInFlight) {
                    return;
                }

                $configuredJitterSeconds = max(0, (int) config('monitoring.schedule_jitter_max_seconds'));
                $intervalJitterCapSeconds = max(1, intdiv(max(1, $monitor->interval_seconds), 4));
                $maximumJitterSeconds = min($configuredJitterSeconds, $intervalJitterCapSeconds);
                $jitterSeconds = $maximumJitterSeconds === 0 ? 0 : random_int(0, $maximumJitterSeconds);

                if ($scheduler->enqueue($monitor, 'scheduled', delaySeconds: $jitterSeconds) !== null) {
                    $queued++;

                    if ($monitor->social_platform === 'dcard') {
                        $dcardCheckInFlight = true;
                    }
                }
            });

            NotificationOutbox::query()
                ->where('status', 'pending')
                ->where(function (Builder $query): void {
                    $query->whereNotIn('event_type', NotificationOutbox::SNOOZABLE_EVENT_TYPES)
                        ->orWhereDoesntHave('incident', static function (Builder $incidentQuery): void {
                            $incidentQuery->whereNotNull('snoozed_until')->where('snoozed_until', '>', now());
                        });
                })
                ->orderBy('id')
                ->limit(100)
                ->get()
                ->each(static function (NotificationOutbox $outbox): void {
                    SendNotificationOutbox::dispatch($outbox->id);
                });

            NotificationOutbox::query()
                ->where('status', 'sending')
                ->where('attempt_started_at', '<', now()->subMinutes(5))
                ->update([
                    'status' => 'unknown',
                    'last_error' => '工作者可能在寄送期間停止，結果不明；重試前請先確認收件狀態。',
                    'updated_at' => now(),
                ]);

            $staleIncidents = $incidents->queueDueStaleIncidents();
            $queuedReminders = $incidents->queueDueReminders();
            $queuedEscalations = $incidents->queueDueEscalations();
            $heartbeat->forceFill(['last_succeeded_at' => now(), 'last_error' => null])->save();
            $this->info('已將 '.$queued.' 個到期監測檢查加入佇列，新增 '.$staleIncidents.' 個監測資料逾時事件，排入 '.$queuedReminders.' 個持續事件提醒與 '.$queuedEscalations.' 個升級通知。');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            report($exception);
            $heartbeat->forceFill(['last_error' => '排程器失敗（'.class_basename($exception).'）。'])->save();
            $this->error('排程器執行失敗；心跳記錄已保存錯誤類型。');

            return self::FAILURE;
        }
    }
}
