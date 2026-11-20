<?php

namespace Tests\Feature;

use App\Models\CheckRun;
use App\Models\DomainRegistrationObservation;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\TlsObservation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardScalabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_prioritizes_active_incidents_and_limits_the_display_list(): void
    {
        $healthyMonitor = $this->createMonitor('A healthy monitor', 'https://healthy.example.test/');
        $healthyMonitor->forceFill(['last_checked_at' => now()])->save();
        CheckRun::factory()->for($healthyMonitor)->create([
            'normal_status' => 200,
            'scheduled_at' => now(),
        ]);

        for ($monitorNumber = 1; $monitorNumber <= 497; $monitorNumber++) {
            $this->createMonitor(
                sprintf('Pending monitor %03d', $monitorNumber),
                sprintf('https://pending-%03d.example.test/', $monitorNumber),
            );
        }

        $staleConfigMonitor = $this->createMonitor('0 stale configuration incident', 'https://stale-config.example.test/');
        Incident::factory()->for($staleConfigMonitor)->create([
            'config_version' => $staleConfigMonitor->config_version - 1,
            'severity' => 'critical',
            'state' => Incident::STATE_OPEN,
        ]);

        $issueMonitor = $this->createMonitor('ZZZ issue monitor', 'https://issue.example.test/');
        $issueMonitor->forceFill(['last_checked_at' => now()])->save();
        CheckRun::factory()->for($issueMonitor)->create([
            'normal_status' => 503,
            'error_type' => 'normal_http_status',
            'scheduled_at' => now(),
        ]);
        Incident::factory()->for($issueMonitor)->create([
            'config_version' => $issueMonitor->config_version,
            'severity' => 'critical',
            'state' => Incident::STATE_OPEN,
        ]);
        TlsObservation::factory()->for($issueMonitor)->create([
            'expires_at' => now()->addDays(20),
        ]);
        DomainRegistrationObservation::factory()->for($issueMonitor)->create([
            'domain_name' => 'issue.example.test',
            'expires_at' => now()->addDays(25),
        ]);
        $warningMonitor = $this->createMonitor('A warning monitor', 'https://warning.example.test/');
        $warningMonitor->forceFill(['last_checked_at' => now()])->save();
        Incident::factory()->for($warningMonitor)->create([
            'config_version' => $warningMonitor->config_version,
            'severity' => 'warning',
            'state' => Incident::STATE_OPEN,
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('目前共 501 個網站或公開貼文');

        $this->assertSame(501, $response->viewData('monitorCount'));
        $this->assertSame(501, $response->viewData('fleetAvailability')['monitor_count']);
        $this->assertCount(8, $response->viewData('monitors'));
        $this->assertSame(['ZZZ issue monitor', 'A warning monitor'], $response->viewData('monitors')->take(2)->pluck('name')->all());
        $this->assertSame(1, $response->viewData('counts')['healthy']);
        $this->assertSame(1, $response->viewData('counts')['issue']);
        $this->assertSame(1, $response->viewData('counts')['warning']);
        $this->assertSame(1, $response->viewData('expiringCertificateCount'));
        $this->assertSame(['ZZZ issue monitor'], $response->viewData('expiringCertificates')->pluck('monitor.name')->all());
        $this->assertSame(1, $response->viewData('expiringDomainCount'));
        $this->assertSame(['ZZZ issue monitor'], $response->viewData('expiringDomains')->pluck('monitor.name')->all());
    }

    private function createMonitor(string $name, string $url): Monitor
    {
        return Monitor::factory()->create([
            'name' => $name,
            'url' => $url,
            'url_hash' => Monitor::fingerprintUrl($url),
        ]);
    }
}
