<?php

namespace App\Console\Commands;

use App\Jobs\SendNotificationOutbox;
use App\Models\Monitor;
use App\Models\NotificationOutbox;
use App\Models\SchedulerHeartbeat;
use App\Services\RunScheduler;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('monitors:dispatch-due')]
#[Description('將到期檢查與通知 outbox 記錄加入佇列')]
class ScheduleDueMonitors extends Command
{
    public function handle(RunScheduler $scheduler): int
    {
        $heartbeat = SchedulerHeartbeat::query()->firstOrCreate(['job_key' => 'monitor-scheduler']);
        $heartbeat->forceFill(['last_started_at' => now(), 'last_error' => null])->save();

        try {
            $queued = 0;

            Monitor::query()
                ->where('enabled', true)
                ->whereNotNull('next_check_at')
                ->where('next_check_at', '<=', now())
                ->orderBy('next_check_at')
                ->orderBy('id')
                ->limit(100)
                ->get()
                ->each(function (Monitor $monitor) use ($scheduler, &$queued): void {
                    $configuredJitterSeconds = max(0, (int) config('monitoring.schedule_jitter_max_seconds'));
                    $intervalJitterCapSeconds = max(1, intdiv(max(1, $monitor->interval_seconds), 4));
                    $maximumJitterSeconds = min($configuredJitterSeconds, $intervalJitterCapSeconds);
                    $jitterSeconds = $maximumJitterSeconds === 0 ? 0 : random_int(0, $maximumJitterSeconds);

                    if ($scheduler->enqueue($monitor, 'scheduled', delaySeconds: $jitterSeconds) !== null) {
                        $queued++;
                    }
                });

            NotificationOutbox::query()
                ->where('status', 'pending')
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

            $heartbeat->forceFill(['last_succeeded_at' => now(), 'last_error' => null])->save();
            $this->info('已將 '.$queued.' 個到期監測檢查加入佇列。');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            report($exception);
            $heartbeat->forceFill(['last_error' => '排程器失敗（'.class_basename($exception).'）。'])->save();
            $this->error('排程器執行失敗；心跳記錄已保存錯誤類型。');

            return self::FAILURE;
        }
    }
}
