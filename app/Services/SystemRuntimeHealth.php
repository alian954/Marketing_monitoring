<?php

namespace App\Services;

use App\Models\CheckRun;
use App\Models\SchedulerHeartbeat;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Throwable;

/**
 * Platform-wide scheduler and queue health, shown only in the admin console.
 */
class SystemRuntimeHealth
{
    private const QUEUED_CHECK_WARNING_AFTER_SECONDS = 120;

    private const RUNNING_CHECK_WARNING_AFTER_SECONDS = 180;

    private const HEARTBEAT_FRESH_MINUTES = 2;

    /**
     * @return array{
     *     heartbeat: SchedulerHeartbeat|null,
     *     heartbeatFresh: bool,
     *     heartbeatError: string|null,
     *     heartbeatStatus: string,
     *     workerHeartbeat: SchedulerHeartbeat|null,
     *     workerHeartbeatFresh: bool,
     *     queueWorkerEnabled: bool,
     *     checkRunQueueHealth: array{due_queued_count: int, oldest_due_wait_seconds: int|null, queued_warning: bool, running_count: int, longest_running_seconds: int|null, running_warning: bool}|null,
     *     queueSize: int|null,
     *     queueStateCounts: array{pending: int, delayed: int, reserved: int}|null,
     *     failedQueueJobStorageSupported: bool,
     *     failedQueueJobCount: int|null,
     *     attention: array{title: string, description: string}|null,
     * }
     */
    public function snapshot(CarbonImmutable $asOf): array
    {
        $freshSince = $asOf->subMinutes(self::HEARTBEAT_FRESH_MINUTES);
        $heartbeat = SchedulerHeartbeat::query()->where('job_key', 'monitor-scheduler')->first();
        $heartbeatFresh = $heartbeat?->last_succeeded_at !== null && $heartbeat->last_succeeded_at->gte($freshSince);
        $heartbeatError = is_string($heartbeat?->last_error) && trim($heartbeat->last_error) !== ''
            ? trim($heartbeat->last_error)
            : null;
        $workerHeartbeat = SchedulerHeartbeat::query()->where('job_key', 'monitor-worker')->first();
        $workerHeartbeatFresh = $workerHeartbeat?->last_succeeded_at !== null && $workerHeartbeat->last_succeeded_at->gte($freshSince);
        $queueWorkerEnabled = config('queue.default') !== 'sync';
        $checkRunQueueHealth = $queueWorkerEnabled ? $this->checkRunQueueHealth($asOf) : null;
        [$queueSize, $queueStateCounts] = $queueWorkerEnabled ? $this->queueCounts() : [null, null];
        $failedQueueJobStorageSupported = in_array(config('queue.failed.driver'), ['database', 'database-uuids'], true);

        return [
            'heartbeat' => $heartbeat,
            'heartbeatFresh' => $heartbeatFresh,
            'heartbeatError' => $heartbeatError,
            'heartbeatStatus' => $heartbeatError !== null ? 'issue' : ($heartbeatFresh ? 'healthy' : 'stale'),
            'workerHeartbeat' => $workerHeartbeat,
            'workerHeartbeatFresh' => $workerHeartbeatFresh,
            'queueWorkerEnabled' => $queueWorkerEnabled,
            'checkRunQueueHealth' => $checkRunQueueHealth,
            'queueSize' => $queueSize,
            'queueStateCounts' => $queueStateCounts,
            'failedQueueJobStorageSupported' => $failedQueueJobStorageSupported,
            'failedQueueJobCount' => $failedQueueJobStorageSupported ? $this->failedQueueJobCount() : null,
            'attention' => $this->attention(
                $heartbeat,
                $heartbeatFresh,
                $heartbeatError,
                $queueWorkerEnabled,
                $workerHeartbeat,
                $workerHeartbeatFresh,
                $checkRunQueueHealth,
            ),
        ];
    }

