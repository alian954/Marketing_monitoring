<?php

namespace Tests\Feature;

use App\Jobs\RunMonitorCheck;
use App\Jobs\SendNotificationOutbox;
use App\Models\CheckRun;
use App\Models\Incident;
use App\Models\IncidentEvent;
use App\Models\MaintenanceWindow;
use App\Models\Monitor;
use App\Models\NotificationOutbox;
use App\Models\User;
use App\Services\IncidentManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class IncidentReminderTest extends TestCase
{
    use RefreshDatabase;

    public function test_scheduler_queues_one_reminder_for_an_open_acknowledged_incident(): void
    {
        $this->travelTo(Carbon::parse('2026-11-20 12:00:00', 'UTC'));
        Config::set('monitoring.incident_reminder_interval_minutes', 360);
        Config::set('monitoring.notify_email', 'ops@example.test');
        Config::set('monitoring.notify_slack_webhook', 'https://hooks.slack.com/services/T123/SECRET/WEBHOOK');
        Queue::fake([RunMonitorCheck::class, SendNotificationOutbox::class]);
        $user = User::factory()->create();
        $monitor = $this->monitor(1);
        $incident = $this->createIncidentWithFailure(
            $monitor,
            Incident::STATE_OPEN,
            now()->subHours(7),
            ['acknowledged_at' => now()->subHour(), 'acknowledged_by_id' => $user->id],
        );

        $this->artisan('monitors:dispatch-due')->assertExitCode(0);

        $reminders = NotificationOutbox::query()->where('event_type', 'reminder')->orderBy('channel')->get();
        $this->assertCount(2, $reminders);
        $this->assertSame([
            NotificationOutbox::CHANNEL_EMAIL,
            NotificationOutbox::CHANNEL_SLACK,
        ], $reminders->pluck('channel')->all());
        $this->assertSame(2, $reminders->pluck('event_key')->unique()->count());
        $this->assertStringContainsString('異常仍未恢復', $reminders->first()->subject);
        $this->assertStringContainsString('HTTP 狀態碼：503', $reminders->first()->body);
        $this->assertDatabaseHas('incident_events', [
            'incident_id' => $incident->id,
            'event_type' => 'reminder_queued',
        ]);
        Queue::assertPushed(SendNotificationOutbox::class, 2);

        $this->assertSame(0, app(IncidentManager::class)->queueDueReminders());
        $this->assertDatabaseCount('notification_outbox', 2);

        $this->actingAs($user)
            ->get(route('notifications.index', ['event_type' => 'reminder']))
            ->assertOk()
            ->assertSee('持續事件提醒')
            ->assertDontSee('https://hooks.slack.com/services/T123/SECRET/WEBHOOK');
    }

    public function test_open_incident_reminder_repeats_only_after_the_configured_interval(): void
    {
        $this->travelTo(Carbon::parse('2026-11-20 12:00:00', 'UTC'));
        Config::set('monitoring.incident_reminder_interval_minutes', 360);
        Config::set('monitoring.notify_email', 'ops@example.test');
        Queue::fake([SendNotificationOutbox::class]);
        $monitor = $this->monitor(2);
        $this->createIncidentWithFailure($monitor, Incident::STATE_OPEN, now()->subHours(7));

        $this->assertSame(1, app(IncidentManager::class)->queueDueReminders());
        $this->travelTo(Carbon::parse('2026-11-20 17:59:00', 'UTC'));
        $this->assertSame(0, app(IncidentManager::class)->queueDueReminders());
        $this->travelTo(Carbon::parse('2026-11-20 18:00:00', 'UTC'));
        $this->assertSame(1, app(IncidentManager::class)->queueDueReminders());

        $reminders = NotificationOutbox::query()->where('event_type', 'reminder')->orderBy('id')->get();
        $this->assertCount(2, $reminders);
        $this->assertNotSame($reminders[0]->event_key, $reminders[1]->event_key);
        $this->assertSame(2, IncidentEvent::query()->where('event_type', 'reminder_queued')->count());
        Queue::assertPushed(SendNotificationOutbox::class, 2);
    }

    public function test_reminders_skip_incidents_in_maintenance_that_are_recent_or_not_open(): void
    {
        $this->travelTo(Carbon::parse('2026-11-20 12:00:00', 'UTC'));
        Config::set('monitoring.incident_reminder_interval_minutes', 360);
        Config::set('monitoring.notify_email', 'ops@example.test');
        Queue::fake([SendNotificationOutbox::class]);

        $maintainedMonitor = $this->monitor(3);
        $maintainedIncident = $this->createIncidentWithFailure($maintainedMonitor, Incident::STATE_OPEN, now()->subHours(7));
        MaintenanceWindow::factory()->for($maintainedMonitor)->create([
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHour(),
        ]);

        $recentMonitor = $this->monitor(4);
        $this->createIncidentWithFailure($recentMonitor, Incident::STATE_OPEN, now()->subHours(2));

        $suspectMonitor = $this->monitor(5);
        $this->createIncidentWithFailure($suspectMonitor, Incident::STATE_SUSPECT, now()->subHours(7));

        $resolvedMonitor = $this->monitor(6);
        $this->createIncidentWithFailure($resolvedMonitor, Incident::STATE_RESOLVED, now()->subHours(7));

        $this->assertSame(0, app(IncidentManager::class)->queueDueReminders());
        $this->assertDatabaseMissing('incident_events', [
            'incident_id' => $maintainedIncident->id,
            'event_type' => 'reminder_queued',
        ]);
        $this->assertDatabaseCount('notification_outbox', 0);
        Queue::assertNothingPushed();
    }

    public function test_weekly_maintenance_skips_due_reminders_during_a_later_occurrence(): void
    {
        $this->travelTo(Carbon::parse('2026-11-27 14:30:00', 'UTC'));
        Config::set('monitoring.incident_reminder_interval_minutes', 360);
        Config::set('monitoring.notify_email', 'ops@example.test');
        Queue::fake([SendNotificationOutbox::class]);
        $monitor = $this->monitor(8);
        $incident = $this->createIncidentWithFailure($monitor, Incident::STATE_OPEN, now()->subHours(7));
        $startsAt = Carbon::parse('2026-11-20 22:00:00', 'Asia/Taipei');

        MaintenanceWindow::factory()->for($monitor)->create([
            'starts_at' => $startsAt->copy()->utc(),
            'ends_at' => $startsAt->copy()->addHour()->utc(),
            'repeat_weekly' => true,
            'repeat_until' => '2026-12-18',
        ]);

        $this->assertSame(0, app(IncidentManager::class)->queueDueReminders());
        $this->assertDatabaseMissing('incident_events', [
            'incident_id' => $incident->id,
            'event_type' => 'reminder_queued',
        ]);
        $this->assertDatabaseCount('notification_outbox', 0);
        Queue::assertNothingPushed();
    }

    public function test_zero_reminder_interval_disables_incident_reminders(): void
    {
        $this->travelTo(Carbon::parse('2026-11-20 12:00:00', 'UTC'));
        Config::set('monitoring.incident_reminder_interval_minutes', 0);
        Config::set('monitoring.notify_email', 'ops@example.test');
        Queue::fake([SendNotificationOutbox::class]);
        $monitor = $this->monitor(7);
        $this->createIncidentWithFailure($monitor, Incident::STATE_OPEN, now()->subDays(2));

        $this->assertSame(0, app(IncidentManager::class)->queueDueReminders());
        $this->assertDatabaseCount('notification_outbox', 0);
        Queue::assertNothingPushed();
    }

    /** @param array<string, mixed> $attributes */
    private function monitor(int $number, array $attributes = []): Monitor
    {
        $url = 'https://reminder-'.$number.'.example/landing';

        return Monitor::factory()->create(array_merge([
            'url' => $url,
            'url_hash' => Monitor::fingerprintUrl($url),
            'next_check_at' => now()->addHour(),
        ], $attributes));
    }

    /** @param array<string, mixed> $attributes */
    private function createIncidentWithFailure(Monitor $monitor, string $state, Carbon $openedAt, array $attributes = []): Incident
    {
        $run = CheckRun::factory()->for($monitor)->create([
            'source' => 'scheduled',
            'status' => 'completed',
            'scheduled_at' => $openedAt,
            'finished_at' => $openedAt,
            'normal_status' => 503,
        ]);
        $incident = Incident::factory()->for($monitor)->create(array_merge([
            'rule_key' => 'http',
            'state' => $state,
            'opened_at' => $openedAt,
            'last_seen_at' => $openedAt,
            'resolved_at' => $state === Incident::STATE_RESOLVED ? $openedAt->copy()->addMinute() : null,
        ], $attributes));

        IncidentEvent::factory()->for($incident)->for($run)->create([
            'event_type' => 'failure_seen',
            'evidence' => ['status' => 503],
            'created_at' => $openedAt,
        ]);

        return $incident;
    }
}
