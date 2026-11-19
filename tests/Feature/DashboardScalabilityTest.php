<?php

namespace Tests\Feature;

use App\Models\CheckRun;
use App\Models\DomainRegistrationObservation;
use App\Models\Monitor;
use App\Models\TlsObservation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardScalabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_counts_all_monitors_across_batches_and_limits_the_display_list(): void
    {
        $healthyMonitor = $this->createMonitor('A healthy monitor', 'https://healthy.example.test/');
        $healthyMonitor->forceFill(['last_checked_at' => now()])->save();
        CheckRun::factory()->for($healthyMonitor)->create([
            'normal_status' => 200,
            'scheduled_at' => now(),
        ]);

        for ($monitorNumber = 1; $monitorNumber <= 499; $monitorNumber++) {
            $this->createMonitor(
                sprintf('Pending monitor %03d', $monitorNumber),
                sprintf('https://pending-%03d.example.test/', $monitorNumber),
            );
        }

        $issueMonitor = $this->createMonitor('ZZZ issue monitor', 'https://issue.example.test/');
        $issueMonitor->forceFill(['last_checked_at' => now()])->save();
        CheckRun::factory()->for($issueMonitor)->create([
            'normal_status' => 503,
            'error_type' => 'normal_http_status',
            'scheduled_at' => now(),
        ]);
        TlsObservation::factory()->for($issueMonitor)->create([
            'expires_at' => now()->addDays(20),
        ]);
        DomainRegistrationObservation::factory()->for($issueMonitor)->create([
            'domain_name' => 'issue.example.test',
            'expires_at' => now()->addDays(25),
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('目前共 501 個網站或公開貼文');

        $this->assertSame(501, $response->viewData('monitorCount'));
        $this->assertCount(8, $response->viewData('monitors'));
        $this->assertSame(1, $response->viewData('counts')['healthy']);
        $this->assertSame(1, $response->viewData('counts')['issue']);
        $this->assertSame(1, $response->viewData('expiringCertificateCount'));
        $this->assertSame(['ZZZ issue monitor'], $response->viewData('expiringCertificates')->pluck('monitor.name')->all());
        $this->assertSame(1, $response->viewData('expiringDomainCount'));
        $this->assertSame(['ZZZ issue monitor'], $response->viewData('expiringDomains')->pluck('monitor.name')->all());
        $this->assertNotContains('ZZZ issue monitor', $response->viewData('monitors')->pluck('name')->all());
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
