<?php

namespace App\Jobs;

use App\Models\SchedulerHeartbeat;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RecordQueueWorkerHeartbeat implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 120;

    public function handle(): void
    {
        $now = now();

        SchedulerHeartbeat::query()->updateOrCreate(
            ['job_key' => 'monitor-worker'],
            [
                'last_started_at' => $now,
                'last_succeeded_at' => $now,
                'last_error' => null,
            ],
        );
    }

    public function uniqueId(): string
    {
        return 'monitor-worker';
    }
}
