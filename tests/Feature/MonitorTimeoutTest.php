<?php

namespace Tests\Feature;

use App\Jobs\RunMonitorCheck;
use App\Models\CheckRun;
use App\Models\Monitor;
use App\Models\User;
use App\Services\IncidentManager;
use App\Services\Probe\HopResponse;
use App\Services\Probe\HostResolver;
use App\Services\Probe\ProbeClient;
use App\Services\Probe\ProbeResult;
use App\Services\Probe\ProbeTransport;
use App\Services\Probe\SecureProbeClient;
use App\Services\Probe\UrlSafetyPolicy;
use App\Services\RunScheduler;
use App\Services\UrlMasker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class MonitorTimeoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_monitor_form_uses_the_configured_default_and_exposes_the_supported_range(): void
    {
        Config::set('monitoring.request_timeout_seconds', 8);

        $this->actingAs(User::factory()->create())
            ->get(route('monitors.create'))
            ->assertOk()
            ->assertSee('name="timeout_seconds"', false)
            ->assertSee('min="1" max="15"', false)
            ->assertSee('value="8"', false);
    }

    public function test_create_persists_timeout_and_scheduler_snapshot_and_shows_the_setting(): void
    {
        Config::set('monitoring.transport', 'fixture');
        Queue::fake([RunMonitorCheck::class]);

        $this->actingAs(User::factory()->create())
            ->post(route('monitors.store'), [
                'name' => 'Slow campaign page',
                'url' => 'https://fixture.test/ok',
                'timeout_seconds' => 15,
                'response_time_threshold_ms' => 14000,
            ])
            ->assertSessionHasNoErrors();

        $monitor = Monitor::query()->sole();

        $this->assertSame(15, $monitor->timeout_seconds);
        $this->assertSame(14000, $monitor->response_time_threshold_ms);
        $this->actingAs(User::factory()->create())
            ->get(route('monitors.show', $monitor))
            ->assertOk()
            ->assertSee('請求逾時上限')
            ->assertSee('15 秒');

        $run = app(RunScheduler::class)->enqueue($monitor, 'manual');
        $this->assertNotNull($run);

        $snapshot = json_decode($run->config_snapshot, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(15, $snapshot['timeout_seconds']);
    }

    public function test_timeout_update_supersedes_queued_runs_and_applies_to_the_next_snapshot(): void
    {
        Config::set('monitoring.transport', 'fixture');
        Queue::fake([RunMonitorCheck::class]);
        $monitor = $this->createMonitor(['timeout_seconds' => 5]);
        $oldRun = app(RunScheduler::class)->enqueue($monitor, 'manual');
        $this->assertNotNull($oldRun);

        $this->actingAs(User::factory()->create())
            ->put(route('monitors.update', $monitor), [
                'name' => 'Updated timeout target',
                'timeout_seconds' => 12,
            ])
            ->assertRedirect(route('monitors.show', $monitor));

        $this->assertSame(12, $monitor->fresh()->timeout_seconds);
        $this->assertDatabaseHas('check_runs', [
            'id' => $oldRun->id,
            'status' => 'stale_config',
        ]);

        $nextRun = app(RunScheduler::class)->enqueue($monitor->fresh(), 'manual');
        $this->assertNotNull($nextRun);
        $snapshot = json_decode($nextRun->config_snapshot, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(12, $snapshot['timeout_seconds']);
    }

    public function test_timeout_and_response_threshold_values_are_validated_together(): void
    {
        Config::set('monitoring.transport', 'fixture');
        $user = User::factory()->create();

        foreach ([0, 16] as $timeoutSeconds) {
            $this->actingAs($user)
                ->from(route('monitors.create'))
                ->post(route('monitors.store'), [
                    'name' => 'Invalid timeout',
                    'url' => 'https://fixture.test/ok',
                    'timeout_seconds' => $timeoutSeconds,
                ])
                ->assertRedirect(route('monitors.create'))
                ->assertSessionHasErrors(['timeout_seconds']);
        }

        $this->actingAs($user)
            ->from(route('monitors.create'))
            ->post(route('monitors.store'), [
                'name' => 'Threshold at timeout',
                'url' => 'https://fixture.test/ok',
                'timeout_seconds' => 5,
                'response_time_threshold_ms' => 5000,
            ])
            ->assertRedirect(route('monitors.create'))
            ->assertSessionHasErrors(['response_time_threshold_ms']);

        $this->assertDatabaseCount('monitors', 0);
    }

    public function test_check_job_passes_saved_timeout_to_both_http_probes(): void
    {
        Queue::fake([RunMonitorCheck::class]);
        $monitor = $this->createMonitor([
            'timeout_seconds' => 7,
            'expected_text' => 'Campaign headline',
        ]);
        $run = app(RunScheduler::class)->enqueue($monitor, 'manual');
        $this->assertNotNull($run);

        $normal = new ProbeResult(200, 31, 'https://example.com/landing', [], ['93.184.216.34'], null, null, true, null);
        $adsbot = new ProbeResult(200, 29, 'https://example.com/landing', [], ['93.184.216.34'], null, null, null, null);
        $probe = $this->mock(ProbeClient::class);
        $probe->shouldReceive('inspect')
            ->once()
            ->with($monitor->url, [], 'MarketingMonitor/1.0 (+authorized landing page check)', 'Campaign headline', 7, 'present')
            ->andReturn($normal);
        $probe->shouldReceive('inspect')
            ->once()
            ->with($monitor->url, [], 'AdsBot-Google (+http://www.google.com/adsbot.html)', null, 7)
            ->andReturn($adsbot);

        (new RunMonitorCheck($run->id))->handle($probe, app(IncidentManager::class), app(RunScheduler::class));

        $this->assertDatabaseHas('check_runs', [
            'id' => $run->id,
            'status' => 'completed',
            'normal_status' => 200,
            'adsbot_status' => 200,
        ]);
    }

    public function test_rate_limited_check_honors_retry_after_for_confirmation_and_scheduled_runs(): void
    {
        Queue::fake([RunMonitorCheck::class]);
        foreach ([429, 503] as $statusCode) {
            $attemptedAt = now()->toImmutable();
            $monitor = $this->createMonitor([
                'interval_seconds' => 300,
                'confirmation_delay_seconds' => 30,
                'next_check_at' => $attemptedAt->addMinutes(5),
            ]);
            $run = app(RunScheduler::class)->enqueue($monitor, 'manual');
            $this->assertNotNull($run);
            $normal = new ProbeResult(
                statusCode: $statusCode,
                elapsedMs: 45,
                finalUrl: $monitor->url,
                redirects: [],
                resolvedPublicIps: ['93.184.216.34'],
                errorType: null,
                errorDetail: null,
                expectedTextFound: null,
                tlsExpiresAt: null,
                monitorHostIps: ['93.184.216.34'],
                retryAfterSeconds: 3600,
            );
            $adsbot = new ProbeResult(200, 35, $monitor->url, [], ['93.184.216.34'], null, null, null, null);
            $probe = $this->mock(ProbeClient::class);
            $probe->shouldReceive('inspect')
                ->once()
                ->with($monitor->url, [], 'MarketingMonitor/1.0 (+authorized landing page check)', null, 10, 'present')
                ->andReturn($normal);
            $probe->shouldReceive('inspect')
                ->once()
                ->with($monitor->url, [], 'AdsBot-Google (+http://www.google.com/adsbot.html)', null, 10)
                ->andReturn($adsbot);

            (new RunMonitorCheck($run->id))->handle($probe, app(IncidentManager::class), app(RunScheduler::class));

            $confirmationRun = CheckRun::query()
                ->where('monitor_id', $monitor->id)
                ->where('source', 'confirmation')
                ->sole();
            $this->assertGreaterThanOrEqual($attemptedAt->addHour()->timestamp, $confirmationRun->scheduled_at->timestamp);
            $this->assertGreaterThanOrEqual(
                $attemptedAt->addMinutes(65)->timestamp,
                $monitor->fresh()->next_check_at->timestamp,
            );
        }

        Queue::assertPushed(RunMonitorCheck::class, 4);
    }

    public function test_secure_probe_uses_the_monitor_timeout_for_every_redirect_hop(): void
    {
        Config::set('monitoring.request_timeout_seconds', 2);
        $resolver = new class implements HostResolver
        {
            public function resolve(string $hostname): array
            {
                return ['93.184.216.34'];
            }
        };
        $transport = new class implements ProbeTransport
        {
            /** @var list<int> */
            public array $budgets = [];

            public function get(array $target, string $userAgent, int $timeoutMs): HopResponse
            {
                $this->budgets[] = $timeoutMs;

                return count($this->budgets) === 1
                    ? new HopResponse(302, '/final', '', 1, null, null, null)
                    : new HopResponse(200, null, '<html>ok</html>', 1, null, null, null);
            }
        };
        $client = new SecureProbeClient(new UrlSafetyPolicy($resolver), $transport, new UrlMasker);

        $result = $client->inspect('https://landing.example', [], 'MonitorTest/1.0', null, 5);

        $this->assertSame(200, $result->statusCode);
        $this->assertCount(2, $transport->budgets);
        $this->assertGreaterThan(4000, $transport->budgets[0]);
        $this->assertGreaterThan(4000, $transport->budgets[1]);
    }

    /** @param array<string, mixed> $attributes */
    private function createMonitor(array $attributes = []): Monitor
    {
        $url = 'https://fixture.test/monitor/'.Str::uuid();

        return Monitor::factory()->create([
            ...$attributes,
            'url' => $url,
            'url_hash' => Monitor::fingerprintUrl($url),
        ]);
    }
}
