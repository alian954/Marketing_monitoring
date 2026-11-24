<?php

namespace Tests\Feature;

use App\Jobs\RecordQueueWorkerHeartbeat;
use App\Models\CheckRun;
use App\Models\Monitor;
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

        $freshResponse = $this->actingAs($this->createPlatformAdmin())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Queue worker')
            ->assertSee('目前佇列工作總數：0 筆')
            ->assertSee('到期排隊 0 筆')
            ->assertSee('最近成功處理佇列工作的時間')
            ->assertDontSee('佇列是否塞住');

        $this->assertTrue($freshResponse->viewData('queueWorkerEnabled'));
        $this->assertTrue($freshResponse->viewData('workerHeartbeatFresh'));
        $this->assertSame('healthy', $freshResponse->viewData('heartbeatStatus'));
        $this->assertSame(0, $freshResponse->viewData('queueSize'));
        $this->assertSame([
            'due_queued_count' => 0,
            'oldest_due_wait_seconds' => null,
            'queued_warning' => false,
            'running_count' => 0,
            'longest_running_seconds' => null,
            'running_warning' => false,
        ], $freshResponse->viewData('checkRunQueueHealth'));

        $workerHeartbeat->update(['last_succeeded_at' => now()->subMinutes(3)]);

        $staleResponse = $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('queue worker 是否停止或佇列是否塞住');

        $this->assertFalse($staleResponse->viewData('workerHeartbeatFresh'));
        $this->assertSame('healthy', $staleResponse->viewData('heartbeatStatus'));
    }

    public function test_dashboard_prioritizes_stalled_monitoring_services_over_an_individual_monitor_alert(): void
    {
        $this->travelTo(now()->startOfMinute());
        Config::set('queue.default', 'database');

        $monitor = Monitor::factory()->create(['name' => '個別網站異常']);
        $monitor->forceFill(['last_checked_at' => now()])->save();
        CheckRun::factory()->for($monitor)->create([
            'config_version' => $monitor->config_version,
            'status' => 'completed',
            'normal_status' => 503,
            'error_type' => 'normal_http_status',
            'scheduled_at' => now(),
        ]);
        CheckRun::factory()->for($monitor)->create([
            'status' => 'queued',
            'scheduled_at' => now()->subMinutes(5),
            'started_at' => null,
            'finished_at' => null,
        ]);
        SchedulerHeartbeat::factory()->create([
            'job_key' => 'monitor-scheduler',
            'last_succeeded_at' => now()->subMinutes(5),
        ]);
        SchedulerHeartbeat::factory()->create([
            'job_key' => 'monitor-worker',
            'last_succeeded_at' => now()->subMinutes(5),
        ]);

        $response = $this->actingAs($this->createPlatformAdmin())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('排程器未正常回報')
            ->assertSee('監測資料更新可能延遲')
            ->assertSee('#dashboard-runtime-details');

        $responseContent = $response->getContent();
        $overviewStart = strpos($responseContent, 'id="dashboard-overview"');
        $detailsStart = strpos($responseContent, 'id="dashboard-monitor-summary"');
        $overviewMarkup = substr($responseContent, $overviewStart, $detailsStart - $overviewStart);

        $this->assertSame('排程器未正常回報', $response->viewData('dashboardSystemAttention')['title']);
        $this->assertSame(1, $response->viewData('counts')['issue']);
        $this->assertStringContainsString('個別網站異常', $overviewMarkup);
        $this->assertStringContainsString('監測資料更新可能延遲', $overviewMarkup);
        $this->assertStringNotContainsString('排程器未正常回報', $overviewMarkup);
        $this->assertStringContainsString('Queue worker', $response->getContent());
    }

    public function test_dashboard_keeps_individual_monitor_focus_when_monitoring_services_are_fresh(): void
    {
        SchedulerHeartbeat::factory()->create([
            'job_key' => 'monitor-scheduler',
            'last_succeeded_at' => now(),
        ]);
        SchedulerHeartbeat::factory()->create([
            'job_key' => 'monitor-worker',
            'last_succeeded_at' => now(),
        ]);
        $monitor = Monitor::factory()->create(['name' => '個別網站異常']);
        $monitor->forceFill(['last_checked_at' => now()])->save();
        CheckRun::factory()->for($monitor)->create([
            'config_version' => $monitor->config_version,
            'normal_status' => 503,
            'error_type' => 'normal_http_status',
            'scheduled_at' => now(),
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('個別網站異常')
            ->assertDontSee('Queue worker')
            ->assertDontSee('系統檢查資訊')
            ->assertDontSee('系統待處理');

        $this->assertNull($response->viewData('dashboardSystemAttention'));
    }

    public function test_dashboard_prioritizes_a_stale_queue_worker_when_the_scheduler_is_fresh(): void
    {
        Config::set('queue.default', 'database');
        Monitor::factory()->create();
        SchedulerHeartbeat::factory()->create([
            'job_key' => 'monitor-scheduler',
            'last_succeeded_at' => now(),
        ]);
        SchedulerHeartbeat::factory()->create([
            'job_key' => 'monitor-worker',
            'last_succeeded_at' => now()->subMinutes(5),
        ]);

        $response = $this->actingAs($this->createPlatformAdmin())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Queue worker 未正常回報');

        $this->assertSame('Queue worker 未正常回報', $response->viewData('dashboardSystemAttention')['title']);
    }

    public function test_dashboard_prioritizes_a_recent_scheduler_failure(): void
    {
        Monitor::factory()->create();
        SchedulerHeartbeat::factory()->create([
            'job_key' => 'monitor-scheduler',
            'last_succeeded_at' => now(),
            'last_error' => '排程器最近執行失敗。',
        ]);

        $response = $this->actingAs($this->createPlatformAdmin())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('排程器最近執行失敗');

        $this->assertSame('排程器最近執行失敗', $response->viewData('dashboardSystemAttention')['title']);
    }

    public function test_dashboard_marks_a_recent_scheduler_failure_as_an_issue_despite_a_fresh_success(): void
    {
        Monitor::factory()->create();
        SchedulerHeartbeat::factory()->create([
            'job_key' => 'monitor-scheduler',
            'last_started_at' => now(),
            'last_succeeded_at' => now()->subMinute(),
            'last_error' => '排程器失敗（RuntimeException）。',
        ]);

        $response = $this->actingAs($this->createPlatformAdmin())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('badge-issue')
            ->assertSee('排程器失敗（RuntimeException）。');

        $this->assertTrue($response->viewData('heartbeatFresh'));
        $this->assertSame('issue', $response->viewData('heartbeatStatus'));
        $this->assertSame('排程器最近執行失敗', $response->viewData('dashboardSystemAttention')['title']);
    }

    public function test_dashboard_counts_only_jobs_on_the_default_queue(): void
    {
        Config::set('queue.default', 'database');
        Queue::push(new RecordQueueWorkerHeartbeat);
        Queue::pushOn('emails', new RecordQueueWorkerHeartbeat);

        $response = $this->actingAs($this->createPlatformAdmin())
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

        $response = $this->actingAs($this->createPlatformAdmin())
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

    public function test_dashboard_reports_overdue_and_long_running_monitor_checks(): void
    {
        $this->travelTo(now()->startOfMinute());
        Config::set('queue.default', 'database');
        $monitor = Monitor::factory()->create();
        CheckRun::factory()->for($monitor)->create([
            'status' => 'queued',
            'scheduled_at' => now()->subMinutes(3),
            'started_at' => null,
            'finished_at' => null,
        ]);
        CheckRun::factory()->for($monitor)->create([
            'status' => 'queued',
            'scheduled_at' => now()->subSeconds(30),
            'started_at' => null,
            'finished_at' => null,
        ]);
        CheckRun::factory()->for($monitor)->create([
            'status' => 'queued',
            'scheduled_at' => now()->addMinutes(10),
            'started_at' => null,
            'finished_at' => null,
        ]);
        CheckRun::factory()->for($monitor)->create([
            'status' => 'running',
            'scheduled_at' => now()->subMinutes(4),
            'started_at' => now()->subMinutes(4),
            'finished_at' => null,
        ]);
        CheckRun::factory()->for($monitor)->create([
            'status' => 'running',
            'scheduled_at' => now()->subSeconds(15),
            'started_at' => now()->subSeconds(15),
            'finished_at' => null,
        ]);

        $response = $this->actingAs($this->createPlatformAdmin())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('監測檢查正在排隊')
            ->assertSee('到期排隊 2 筆')
            ->assertSee('最久等待 3.0 分鐘')
            ->assertSee('執行中 2 筆')
            ->assertSee('最久執行 4.0 分鐘')
            ->assertSee('有到期檢查已等待超過 2 分鐘')
            ->assertSee('有檢查已執行超過 3 分鐘');

        $this->assertSame([
            'due_queued_count' => 2,
            'oldest_due_wait_seconds' => 180,
            'queued_warning' => true,
            'running_count' => 2,
            'longest_running_seconds' => 240,
            'running_warning' => true,
        ], $response->viewData('checkRunQueueHealth'));
        $this->assertSame('監測檢查正在排隊', $response->viewData('dashboardSystemAttention')['title']);
    }

    public function test_dashboard_warns_when_a_monitor_check_runs_too_long_without_other_service_alerts(): void
    {
        $this->travelTo(now()->startOfMinute());
        Config::set('queue.default', 'database');
        $monitor = Monitor::factory()->create();
        CheckRun::factory()->for($monitor)->create([
            'status' => 'running',
            'scheduled_at' => now()->subMinutes(4),
            'started_at' => now()->subMinutes(4),
            'finished_at' => null,
        ]);
        SchedulerHeartbeat::factory()->create([
            'job_key' => 'monitor-scheduler',
            'last_succeeded_at' => now(),
        ]);
        SchedulerHeartbeat::factory()->create([
            'job_key' => 'monitor-worker',
            'last_succeeded_at' => now(),
        ]);

        $response = $this->actingAs($this->createPlatformAdmin())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('監測檢查執行過久');

        $this->assertSame('監測檢查執行過久', $response->viewData('dashboardSystemAttention')['title']);
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

        $user = $this->createPlatformAdmin();

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

        $response = $this->actingAs($this->createPlatformAdmin())
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

        $response = $this->actingAs($this->createPlatformAdmin())
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

        $response = $this->actingAs($this->createPlatformAdmin())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('目前無法取得佇列工作數。');

        $this->assertNull($response->viewData('queueSize'));
    }

    public function test_dashboard_explains_that_sync_queue_has_no_separate_worker(): void
    {
        Config::set('queue.default', 'sync');

        $response = $this->actingAs($this->createPlatformAdmin())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('目前使用 sync 同步佇列，工作由目前程序直接執行，沒有獨立 worker 心跳。')
            ->assertDontSee('目前佇列工作總數')
            ->assertDontSee('尚未收到 worker 心跳');

        $this->assertFalse($response->viewData('queueWorkerEnabled'));
        $this->assertNull($response->viewData('queueSize'));
        $this->assertNull($response->viewData('checkRunQueueHealth'));
    }

    private function createPlatformAdmin(): User
    {
        $platformAdmin = User::factory()->create();
        $platformAdmin->forceFill(['is_platform_admin' => true])->save();

        return $platformAdmin;
    }
}