    /**
     * @param  array{queued_warning: bool, running_warning: bool}|null  $checkRunQueueHealth
     * @return array{title: string, description: string}|null
     */
    private function attention(
        ?SchedulerHeartbeat $heartbeat,
        bool $heartbeatFresh,
        ?string $heartbeatError,
        bool $queueWorkerEnabled,
        ?SchedulerHeartbeat $workerHeartbeat,
        bool $workerHeartbeatFresh,
        ?array $checkRunQueueHealth,
    ): ?array {
        if ($heartbeatError !== null) {
            return ['title' => '排程器最近執行失敗', 'description' => '監測排程可能未正常執行，請查看服務狀態與最近錯誤。'];
        }

        if ($heartbeat !== null && ! $heartbeatFresh) {
            return ['title' => '排程器未正常回報', 'description' => '監測項目可能尚未依排程更新，請確認排程服務。'];
        }

        if ($queueWorkerEnabled && $workerHeartbeat !== null && ! $workerHeartbeatFresh) {
            return ['title' => 'Queue worker 未正常回報', 'description' => '排入佇列的監測工作可能無法執行，請確認 worker 服務。'];
        }

        if ($checkRunQueueHealth['queued_warning'] ?? false) {
            return ['title' => '監測檢查正在排隊', 'description' => '到期檢查等待超過 2 分鐘，請確認佇列服務。'];
        }

        if ($checkRunQueueHealth['running_warning'] ?? false) {
            return ['title' => '監測檢查執行過久', 'description' => '有檢查執行超過 3 分鐘，請查看服務狀態。'];
        }

        return null;
    }

    /**
     * @return array{0: int|null, 1: array{pending: int, delayed: int, reserved: int}|null}
     */
    private function queueCounts(): array
    {
        $queueSize = null;
        $queueStateCounts = null;

        try {
            $queueSize = Queue::size();
        } catch (Throwable $exception) {
            report($exception);
        }

        try {
            $queueConnection = Queue::connection();

            if (method_exists($queueConnection, 'pendingSize')
                && method_exists($queueConnection, 'delayedSize')
                && method_exists($queueConnection, 'reservedSize')) {
                $queueStateCounts = [
                    'pending' => $queueConnection->pendingSize(),
                    'delayed' => $queueConnection->delayedSize(),
                    'reserved' => $queueConnection->reservedSize(),
                ];
            }
        } catch (Throwable $exception) {
            report($exception);
        }

        return [$queueSize, $queueStateCounts];
    }

    private function failedQueueJobCount(): ?int
    {
        try {
            return DB::connection(config('queue.failed.database'))
                ->table(config('queue.failed.table'))
                ->count();
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    /**
     * Counts every workspace's check runs, so the authenticated-workspace scopes are bypassed.
     *
     * @return array{due_queued_count: int, oldest_due_wait_seconds: int|null, queued_warning: bool, running_count: int, longest_running_seconds: int|null, running_warning: bool}
     */
    private function checkRunQueueHealth(CarbonImmutable $asOf): array
    {
        $queuedSummary = CheckRun::query()
            ->withoutGlobalScopes()
            ->where('status', 'queued')
            ->where('scheduled_at', '<=', $asOf)
            ->selectRaw('COUNT(*) AS run_count, MIN(scheduled_at) AS oldest_at')
            ->first();
        $runningSummary = CheckRun::query()
            ->withoutGlobalScopes()
            ->where('status', 'running')
            ->whereNotNull('started_at')
            ->selectRaw('COUNT(*) AS run_count, MIN(started_at) AS oldest_at')
            ->first();
        $timezone = (string) config('app.timezone', 'UTC');
        $oldestDueWaitSeconds = $queuedSummary?->oldest_at === null
            ? null
            : max(0, $asOf->getTimestamp() - CarbonImmutable::parse((string) $queuedSummary->oldest_at, $timezone)->getTimestamp());
        $longestRunningSeconds = $runningSummary?->oldest_at === null
            ? null
            : max(0, $asOf->getTimestamp() - CarbonImmutable::parse((string) $runningSummary->oldest_at, $timezone)->getTimestamp());

        return [
            'due_queued_count' => (int) ($queuedSummary?->run_count ?? 0),
            'oldest_due_wait_seconds' => $oldestDueWaitSeconds,
            'queued_warning' => $oldestDueWaitSeconds !== null && $oldestDueWaitSeconds >= self::QUEUED_CHECK_WARNING_AFTER_SECONDS,
            'running_count' => (int) ($runningSummary?->run_count ?? 0),
            'longest_running_seconds' => $longestRunningSeconds,
            'running_warning' => $longestRunningSeconds !== null && $longestRunningSeconds >= self::RUNNING_CHECK_WARNING_AFTER_SECONDS,
        ];
    }
}
