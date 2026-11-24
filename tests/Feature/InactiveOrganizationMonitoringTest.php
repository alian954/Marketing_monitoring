<?php

namespace Tests\Feature;

use App\Jobs\CheckDomainRegistrationExpiry;
use App\Jobs\CheckRobotsTxt;
use App\Jobs\CheckTlsCertificate;
use App\Jobs\RunMonitorCheck;
use App\Models\CheckRun;
use App\Models\Client;
use App\Models\Monitor;
use App\Models\Organization;
use App\Models\User;
use App\Services\IncidentManager;
use App\Services\Probe\DomainExpiryProbe;
use App\Services\Probe\ProbeClient;
use App\Services\Probe\RobotsTxtProbe;
use App\Services\Probe\TlsCertificateProbe;
use App\Services\RunScheduler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class InactiveOrganizationMonitoringTest extends TestCase
{
    use RefreshDatabase;

    public function test_inactive_organizations_are_excluded_from_monitor_dispatchers_and_enqueue(): void
    {
        $this->travelTo('2026-11-22 12:00:00');
        Queue::fake();

        foreach ([
            ['is_enabled' => false, 'subscription_ends_on' => null],
            ['is_enabled' => true, 'subscription_ends_on' => '2026-11-21'],
        ] as $organizationAttributes) {
            $owner = User::factory()->create();
            Organization::factory()->for($owner, 'owner')->create($organizationAttributes);
            $monitor = $this->createOwnedMonitor($owner, 'https://inactive-'.$owner->getKey().'.example/landing');

            $this->assertNull(app(RunScheduler::class)->enqueue($monitor, 'manual'));
        }

        $this->artisan('monitors:dispatch-due')->assertExitCode(0);
        $this->artisan('monitors:dispatch-tls')->assertExitCode(0);
        $this->artisan('monitors:dispatch-domain-expiry')->assertExitCode(0);
        $this->artisan('monitors:dispatch-robots')->assertExitCode(0);

        $this->assertDatabaseCount('check_runs', 0);
        Queue::assertNotPushed(RunMonitorCheck::class);
        Queue::assertNotPushed(CheckTlsCertificate::class);
        Queue::assertNotPushed(CheckDomainRegistrationExpiry::class);
        Queue::assertNotPushed(CheckRobotsTxt::class);
    }

    public function test_queued_site_check_is_paused_after_organization_is_disabled(): void
    {
        $owner = User::factory()->create();
        $organization = Organization::factory()->for($owner, 'owner')->create();
        $monitor = $this->createOwnedMonitor($owner, 'https://paused.example/landing');
        $run = CheckRun::factory()->for($monitor)->create(['status' => 'queued']);
        $organization->update(['is_enabled' => false]);

        $probe = $this->mock(ProbeClient::class);
        $probe->shouldNotReceive('inspect');

        (new RunMonitorCheck($run->getKey()))->handle(
            $probe,
            app(IncidentManager::class),
            app(RunScheduler::class),
        );

        $this->assertDatabaseHas('check_runs', [
            'id' => $run->getKey(),
            'status' => 'paused',
        ]);
        $this->assertNotNull($run->fresh()->finished_at);
    }

    public function test_queued_tls_domain_and_robots_checks_skip_an_expired_organization(): void
    {
        $this->travelTo('2026-11-22 12:00:00');
        $owner = User::factory()->create();
        Organization::factory()->for($owner, 'owner')->create([
            'subscription_ends_on' => '2026-11-21',
        ]);
        $monitor = $this->createOwnedMonitor($owner, 'https://expired.example/landing');

        $tlsProbe = $this->mock(TlsCertificateProbe::class);
        $tlsProbe->shouldNotReceive('inspect');
        $domainProbe = $this->mock(DomainExpiryProbe::class);
        $domainProbe->shouldNotReceive('inspect');
        $robotsProbe = $this->mock(RobotsTxtProbe::class);
        $robotsProbe->shouldNotReceive('inspect');

        (new CheckTlsCertificate($monitor->getKey()))->handle($tlsProbe, app(IncidentManager::class));
        (new CheckDomainRegistrationExpiry($monitor->getKey()))->handle($domainProbe, app(IncidentManager::class));
        (new CheckRobotsTxt($monitor->getKey()))->handle($robotsProbe);

        $this->assertDatabaseMissing('tls_observations', ['monitor_id' => $monitor->getKey()]);
        $this->assertDatabaseMissing('domain_registration_observations', ['monitor_id' => $monitor->getKey()]);
        $this->assertDatabaseMissing('robots_observations', ['monitor_id' => $monitor->getKey()]);
    }

    private function createOwnedMonitor(User $owner, string $url): Monitor
    {
        $client = Client::ensureDefaultFor($owner);
        $monitor = Monitor::factory()->create([
            'url' => $url,
            'url_hash' => Monitor::fingerprintUrl($url),
            'client_id' => $client->getKey(),
            'next_check_at' => now()->subMinute(),
        ]);
        $monitor->forceFill(['user_id' => $owner->getKey()])->save();

        return $monitor;
    }
}
