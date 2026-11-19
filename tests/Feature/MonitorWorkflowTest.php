<?php

namespace Tests\Feature;

use App\Jobs\RunMonitorCheck;
use App\Jobs\SendNotificationOutbox;
use App\Models\CheckRun;
use App\Models\Incident;
use App\Models\MaintenanceWindow;
use App\Models\Monitor;
use App\Models\NotificationOutbox;
use App\Models\User;
use App\Services\IncidentManager;
use App\Services\NotificationDispatcher;
use App\Services\Probe\ProbeClient;
use App\Services\Probe\ProbeResult;
use App\Services\RunScheduler;
use App\Services\StatusPageSubscriptionDispatcher;
use App\Services\UrlMasker;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class MonitorWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_check_run_stores_encrypted_text_match_details_and_displays_each_phrase(): void
    {
        Queue::fake([RunMonitorCheck::class]);
        $monitor = Monitor::factory()->create([
            'expected_text' => "Campaign headline\nSpring sale",
            'expected_text_mode' => 'present_any',
        ]);
        $run = app(RunScheduler::class)->enqueue($monitor, 'manual');

        $this->assertNotNull($run);
        $normal = new ProbeResult(
            200,
            31,
            'https://example.com/landing',
            [],
            ['93.184.216.34'],
            null,
            null,
            true,
            null,
            ['93.184.216.34'],
            null,
            null,
            [true, false],
        );
        $adsbot = new ProbeResult(200, 29, 'https://example.com/landing', [], ['93.184.216.34'], null, null, null, null);
        $probe = $this->mock(ProbeClient::class);
        $probe->shouldReceive('inspect')->twice()->andReturn($normal, $adsbot);

        (new RunMonitorCheck($run->id))->handle(
            $probe,
            app(IncidentManager::class),
            app(RunScheduler::class),
        );

        $completedRun = $run->fresh();
        $rawTermMatches = (string) DB::table('check_runs')->where('id', $run->id)->value('expected_text_term_matches');

        $this->assertSame([true, false], $completedRun->expected_text_term_matches);
        $this->assertNotSame('[true,false]', $rawTermMatches);
        $this->assertStringNotContainsString('Campaign headline', $rawTermMatches);
        $this->assertSame([
            ['term' => 'Campaign headline', 'matched' => true],
            ['term' => 'Spring sale', 'matched' => false],
        ], $completedRun->expectedTextMatchDetails());

        $this->actingAs(User::factory()->create())
            ->get(route('monitors.show', $monitor))
            ->assertOk()
            ->assertSee('Campaign headline')
            ->assertSee('Spring sale')
            ->assertSee('原始回應有此片段')
            ->assertSee('原始回應未找到此片段')
            ->assertSee('查看逐項片段結果');
    }

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
        $job->handle($probe, new IncidentManager(new NotificationDispatcher(new UrlMasker, app(StatusPageSubscriptionDispatcher::class))), app(RunScheduler::class));
        $job->handle($probe, new IncidentManager(new NotificationDispatcher(new UrlMasker, app(StatusPageSubscriptionDispatcher::class))), app(RunScheduler::class));

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

    public function test_exhausted_check_job_marks_in_flight_runs_failed_and_allows_a_later_check(): void
    {
        Queue::fake([RunMonitorCheck::class]);
        $monitor = Monitor::factory()->create(['next_check_at' => now()->subMinute()]);
        $queuedRun = $this->createRun($monitor, ['status' => 'queued', 'started_at' => null, 'finished_at' => null]);
        $runningRun = $this->createRun($monitor, [
            'status' => 'running',
            'started_at' => now()->subMinutes(5),
            'finished_at' => null,
        ]);
        $exception = new RuntimeException('private transport exception details');

        (new RunMonitorCheck($queuedRun->id))->failed($exception);
        (new RunMonitorCheck($runningRun->id))->failed($exception);

        foreach ([$queuedRun, $runningRun] as $run) {
            $this->assertDatabaseHas('check_runs', [
                'id' => $run->id,
                'status' => 'failed',
                'error_type' => 'queue_job_failed',
                'error_detail' => '檢查佇列工作重試耗盡，未能完成探測。',
            ]);
            $this->assertNotNull($run->fresh()->finished_at);
            $this->assertStringNotContainsString('private transport exception details', $run->fresh()->error_detail);
        }

        $nextRun = app(RunScheduler::class)->enqueue($monitor->fresh(), 'scheduled');

        $this->assertNotNull($nextRun);
        $this->assertSame('queued', $nextRun->status);
        $this->assertTrue($monitor->fresh()->next_check_at->isFuture());
        Queue::assertPushed(RunMonitorCheck::class, 1);
    }

    public function test_failed_callback_does_not_overwrite_completed_or_already_failed_runs(): void
    {
        $monitor = Monitor::factory()->create();
        $completedRun = $this->createRun($monitor, ['status' => 'completed', 'error_type' => null]);
        $probeFailedRun = $this->createRun($monitor, [
            'status' => 'failed',
            'error_type' => 'probe_internal_error',
            'error_detail' => '檢查在記錄結果前失敗。',
        ]);
        $exception = new RuntimeException('later worker exception');

        (new RunMonitorCheck($completedRun->id))->failed($exception);
        (new RunMonitorCheck($probeFailedRun->id))->failed($exception);

        $this->assertDatabaseHas('check_runs', [
            'id' => $completedRun->id,
            'status' => 'completed',
            'error_type' => null,
        ]);
        $this->assertDatabaseHas('check_runs', [
            'id' => $probeFailedRun->id,
            'status' => 'failed',
            'error_type' => 'probe_internal_error',
            'error_detail' => '檢查在記錄結果前失敗。',
        ]);
    }

    public function test_transient_probe_exception_keeps_run_in_flight_until_retries_are_exhausted(): void
    {
        $monitor = Monitor::factory()->create();
        $run = $this->createRun($monitor, ['status' => 'queued', 'started_at' => null, 'finished_at' => null]);
        $exception = new RuntimeException('temporary probe failure');
        $probe = $this->mock(ProbeClient::class);
        $probe->shouldReceive('inspect')->once()->andThrow($exception);
        $job = new RunMonitorCheck($run->id);

        try {
            $job->handle($probe, app(IncidentManager::class), app(RunScheduler::class));
            $this->fail('The probe exception should be rethrown for queue retry.');
        } catch (RuntimeException $caughtException) {
            $this->assertSame($exception, $caughtException);
        }

        $this->assertDatabaseHas('check_runs', [
            'id' => $run->id,
            'status' => 'running',
            'finished_at' => null,
            'error_type' => null,
        ]);
        $this->assertNull(app(RunScheduler::class)->enqueue($monitor->fresh(), 'manual'));

        $job->failed($exception);

        $this->assertDatabaseHas('check_runs', [
            'id' => $run->id,
            'status' => 'failed',
            'error_type' => 'queue_job_failed',
        ]);
    }

    public function test_queue_failure_callback_finishes_a_run_when_the_sync_worker_fails(): void
    {
        $monitor = Monitor::factory()->create();
        $run = $this->createRun($monitor, ['status' => 'queued', 'started_at' => null, 'finished_at' => null]);
        $exception = new RuntimeException('probe worker exception');
        $probe = $this->mock(ProbeClient::class);
        $probe->shouldReceive('inspect')->once()->andThrow($exception);

        try {
            dispatch_sync(new RunMonitorCheck($run->id));
            $this->fail('The sync queue should rethrow the failed job exception.');
        } catch (RuntimeException $caughtException) {
            $this->assertSame($exception, $caughtException);
        }

        $this->assertDatabaseHas('check_runs', [
            'id' => $run->id,
            'status' => 'failed',
            'error_type' => 'queue_job_failed',
            'error_detail' => '檢查佇列工作重試耗盡，未能完成探測。',
        ]);
        $this->assertNotNull($run->fresh()->finished_at);
    }

    public function test_two_failures_open_one_incident_and_one_logical_notice(): void
    {
        Config::set('monitoring.notify_email', 'owner@example.test');
        Queue::fake([SendNotificationOutbox::class]);
        $url = 'https://example.com/landing?email=owner%40example.test&token=secret-value';
        $monitor = Monitor::factory()->create(['url' => $url, 'url_hash' => Monitor::fingerprintUrl($url)]);
        $manager = new IncidentManager(new NotificationDispatcher(new UrlMasker, app(StatusPageSubscriptionDispatcher::class)));
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
        $manager = new IncidentManager(new NotificationDispatcher(new UrlMasker, app(StatusPageSubscriptionDispatcher::class)));
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
        $manager = new IncidentManager(new NotificationDispatcher(new UrlMasker, app(StatusPageSubscriptionDispatcher::class)));
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

    public function test_tls_threshold_changes_advance_warnings_without_recovery_notifications(): void
    {
        Config::set('monitoring.notify_email', 'owner@example.test');
        Queue::fake([SendNotificationOutbox::class]);
        $monitor = Monitor::factory()->create();
        $manager = new IncidentManager(new NotificationDispatcher(new UrlMasker, app(StatusPageSubscriptionDispatcher::class)));
        $normal = new ProbeResult(200, 50, 'https://example.com/landing', [], ['93.184.216.34'], null, null, null, now()->addDays(29)->toDateTimeImmutable());
        $adsbot = new ProbeResult(200, 50, 'https://example.com/landing', [], ['93.184.216.34'], null, null, null, now()->addDays(29)->toDateTimeImmutable());
        $run1 = $this->createRun($monitor, ['normal_status' => 200]);
        $manager->record($monitor, $run1, $normal, $adsbot);
        $this->travelTo(now()->addSeconds(61));
        $run2 = $this->createRun($monitor, ['normal_status' => 200]);
        $manager->record($monitor, $run2, $normal, $adsbot);

        $withinFourteenDays = new ProbeResult(200, 50, 'https://example.com/landing', [], ['93.184.216.34'], null, null, null, now()->addDays(13)->toDateTimeImmutable());
        $this->travelTo(now()->addMinutes(1));
        $run3 = $this->createRun($monitor, ['normal_status' => 200]);
        $manager->record($monitor, $run3, $withinFourteenDays, $withinFourteenDays);
        $this->travelTo(now()->addSeconds(61));
        $run4 = $this->createRun($monitor, ['normal_status' => 200]);
        $manager->record($monitor, $run4, $withinFourteenDays, $withinFourteenDays);

        $withinSevenDays = new ProbeResult(200, 50, 'https://example.com/landing', [], ['93.184.216.34'], null, null, null, now()->addDays(6)->toDateTimeImmutable());
        $this->travelTo(now()->addMinutes(1));
        $run5 = $this->createRun($monitor, ['normal_status' => 200]);
        $manager->record($monitor, $run5, $withinSevenDays, $withinSevenDays);
        $this->travelTo(now()->addSeconds(61));
        $run6 = $this->createRun($monitor, ['normal_status' => 200]);
        $manager->record($monitor, $run6, $withinSevenDays, $withinSevenDays);

        $this->assertDatabaseHas('incidents', ['monitor_id' => $monitor->id, 'rule_key' => 'tls_30d', 'state' => Incident::STATE_RESOLVED]);
        $this->assertDatabaseHas('incidents', ['monitor_id' => $monitor->id, 'rule_key' => 'tls_14d', 'state' => Incident::STATE_RESOLVED]);
        $this->assertDatabaseHas('incidents', ['monitor_id' => $monitor->id, 'rule_key' => 'tls_7d', 'state' => Incident::STATE_OPEN]);
        $rules = NotificationOutbox::query()->with('incident')->orderBy('id')->get()->map(fn (NotificationOutbox $outbox): string => $outbox->incident->rule_key)->all();
        $this->assertSame(['tls_30d', 'tls_14d', 'tls_7d'], $rules);
        $this->assertCount(3, $rules);
    }

    public function test_recurring_maintenance_keeps_recovery_notifications_suppressed_for_an_incident_opened_in_its_final_occurrence(): void
    {
        $this->travelTo(Carbon::parse('2026-12-19 04:00:00', 'UTC'));
        Config::set('monitoring.notify_email', 'owner@example.test');
        Queue::fake([SendNotificationOutbox::class]);
        $monitor = Monitor::factory()->create();
        $openedAt = Carbon::parse('2026-12-18 14:15:00', 'UTC');
        $startsAt = Carbon::parse('2026-11-20 22:00:00', 'Asia/Taipei');
        $window = MaintenanceWindow::factory()->for($monitor)->create([
            'starts_at' => $startsAt->copy()->utc(),
            'ends_at' => $startsAt->copy()->addHour()->utc(),
            'repeat_weekly' => true,
            'repeat_until' => '2026-12-18',
        ]);
        Incident::factory()->for($monitor)->create([
            'state' => Incident::STATE_OPEN,
            'rule_key' => 'http',
            'opened_at' => $openedAt,
            'last_seen_at' => $openedAt,
        ]);
        $manager = new IncidentManager(new NotificationDispatcher(new UrlMasker, app(StatusPageSubscriptionDispatcher::class)));
        $success = new ProbeResult(200, 40, 'https://example.com/landing', [], ['93.184.216.34'], null, null, null, null);

        $this->assertTrue($window->isActiveAt($openedAt));
        $firstRecoveryRun = $this->createRun($monitor, ['normal_status' => 200]);
        $manager->record($monitor, $firstRecoveryRun, $success, $success);
        $this->travelTo(now()->addSeconds(61));
        $confirmedRecoveryRun = $this->createRun($monitor, ['normal_status' => 200]);
        $manager->record($monitor, $confirmedRecoveryRun, $success, $success);

        $this->assertDatabaseHas('incidents', [
            'monitor_id' => $monitor->id,
            'rule_key' => 'http',
            'state' => Incident::STATE_RESOLVED,
        ]);
        $this->assertDatabaseHas('incident_events', [
            'event_type' => 'recovered',
            'check_run_id' => $confirmedRecoveryRun->id,
        ]);
        $this->assertDatabaseCount('notification_outbox', 0);
        Queue::assertNothingPushed();
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
