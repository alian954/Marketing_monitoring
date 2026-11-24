<?php

namespace Tests\Feature;

use App\Jobs\CheckTlsCertificate;
use App\Jobs\SendNotificationOutbox;
use App\Models\CheckRun;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\TlsObservation;
use App\Models\User;
use App\Services\IncidentManager;
use App\Services\Probe\TlsCertificateProbe;
use App\Services\Probe\TlsCertificateResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schedule;
use Tests\TestCase;

class DailyTlsCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatcher_queues_enabled_https_monitors_without_todays_observation(): void
    {
        Queue::fake();

        $eligibleMonitor = $this->createMonitor('https://eligible.example/landing');
        $this->createMonitor('http://plain.example/landing');
        $socialMonitor = $this->createMonitor('https://www.facebook.com/share/p/12345/');
        $this->createMonitor('https://paused.example/landing', false);
        $observedMonitor = $this->createMonitor('https://observed.example/landing');
        TlsObservation::factory()->for($observedMonitor)->create(['observed_on' => today()]);

        $this->artisan('monitors:dispatch-tls')
            ->assertExitCode(0)
            ->expectsOutputToContain('已將 1 個 HTTPS 憑證檢查加入佇列。');

        Queue::assertPushed(CheckTlsCertificate::class, 1);
        Queue::assertPushed(CheckTlsCertificate::class, fn (CheckTlsCertificate $job): bool => $job->monitorId === $eligibleMonitor->id);
        Queue::assertNotPushed(CheckTlsCertificate::class, fn (CheckTlsCertificate $job): bool => $job->monitorId === $socialMonitor->id);
    }

    public function test_job_skips_social_post_urls_even_when_dispatched_directly(): void
    {
        $monitor = $this->createMonitor('https://www.instagram.com/reel/12345/');
        $probe = $this->mock(TlsCertificateProbe::class);
        $probe->shouldNotReceive('inspect');

        (new CheckTlsCertificate($monitor->id))->handle($probe, app(IncidentManager::class));

        $this->assertDatabaseMissing('tls_observations', ['monitor_id' => $monitor->id]);
        $this->assertDatabaseMissing('check_runs', ['monitor_id' => $monitor->id, 'source' => 'tls_daily']);
    }

    public function test_job_saves_daily_expiry_and_uses_existing_tls_alert_rules_once(): void
    {
        Config::set('monitoring.notify_email', '');
        Queue::fake([SendNotificationOutbox::class]);
        $monitor = $this->createMonitor('https://certificate.example/landing');
        $regularRun = CheckRun::factory()->for($monitor)->create(['scheduled_at' => now()->subHour()]);
        $expiration = now()->addDays(13)->toDateTimeImmutable();
        $probe = $this->mock(TlsCertificateProbe::class);
        $probe->shouldReceive('inspect')
            ->once()
            ->with($monitor->url)
            ->andReturn(new TlsCertificateResult($expiration, null, null));

        $job = new CheckTlsCertificate($monitor->id);
        $job->handle($probe, app(IncidentManager::class));
        $job->handle($probe, app(IncidentManager::class));

        $this->assertDatabaseCount('tls_observations', 1);
        $this->assertDatabaseHas('tls_observations', [
            'monitor_id' => $monitor->id,
            'observed_on' => today()->startOfDay()->toDateTimeString(),
            'error_type' => null,
        ]);
        $this->assertDatabaseHas('check_runs', [
            'monitor_id' => $monitor->id,
            'source' => 'tls_daily',
            'status' => 'completed',
        ]);
        $this->assertDatabaseHas('incidents', [
            'monitor_id' => $monitor->id,
            'rule_key' => 'tls_14d',
            'state' => Incident::STATE_SUSPECT,
        ]);
        $this->assertSame($regularRun->id, $monitor->fresh()->latestCheckRun->id);
        $this->actingAs(User::factory()->create())
            ->get(route('monitors.show', $monitor))
            ->assertOk()
            ->assertSee('每日 TLS 獨立檢查')
            ->assertSee('憑證到期');
    }

    public function test_daily_tls_command_is_scheduled_once_per_day(): void
    {
        $event = collect(Schedule::events())->first(
            static fn ($event): bool => str_contains((string) $event->command, 'monitors:dispatch-tls'),
        );

        $this->assertNotNull($event);
        $this->assertSame('0 3 * * *', $event->expression);
    }

    public function test_job_records_a_handshake_failure_for_incident_confirmation(): void
    {
        Config::set('monitoring.notify_email', '');
        $monitor = $this->createMonitor('https://broken-certificate.example/landing');
        $probe = $this->mock(TlsCertificateProbe::class);
        $probe->shouldReceive('inspect')
            ->once()
            ->andReturn(new TlsCertificateResult(null, 'tls_handshake', 'TLS 交握失敗。'));

        (new CheckTlsCertificate($monitor->id))->handle($probe, app(IncidentManager::class));

        $this->assertDatabaseHas('tls_observations', [
            'monitor_id' => $monitor->id,
            'error_type' => 'tls_handshake',
        ]);
        $this->assertDatabaseHas('incidents', [
            'monitor_id' => $monitor->id,
            'rule_key' => 'tls_handshake',
            'state' => Incident::STATE_SUSPECT,
        ]);
    }

    public function test_daily_tls_result_is_visible_before_the_first_http_check(): void
    {
        $monitor = $this->createMonitor('https://first-check.example/landing');
        $probe = $this->mock(TlsCertificateProbe::class);
        $probe->shouldReceive('inspect')
            ->once()
            ->andReturn(new TlsCertificateResult(now()->addDays(90)->toDateTimeImmutable(), null, null));
        (new CheckTlsCertificate($monitor->id))->handle($probe, app(IncidentManager::class));

        $this->actingAs(User::factory()->create())
            ->get(route('monitors.show', $monitor))
            ->assertOk()
            ->assertSee('尚無檢查結果')
            ->assertSee('每日 TLS 獨立檢查')
            ->assertSee('憑證到期');
    }

    private function createMonitor(string $url, bool $enabled = true): Monitor
    {
        return Monitor::factory()->create([
            'url' => $url,
            'url_hash' => Monitor::fingerprintUrl($url),
            'enabled' => $enabled,
        ]);
    }
}
