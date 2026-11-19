<?php

namespace Tests\Feature;

use App\Jobs\SendNotificationOutbox;
use App\Models\CheckRun;
use App\Models\Incident;
use App\Models\MaintenanceWindow;
use App\Models\Monitor;
use App\Models\NotificationOutbox;
use App\Models\StatusPage;
use App\Models\StatusPageSubscription;
use App\Models\User;
use App\Services\IncidentManager;
use App\Services\Probe\ProbeResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class StaleMonitorIncidentTest extends TestCase
{
    use RefreshDatabase;

    public function test_scheduler_opens_one_warning_incident_and_notifies_when_check_data_expires(): void
    {
        $this->travelTo(Carbon::parse('2026-11-20 12:00:00', 'UTC'));
        Config::set('monitoring.stale_grace_seconds', 0);
        Queue::fake([SendNotificationOutbox::class]);
        $statusPage = StatusPage::factory()->create(['setting_key' => 'default', 'is_published' => true]);
        StatusPageSubscription::factory()->for($statusPage)->create();
        $monitor = $this->createStaleMonitor()->forceFill(['public_status' => true]);
        $monitor->save();

        $this->artisan('monitors:dispatch-due')->assertExitCode(0);
        $this->artisan('monitors:dispatch-due')->assertExitCode(0);

        $incident = Incident::query()->sole();
        $this->assertSame('monitor_stale', $incident->rule_key);
        $this->assertSame(Incident::STATE_OPEN, $incident->state);
        $this->assertSame('warning', $incident->severity);
        $this->assertDatabaseHas('incident_events', [
            'incident_id' => $incident->id,
            'check_run_id' => null,
            'event_type' => 'opened',
        ]);
        $this->assertSame(1, NotificationOutbox::query()->where('event_type', 'opened')->count());
        $this->assertDatabaseMissing('notification_outbox', [
            'event_type' => NotificationOutbox::EVENT_TYPE_STATUS_OPENED,
        ]);
        $this->assertStringContainsString('檢查結果已超過預期更新時間', NotificationOutbox::query()->sole()->body);
        Queue::assertPushed(SendNotificationOutbox::class, 1);

        $this->actingAs(User::factory()->create())
            ->get(route('incidents.index', ['rule_key' => 'monitor_stale']))
            ->assertOk()
            ->assertSee('監測資料逾時')
            ->assertSee($monitor->name);
        $this->get(route('monitors.show', $monitor))
            ->assertOk()
            ->assertSee('監測資料逾時');
        $this->get(route('notifications.index', ['event_type' => 'opened']))
            ->assertOk()
            ->assertSee('異常通知')
            ->assertSee($monitor->name);
        $exportedIncidents = $this->get(route('incidents.export', ['rule_key' => 'monitor_stale']))
            ->assertOk()
            ->streamedContent();
        $this->assertStringContainsString('monitor_stale', $exportedIncidents);
    }

    public function test_stale_detection_respects_the_exact_threshold_and_skips_paused_monitors(): void
    {
        $this->travelTo(Carbon::parse('2026-11-20 12:00:00', 'UTC'));
        Config::set('monitoring.stale_grace_seconds', 30);
        Queue::fake([SendNotificationOutbox::class]);
        $atThreshold = $this->createStaleMonitor(
            intervalSeconds: 60,
            lastCheckedAt: now()->subSeconds(90),
        );
        $paused = $this->createStaleMonitor(
            intervalSeconds: 60,
            lastCheckedAt: now()->subHours(2),
            enabled: false,
        );

        $this->assertSame(0, app(IncidentManager::class)->queueDueStaleIncidents());
        $this->travelTo(now()->addSecond());
        $this->assertSame(1, app(IncidentManager::class)->queueDueStaleIncidents());

        $this->assertDatabaseHas('incidents', [
            'monitor_id' => $atThreshold->id,
            'rule_key' => 'monitor_stale',
            'state' => Incident::STATE_OPEN,
        ]);
        $this->assertDatabaseMissing('incidents', [
            'monitor_id' => $paused->id,
            'rule_key' => 'monitor_stale',
        ]);
    }

    public function test_a_monitor_that_has_never_completed_a_check_can_be_reported_stale(): void
    {
        $this->travelTo(Carbon::parse('2026-11-20 12:00:00', 'UTC'));
        Config::set('monitoring.stale_grace_seconds', 0);
        Queue::fake([SendNotificationOutbox::class]);
        $monitor = Monitor::factory()->create([
            'url_hash' => hash('sha256', (string) Str::uuid()),
            'interval_seconds' => 60,
            'next_check_at' => now()->addMinutes(10),
            'last_checked_at' => null,
        ]);
        $monitor->forceFill(['created_at' => now()->subMinutes(2)])->save();

        $this->assertSame(1, app(IncidentManager::class)->queueDueStaleIncidents());

        $this->assertDatabaseHas('incidents', [
            'monitor_id' => $monitor->id,
            'rule_key' => 'monitor_stale',
            'state' => Incident::STATE_OPEN,
        ]);
    }

    public function test_stale_incidents_are_recorded_but_not_notified_during_maintenance(): void
    {
        $this->travelTo(Carbon::parse('2026-11-20 12:00:00', 'UTC'));
        Config::set('monitoring.stale_grace_seconds', 0);
        Queue::fake([SendNotificationOutbox::class]);
        $monitor = $this->createStaleMonitor();
        MaintenanceWindow::factory()->for($monitor)->create([
            'starts_at' => now()->subMinute(),
            'ends_at' => now()->addMinute(),
        ]);

        $this->assertSame(1, app(IncidentManager::class)->queueDueStaleIncidents());

        $this->assertDatabaseHas('incidents', [
            'monitor_id' => $monitor->id,
            'rule_key' => 'monitor_stale',
            'state' => Incident::STATE_OPEN,
        ]);
        $this->assertDatabaseCount('notification_outbox', 0);
        Queue::assertNothingPushed();
    }

    public function test_stale_incident_recovers_after_two_fresh_check_observations(): void
    {
        $this->travelTo(Carbon::parse('2026-11-20 12:00:00', 'UTC'));
        Config::set('monitoring.stale_grace_seconds', 0);
        Queue::fake([SendNotificationOutbox::class]);
        $monitor = $this->createStaleMonitor();
        $manager = app(IncidentManager::class);
        $this->assertSame(1, $manager->queueDueStaleIncidents());
        $incident = Incident::query()->sole();
        $normal = new ProbeResult(200, 50, $monitor->url, [], ['93.184.216.34'], null, null, null, null);

        $firstRun = $this->createRun($monitor);
        $monitor->forceFill(['last_checked_at' => now()])->save();
        $manager->record($monitor, $firstRun, $normal, null);
        $this->assertSame(Incident::STATE_OPEN, $incident->fresh()->state);
        $this->assertSame('warning', $monitor->fresh()->healthState());

        $this->travelTo(now()->addSeconds(61));
        $secondRun = $this->createRun($monitor);
        $monitor->forceFill(['last_checked_at' => now()])->save();
        $manager->record($monitor, $secondRun, $normal, null);

        $this->assertSame(Incident::STATE_RESOLVED, $incident->fresh()->state);
        $this->assertSame('healthy', $monitor->fresh()->healthState());
        $this->assertDatabaseHas('incident_events', [
            'incident_id' => $incident->id,
            'check_run_id' => $secondRun->id,
            'event_type' => 'recovered',
        ]);
        $this->assertDatabaseHas('notification_outbox', [
            'incident_id' => $incident->id,
            'event_type' => 'recovered',
        ]);
    }

    public function test_stale_incidents_support_reminders_and_escalations_without_a_check_run(): void
    {
        $this->travelTo(Carbon::parse('2026-11-20 12:00:00', 'UTC'));
        Config::set('monitoring.stale_grace_seconds', 0);
        Config::set('monitoring.incident_reminder_interval_minutes', 1);
        Queue::fake([SendNotificationOutbox::class]);
        $monitor = $this->createStaleMonitor();
        $monitor->forceFill([
            'notification_escalation_email' => 'oncall@example.test',
            'notification_escalation_delay_minutes' => 1,
        ])->save();
        $manager = app(IncidentManager::class);

        $this->assertSame(1, $manager->queueDueStaleIncidents());
        $this->travelTo(now()->addMinutes(2));

        $this->assertSame(1, $manager->queueDueReminders());
        $this->assertSame(1, $manager->queueDueEscalations());
        $reminder = NotificationOutbox::query()->where('event_type', NotificationOutbox::EVENT_TYPE_REMINDER)->sole();
        $escalation = NotificationOutbox::query()->where('event_type', NotificationOutbox::EVENT_TYPE_ESCALATION)->sole();
        $this->assertStringContainsString('監測檢查結果已超過預期更新時間', $reminder->body);
        $this->assertSame('oncall@example.test', $escalation->recipient);
        Queue::assertPushed(SendNotificationOutbox::class, 3);
    }

    private function createStaleMonitor(
        int $intervalSeconds = 60,
        ?Carbon $lastCheckedAt = null,
        bool $enabled = true,
    ): Monitor {
        return Monitor::factory()->create([
            'url_hash' => hash('sha256', (string) Str::uuid()),
            'enabled' => $enabled,
            'interval_seconds' => $intervalSeconds,
            'next_check_at' => now()->addMinutes(10),
            'last_checked_at' => $lastCheckedAt ?? now()->subSeconds($intervalSeconds + 1),
            'notification_email' => 'ops@example.test',
        ]);
    }

    private function createRun(Monitor $monitor): CheckRun
    {
        return CheckRun::factory()->for($monitor)->create([
            'run_key' => hash('sha256', (string) Str::uuid()),
            'config_version' => $monitor->config_version,
            'scheduled_at' => now(),
        ]);
    }
}
