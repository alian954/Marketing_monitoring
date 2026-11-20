<?php

namespace Tests\Feature;

use App\Jobs\RecordQueueWorkerHeartbeat;
use App\Models\SchedulerHeartbeat;
use App\Models\User;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class QueueWorkerHeartbeatTest extends TestCase
{
    use RefreshDatabase;

    public function test_worker_heartbeat_is_scheduled_each_minute_and_unique_while_queued(): void
    {
        Queue::fake();

        $event = collect(app(Schedule::class)->events())->first(
            fn ($event): bool => $event->getSummaryForDisplay() === RecordQueueWorkerHeartbeat::class,
        );

        $this->assertInstanceOf(CallbackEvent::class, $event);
        $this->assertSame('* * * * *', $event->expression);

        $event->run(app());
        $event->run(app());

        Queue::assertPushed(RecordQueueWorkerHeartbeat::class, 1);
    }

    public function test_worker_heartbeat_job_refreshes_the_success_timestamp_and_clears_old_errors(): void
    {
        $heartbeat = SchedulerHeartbeat::factory()->create([
            'job_key' => 'monitor-worker',
            'last_started_at' => now()->subMinutes(10),
            'last_succeeded_at' => now()->subMinutes(10),
            'last_error' => 'previous error',
        ]);

        (new RecordQueueWorkerHeartbeat)->handle();

        $heartbeat->refresh();

        $this->assertTrue($heartbeat->last_started_at->isToday());
        $this->assertTrue($heartbeat->last_succeeded_at->isToday());
        $this->assertNull($heartbeat->last_error);
        $this->assertTrue($heartbeat->last_succeeded_at->greaterThan(now()->subMinute()));
    }

    public function test_dashboard_reports_fresh_and_stale_queue_worker_heartbeats(): void
    {
        Config::set('queue.default', 'database');
        SchedulerHeartbeat::factory()->create([
            'job_key' => 'monitor-scheduler',
            'last_succeeded_at' => now(),
        ]);
        $workerHeartbeat = SchedulerHeartbeat::factory()->create([
            'job_key' => 'monitor-worker',
            'last_succeeded_at' => now(),
        ]);

        $freshResponse = $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Queue worker')
            ->assertSee('目前佇列工作總數：0 筆')
            ->assertSee('最近成功處理佇列工作的時間')
            ->assertDontSee('佇列是否塞住');

        $this->assertTrue($freshResponse->viewData('queueWorkerEnabled'));
        $this->assertTrue($freshResponse->viewData('workerHeartbeatFresh'));
        $this->assertSame(0, $freshResponse->viewData('queueSize'));

        $workerHeartbeat->update(['last_succeeded_at' => now()->subMinutes(3)]);

        $staleResponse = $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('queue worker 是否停止或佇列是否塞住');

        $this->assertFalse($staleResponse->viewData('workerHeartbeatFresh'));
    }

    public function test_dashboard_counts_only_jobs_on_the_default_queue(): void
    {
        Config::set('queue.default', 'database');
        Queue::push(new RecordQueueWorkerHeartbeat);
        Queue::pushOn('emails', new RecordQueueWorkerHeartbeat);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('目前佇列工作總數：1 筆');

        $this->assertSame(1, $response->viewData('queueSize'));
    }

    public function test_dashboard_breaks_default_queue_jobs_down_by_state(): void
    {
        Config::set('queue.default', 'database');
        Queue::push(new RecordQueueWorkerHeartbeat);
        Queue::later(3600, new RecordQueueWorkerHeartbeat);
        $reservedJobId = Queue::push(new RecordQueueWorkerHeartbeat);
        DB::table('jobs')->where('id', $reservedJobId)->update(['reserved_at' => now()->timestamp]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('目前佇列工作總數：3 筆')
            ->assertSee('可立即處理 1 筆、延遲等待 1 筆、保留中 1 筆。');

        $this->assertSame([
            'pending' => 1,
            'delayed' => 1,
            'reserved' => 1,
        ], $response->viewData('queueStateCounts'));
    }

    public function test_dashboard_counts_failed_jobs_when_database_storage_is_configured(): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => 'failed-job-uuid',
            'connection' => 'database',
            'queue' => 'default',
            'payload' => '{}',
            'exception' => 'RuntimeException',
            'failed_at' => now(),
        ]);

        $user = User::factory()->create();

        foreach (['database', 'database-uuids'] as $driver) {
            Config::set('queue.failed.driver', $driver);

            $response = $this->actingAs($user)
                ->get(route('dashboard'))
                ->assertOk()
                ->assertSee('目前記錄 1 件失敗工作。');

            $this->assertTrue($response->viewData('failedQueueJobStorageSupported'));
            $this->assertSame(1, $response->viewData('failedQueueJobCount'));
        }
    }

    public function test_dashboard_hides_failed_job_count_when_storage_is_not_database(): void
    {
        Config::set('queue.failed.driver', 'null');

        $response = $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('失敗佇列工作');

        $this->assertFalse($response->viewData('failedQueueJobStorageSupported'));
        $this->assertNull($response->viewData('failedQueueJobCount'));
    }

    public function test_dashboard_stays_available_when_failed_jobs_cannot_be_read(): void
    {
        Config::set('queue.failed.driver', 'database-uuids');
        Config::set('queue.failed.table', 'missing_failed_jobs');

        $response = $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('目前無法取得失敗佇列工作數。');

        $this->assertTrue($response->viewData('failedQueueJobStorageSupported'));
        $this->assertNull($response->viewData('failedQueueJobCount'));
    }

    public function test_dashboard_remains_available_when_queue_size_cannot_be_read(): void
    {
        Config::set('queue.default', 'database');
        Queue::shouldReceive('size')
            ->once()
            ->andThrow(new RuntimeException('Queue backend unavailable'));

        $response = $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('目前無法取得佇列工作數。');

        $this->assertNull($response->viewData('queueSize'));
    }

    public function test_dashboard_explains_that_sync_queue_has_no_separate_worker(): void
    {
        Config::set('queue.default', 'sync');

        $response = $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('目前使用 sync 同步佇列，工作由目前程序直接執行，沒有獨立 worker 心跳。')
            ->assertDontSee('目前佇列工作總數')
            ->assertDontSee('尚未收到 worker 心跳');

        $this->assertFalse($response->viewData('queueWorkerEnabled'));
        $this->assertNull($response->viewData('queueSize'));
    }
}
