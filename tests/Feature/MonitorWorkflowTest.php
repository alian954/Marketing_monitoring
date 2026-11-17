<?php

namespace Tests\Feature;

use App\Jobs\RunMonitorCheck;
use App\Jobs\SendNotificationOutbox;
use App\Models\CheckRun;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\NotificationOutbox;
use App\Services\IncidentManager;
use App\Services\NotificationDispatcher;
use App\Services\Probe\ProbeClient;
use App\Services\Probe\ProbeResult;
use App\Services\RunScheduler;
use App\Services\UrlMasker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class MonitorWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_scheduled_run_persists_two_user_agent_results_once(): void
    {
        Queue::fake([RunMonitorCheck::class]);
        $url = 'https://landing.example/path?email=owner%40example.test&token=snapshot-secret';
        $monitor = Monitor::factory()->create([
            'url' => $url,
            'url_hash' => Monitor::fingerprintUrl($url),
        ]);
        $run = app(RunScheduler::class)->enqueue($monitor, 'manual');

        $this->assertNotNull($run);
        $this->assertDatabaseHas('check_runs', ['id' => $run->id, 'status' => 'queued', 'config_version' => 1]);
        $this->assertDatabaseHas('monitors', ['id' => $monitor->id]);
        $rawSnapshot = (string) DB::table('check_runs')->where('id', $run->id)->value('config_snapshot');
        $this->assertStringNotContainsString($url, $rawSnapshot);
        $this->assertStringNotContainsString('snapshot-secret', $rawSnapshot);
        $this->assertSame($url, json_decode($run->fresh()->config_snapshot, true, flags: JSON_THROW_ON_ERROR)['url']);
        Queue::assertPushed(RunMonitorCheck::class, 1);

        $normal = new ProbeResult(200, 31, 'https://example.com/landing', [], ['93.184.216.34'], null, null, null, null);
        $adsbot = new ProbeResult(200, 29, 'https://example.com/landing', [], ['93.184.216.34'], null, null, null, null);
        $probe = $this->mock(ProbeClient::class);
        $probe->shouldReceive('inspect')->twice()->andReturn($normal, $adsbot);
        $job = new RunMonitorCheck($run->id);
        $job->handle($probe, new IncidentManager(new NotificationDispatcher(new UrlMasker)), app(RunScheduler::class));
        $job->handle($probe, new IncidentManager(new NotificationDispatcher(new UrlMasker)), app(RunScheduler::class));

        $this->assertDatabaseHas('check_runs', [
            'id' => $run->id,
            'status' => 'completed',
            'normal_status' => 200,
            'adsbot_status' => 200,
            'elapsed_ms' => 60,
        ]);
        $this->assertDatabaseCount('check_runs', 1);
        $this->assertDatabaseMissing('incidents', ['monitor_id' => $monitor->id]);
    }

    public function test_manual_and_confirmation_runs_preserve_the_regular_schedule(): void
    {
        Queue::fake([RunMonitorCheck::class]);
        $scheduler = app(RunScheduler::class);
        $manualDueAt = now()->addMinutes(10);
        $manualUrl = 'https://example.com/manual';
        $manualMonitor = Monitor::factory()->create([
            'url' => $manualUrl,
            'url_hash' => Monitor::fingerprintUrl($manualUrl),
            'next_check_at' => $manualDueAt,
        ]);
        $confirmationDueAt = now()->addMinutes(12);
        $confirmationUrl = 'https://example.com/confirmation';
        $confirmationMonitor = Monitor::factory()->create([
            'url' => $confirmationUrl,
            'url_hash' => Monitor::fingerprintUrl($confirmationUrl),
            'next_check_at' => $confirmationDueAt,
        ]);

        $scheduler->enqueue($manualMonitor, 'manual');
        $scheduler->enqueue($confirmationMonitor, 'confirmation', now()->addSeconds(60), 60);

        $this->assertSame($manualDueAt->toDateTimeString(), $manualMonitor->fresh()->next_check_at->toDateTimeString());
        $this->assertSame($confirmationDueAt->toDateTimeString(), $confirmationMonitor->fresh()->next_check_at->toDateTimeString());
    }

    public function test_two_failures_open_one_incident_and_one_logical_notice(): void
    {
        Config::set('monitoring.notify_email', 'owner@example.test');
        Queue::fake([SendNotificationOutbox::class]);
        $url = 'https://example.com/landing?email=owner%40example.test&token=secret-value';
        $monitor = Monitor::factory()->create(['url' => $url, 'url_hash' => Monitor::fingerprintUrl($url)]);
        $manager = new IncidentManager(new NotificationDispatcher(new UrlMasker));
        $failure = new ProbeResult(503, 120, 'https://example.com/landing?email=REDACTED&token=REDACTED', [], ['93.184.216.34'], null, null, null, null);
        $success = new ProbeResult(200, 80, 'https://example.com/landing', [], ['93.184.216.34'], null, null, null, null);
        $firstRun = $this->createRun($monitor, ['normal_status' => 503]);
        $manager->record($monitor, $firstRun, $failure, $success);

        $this->assertDatabaseHas('incidents', ['monitor_id' => $monitor->id, 'state' => Incident::STATE_SUSPECT, 'failure_count' => 1]);
        $this->travelTo(now()->addSeconds(61));
        $secondRun = $this->createRun($monitor, ['normal_status' => 503]);
        $manager->record($monitor, $secondRun, $failure, $success);
        $manager->record($monitor, $secondRun, $failure, $success);

        $this->assertDatabaseCount('incidents', 1);
        $this->assertDatabaseHas('incidents', ['monitor_id' => $monitor->id, 'state' => Incident::STATE_OPEN, 'failure_count' => 2]);
        $this->assertDatabaseCount('incident_events', 3);
        $this->assertDatabaseCount('notification_outbox', 1);
        $this->assertDatabaseHas('notification_outbox', ['status' => 'pending', 'event_type' => 'opened']);
        $outbox = NotificationOutbox::query()->firstOrFail();
        $this->assertStringContainsString('email=REDACTED', $outbox->body);
        $this->assertStringContainsString('token=REDACTED', $outbox->body);
        $this->assertStringNotContainsString('secret-value', $outbox->body);
        $this->assertNotSame($url, \DB::table('monitors')->where('id', $monitor->id)->value('url'));
        Queue::assertPushed(SendNotificationOutbox::class, 1);
    }

    public function test_two_successful_checks_recover_an_open_incident_once(): void
    {
        Config::set('monitoring.notify_email', 'owner@example.test');
        Queue::fake([SendNotificationOutbox::class]);
        $monitor = Monitor::factory()->create();
        $manager = new IncidentManager(new NotificationDispatcher(new UrlMasker));
        $failure = new ProbeResult(500, 100, 'https://example.com/landing', [], ['93.184.216.34'], null, null, null, null);
        $success = new ProbeResult(200, 75, 'https://example.com/landing', [], ['93.184.216.34'], null, null, null, null);
        $firstRun = $this->createRun($monitor, ['normal_status' => 500]);
        $manager->record($monitor, $firstRun, $failure, $success);
        $this->travelTo(now()->addSeconds(61));
        $secondRun = $this->createRun($monitor, ['normal_status' => 500]);
        $manager->record($monitor, $secondRun, $failure, $success);

        $this->travelTo(now()->addSeconds(61));
        $firstRecovery = $this->createRun($monitor, ['normal_status' => 200]);
        $manager->record($monitor, $firstRecovery, $success, $success);
        $this->travelTo(now()->addSeconds(61));
        $secondRecovery = $this->createRun($monitor, ['normal_status' => 200]);
        $manager->record($monitor, $secondRecovery, $success, $success);

        $this->assertDatabaseHas('incidents', ['monitor_id' => $monitor->id, 'state' => Incident::STATE_RESOLVED, 'recovery_count' => 2]);
        $this->assertDatabaseCount('notification_outbox', 2);
        $this->assertDatabaseHas('notification_outbox', ['event_type' => 'recovered', 'status' => 'pending']);
    }

    public function test_single_failed_check_that_recovers_is_recorded_as_false_alarm(): void
    {
        Config::set('monitoring.notify_email', 'owner@example.test');
        Queue::fake([SendNotificationOutbox::class]);
        $monitor = Monitor::factory()->create();
        $manager = new IncidentManager(new NotificationDispatcher(new UrlMasker));
        $failure = new ProbeResult(404, 40, 'https://example.com/missing', [], ['93.184.216.34'], null, null, null, null);
        $success = new ProbeResult(200, 40, 'https://example.com/landing', [], ['93.184.216.34'], null, null, null, null);
        $run = $this->createRun($monitor, ['normal_status' => 404]);
        $manager->record($monitor, $run, $failure, $success);
        $this->travelTo(now()->addSeconds(10));
        $confirmation = $this->createRun($monitor, ['normal_status' => 200]);
        $manager->record($monitor, $confirmation, $success, $success);

        $this->assertDatabaseHas('incidents', ['monitor_id' => $monitor->id, 'state' => Incident::STATE_RESOLVED, 'classification' => 'false_alarm']);
        $this->assertDatabaseHas('incident_events', ['event_type' => 'false_alarm', 'check_run_id' => $confirmation->id]);
        $this->assertDatabaseCount('notification_outbox', 0);
    }

    public function test_tls_threshold_change_does_not_send_a_recovery_notice_for_the_14_day_band(): void
    {
        Config::set('monitoring.notify_email', 'owner@example.test');
        Queue::fake([SendNotificationOutbox::class]);
        $monitor = Monitor::factory()->create();
        $manager = new IncidentManager(new NotificationDispatcher(new UrlMasker));
        $normal = new ProbeResult(200, 50, 'https://example.com/landing', [], ['93.184.216.34'], null, null, null, now()->addDays(13)->toDateTimeImmutable());
        $adsbot = new ProbeResult(200, 50, 'https://example.com/landing', [], ['93.184.216.34'], null, null, null, now()->addDays(13)->toDateTimeImmutable());
        $run1 = $this->createRun($monitor, ['normal_status' => 200]);
        $manager->record($monitor, $run1, $normal, $adsbot);
        $this->travelTo(now()->addSeconds(61));
        $run2 = $this->createRun($monitor, ['normal_status' => 200]);
        $manager->record($monitor, $run2, $normal, $adsbot);

        $withinSevenDays = new ProbeResult(200, 50, 'https://example.com/landing', [], ['93.184.216.34'], null, null, null, now()->addDays(6)->toDateTimeImmutable());
        $this->travelTo(now()->addMinutes(1));
        $run3 = $this->createRun($monitor, ['normal_status' => 200]);
        $manager->record($monitor, $run3, $withinSevenDays, $withinSevenDays);
        $this->travelTo(now()->addSeconds(61));
        $run4 = $this->createRun($monitor, ['normal_status' => 200]);
        $manager->record($monitor, $run4, $withinSevenDays, $withinSevenDays);

        $this->assertDatabaseHas('incidents', ['monitor_id' => $monitor->id, 'rule_key' => 'tls_14d', 'state' => Incident::STATE_RESOLVED]);
        $this->assertDatabaseHas('incidents', ['monitor_id' => $monitor->id, 'rule_key' => 'tls_7d', 'state' => Incident::STATE_OPEN]);
        $rules = NotificationOutbox::query()->with('incident')->orderBy('id')->get()->map(fn (NotificationOutbox $outbox): string => $outbox->incident->rule_key)->all();
        $this->assertSame(['tls_14d', 'tls_7d'], $rules);
        $this->assertCount(2, $rules);
    }

    private function createRun(Monitor $monitor, array $attributes): CheckRun
    {
        return CheckRun::factory()->for($monitor)->create(array_merge([
            'run_key' => hash('sha256', (string) Str::uuid()),
            'config_version' => $monitor->config_version,
            'scheduled_at' => now(),
        ], $attributes));
    }
}
