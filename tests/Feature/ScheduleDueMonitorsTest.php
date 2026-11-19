<?php

namespace Tests\Feature;

use App\Jobs\SendNotificationOutbox;
use App\Models\NotificationOutbox;
use App\Models\SchedulerHeartbeat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ScheduleDueMonitorsTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatch_due_recovers_only_stale_sending_notifications_and_queues_pending_ones(): void
    {
        Queue::fake([SendNotificationOutbox::class]);
        $this->travelTo(Carbon::parse('2026-11-18 12:00:00', 'UTC'));
        $stale = NotificationOutbox::factory()->create([
            'incident_id' => null,
            'event_type' => 'dns_daily_summary',
            'status' => 'sending',
            'attempt_started_at' => now()->subMinutes(6),
        ]);
        $atTimeoutBoundary = NotificationOutbox::factory()->create([
            'incident_id' => null,
            'event_type' => 'dns_daily_summary',
            'status' => 'sending',
            'attempt_started_at' => now()->subMinutes(5),
        ]);
        $recent = NotificationOutbox::factory()->create([
            'incident_id' => null,
            'event_type' => 'dns_daily_summary',
            'status' => 'sending',
            'attempt_started_at' => now()->subMinutes(4),
        ]);
        $pending = NotificationOutbox::factory()->create([
            'incident_id' => null,
            'event_type' => 'dns_daily_summary',
            'status' => 'pending',
        ]);

        $this->artisan('monitors:dispatch-due')->assertExitCode(0);

        $this->assertDatabaseHas('notification_outbox', [
            'id' => $stale->id,
            'status' => 'unknown',
            'last_error' => '工作者可能在寄送期間停止，結果不明；重試前請先確認收件狀態。',
        ]);
        $this->assertDatabaseHas('notification_outbox', [
            'id' => $atTimeoutBoundary->id,
            'status' => 'sending',
        ]);
        $this->assertDatabaseHas('notification_outbox', ['id' => $recent->id, 'status' => 'sending']);
        $this->assertDatabaseHas('notification_outbox', ['id' => $pending->id, 'status' => 'pending']);
        $this->assertNotNull(SchedulerHeartbeat::query()->where('job_key', 'monitor-scheduler')->value('last_succeeded_at'));

        Queue::assertPushed(SendNotificationOutbox::class, 1);
        Queue::assertPushed(
            SendNotificationOutbox::class,
            fn (SendNotificationOutbox $job): bool => $job->outboxId === $pending->id,
        );
    }
}
