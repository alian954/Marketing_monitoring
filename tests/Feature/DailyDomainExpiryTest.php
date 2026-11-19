<?php

namespace Tests\Feature;

use App\Jobs\CheckDomainRegistrationExpiry;
use App\Models\CheckRun;
use App\Models\DomainRegistrationObservation;
use App\Models\Incident;
use App\Models\Monitor;
use App\Services\IncidentManager;
use App\Services\Probe\DomainExpiryProbe;
use App\Services\Probe\DomainExpiryResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schedule;
use Tests\TestCase;

class DailyDomainExpiryTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatcher_queues_enabled_website_monitors_without_todays_observation(): void
    {
        Queue::fake();

        $eligibleMonitor = $this->createMonitor('https://www.eligible.example/landing');
        $this->createMonitor('https://paused.example/landing', false);
        $this->createMonitor('https://www.instagram.com/p/123456/');
        $this->createMonitor('https://192.0.2.10/landing');
        $observedMonitor = $this->createMonitor('https://observed.example/landing');
        DomainRegistrationObservation::factory()->for($observedMonitor)->create(['observed_on' => today()]);

        $this->artisan('monitors:dispatch-domain-expiry')
            ->assertExitCode(0)
            ->expectsOutputToContain('已將 1 個網域註冊期限檢查加入佇列。');

        Queue::assertPushed(CheckDomainRegistrationExpiry::class, 1);
        Queue::assertPushed(CheckDomainRegistrationExpiry::class, fn (CheckDomainRegistrationExpiry $job): bool => $job->monitorId === $eligibleMonitor->id);
    }

    public function test_job_records_daily_result_and_applies_expiry_incident_thresholds_once(): void
    {
        $monitor = $this->createMonitor('https://www.expiring.example/landing');
        $regularRun = CheckRun::factory()->for($monitor)->create(['scheduled_at' => now()->subHour()]);
        $expiration = now()->addDays(3)->toDateTimeImmutable();
        $probe = $this->mock(DomainExpiryProbe::class);
        $probe->shouldReceive('inspect')
            ->once()
            ->with($monitor->url)
            ->andReturn(new DomainExpiryResult('example.com', $expiration, null, null));

        $job = new CheckDomainRegistrationExpiry($monitor->id);
        $job->handle($probe, app(IncidentManager::class));
        $job->handle($probe, app(IncidentManager::class));

        $this->assertDatabaseCount('domain_registration_observations', 1);
        $this->assertDatabaseHas('domain_registration_observations', [
            'monitor_id' => $monitor->id,
            'observed_on' => today()->startOfDay()->toDateTimeString(),
            'domain_name' => 'example.com',
            'error_type' => null,
        ]);
        $this->assertDatabaseHas('check_runs', [
            'monitor_id' => $monitor->id,
            'source' => 'domain_daily',
            'status' => 'completed',
        ]);
        $this->assertDatabaseHas('incidents', [
            'monitor_id' => $monitor->id,
            'rule_key' => 'domain_7d',
            'state' => Incident::STATE_SUSPECT,
        ]);
        $this->assertSame($regularRun->id, $monitor->fresh()->latestCheckRun->id);
    }

    public function test_job_routes_expired_and_7_14_30_day_dates_to_the_correct_incident_rules(): void
    {
        $monitorsByHost = [];
        $expectedRuleByHost = [];
        $dayOffsets = [-1 => 'domain_expired', 3 => 'domain_7d', 10 => 'domain_14d', 20 => 'domain_30d', 60 => null];

        foreach ($dayOffsets as $daysUntilExpiry => $expectedRule) {
            $host = 'domain-'.$daysUntilExpiry.'.example.com';
            $monitor = $this->createMonitor('https://'.$host.'/');
            $monitorsByHost[$host] = $monitor;
            $expectedRuleByHost[$host] = $expectedRule;
        }

        $probe = $this->mock(DomainExpiryProbe::class);
        $probe->shouldReceive('inspect')->times(count($monitorsByHost))->andReturnUsing(function (string $url) use ($expectedRuleByHost): DomainExpiryResult {
            $host = (string) parse_url($url, PHP_URL_HOST);
            $daysUntilExpiry = match ($expectedRuleByHost[$host]) {
                'domain_expired' => -1,
                'domain_7d' => 3,
                'domain_14d' => 10,
                'domain_30d' => 20,
                default => 60,
            };

            return new DomainExpiryResult('example.com', now()->addDays($daysUntilExpiry)->toDateTimeImmutable(), null, null);
        });

        foreach ($monitorsByHost as $monitor) {
            (new CheckDomainRegistrationExpiry($monitor->id))->handle($probe, app(IncidentManager::class));
        }

        foreach ($expectedRuleByHost as $host => $expectedRule) {
            $monitor = $monitorsByHost[$host];

            if ($expectedRule === null) {
                $this->assertDatabaseMissing('incidents', ['monitor_id' => $monitor->id]);

                continue;
            }

            $this->assertDatabaseHas('incidents', [
                'monitor_id' => $monitor->id,
                'rule_key' => $expectedRule,
                'state' => Incident::STATE_SUSPECT,
            ]);
        }
    }

    public function test_unavailable_registry_data_is_saved_without_claiming_the_domain_is_expired(): void
    {
        $monitor = $this->createMonitor('https://registry-unavailable.example/');
        $probe = $this->mock(DomainExpiryProbe::class);
        $probe->shouldReceive('inspect')
            ->once()
            ->andReturn(new DomainExpiryResult(null, null, 'rdap_unavailable', 'RDAP 服務目前無法完成查詢。'));

        (new CheckDomainRegistrationExpiry($monitor->id))->handle($probe, app(IncidentManager::class));

        $this->assertDatabaseHas('domain_registration_observations', [
            'monitor_id' => $monitor->id,
            'error_type' => 'rdap_unavailable',
            'expires_at' => null,
        ]);
        $this->assertDatabaseMissing('incidents', ['monitor_id' => $monitor->id]);
    }

    public function test_daily_domain_expiry_dispatcher_is_scheduled_once_per_day(): void
    {
        $event = collect(Schedule::events())->first(
            static fn ($event): bool => str_contains((string) $event->command, 'monitors:dispatch-domain-expiry'),
        );

        $this->assertNotNull($event);
        $this->assertSame('0 4 * * *', $event->expression);
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
