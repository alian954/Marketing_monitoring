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
            ->assertSee('class="dashboard-overview-value">2</p>', false)
            ->assertDontSee('dashboard-focus-value');

        $this->assertSame(501, $response->viewData('monitorCount'));
        $this->assertSame(501, $response->viewData('fleetAvailability')['monitor_count']);
        $this->assertSame(501, $response->viewData('fleetAvailability')['slo_budget_summary']['monitor_count']);
        $this->assertSame(501, $response->viewData('fleetAvailability')['slo_budget_summary']['flagged_monitor_count']);
        $this->assertCount(10, $response->viewData('fleetAvailability')['slo_budget_summary']['priority_monitors']);
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

    public function test_dashboard_prioritizes_stale_monitors_above_healthy_monitors(): void
    {
        for ($monitorNumber = 1; $monitorNumber <= 7; $monitorNumber++) {
            $monitor = $this->createMonitor(
                sprintf('Healthy monitor %03d', $monitorNumber),
                sprintf('https://healthy-%03d.example.test/', $monitorNumber),
            );
            $monitor->forceFill(['last_checked_at' => now()])->save();
            CheckRun::factory()->for($monitor)->create([
                'normal_status' => 200,
                'scheduled_at' => now(),
            ]);
        }

        $warningMonitor = $this->createMonitor('Warning monitor', 'https://warning.example.test/');
        $warningMonitor->forceFill(['last_checked_at' => now()])->save();
        CheckRun::factory()->for($warningMonitor)->create([
            'normal_status' => 200,
            'adsbot_status' => 503,
            'scheduled_at' => now(),
        ]);

        $staleMonitor = $this->createMonitor('Z stale monitor', 'https://stale.example.test/');
        $staleMonitor->forceFill(['last_checked_at' => now()->subHour()])->save();

        $response = $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Warning monitor')
            ->assertSee('Z stale monitor');

        $dashboardMonitors = $response->viewData('monitors');

        $this->assertSame(['Warning monitor', 'Z stale monitor'], $dashboardMonitors->take(2)->pluck('name')->all());
        $this->assertSame('warning', $dashboardMonitors->first()->getAttribute('health_state'));
        $this->assertSame('stale', $dashboardMonitors->get(1)->getAttribute('health_state'));
        $this->assertCount(8, $dashboardMonitors);
        $this->assertFalse(in_array('Healthy monitor 007', $dashboardMonitors->pluck('name')->all(), true));
        $this->assertTrue(in_array(
            'Healthy monitor 007',
            array_column($response->viewData('fleetAvailability')['slo_budget_summary']['priority_monitors'], 'name'),
            true,
        ));
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
