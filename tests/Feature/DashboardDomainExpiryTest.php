<?php

namespace Tests\Feature;

use App\Models\DomainRegistrationObservation;
use App\Models\Monitor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DashboardDomainExpiryTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_lists_expired_and_expiring_domains_using_the_latest_observation(): void
    {
        $this->travelTo(Carbon::parse('2026-11-19 04:00:00', 'UTC'));
        $expiredMonitor = $this->createMonitor('Expired domain', 'https://expired.example/landing');
        $expiringMonitor = $this->createMonitor('Expiring domain', 'https://expiring.example/landing');
        $staleMonitor = $this->createMonitor('Stale domain data', 'https://stale.example/landing');
        $safeMonitor = $this->createMonitor('Safe domain', 'https://safe.example/landing');
        $updatedMonitor = $this->createMonitor('Latest domain observation', 'https://latest.example/landing');
        $socialMonitor = $this->createMonitor('Social post', 'https://www.instagram.com/p/123456/');
        $unknownMonitor = $this->createMonitor('Unknown domain', 'https://unknown.example/landing');

        $this->createObservation($expiredMonitor, now()->subDays(2));
        $this->createObservation($expiringMonitor, now()->addDays(10));
        $this->createObservation($staleMonitor, now()->addDays(5), observedAt: now()->subHours(49));
        $this->createObservation($safeMonitor, now()->addDays(31));
        $this->createObservation($updatedMonitor, now()->addDays(5), observedOn: today()->subDay());
        $this->createObservation($updatedMonitor, now()->addDays(60));
        $this->createObservation($socialMonitor, now()->addDays(5));
        DomainRegistrationObservation::factory()->for($unknownMonitor)->create([
            'observed_on' => today(),
            'domain_name' => null,
            'expires_at' => null,
            'error_type' => 'rdap_unsupported',
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('網域註冊期限')
            ->assertSee('Expired domain')
            ->assertSee('Expiring domain')
            ->assertSee('Stale domain data')
            ->assertSee('已到期')
            ->assertSee('資料逾時，需重新查詢')
            ->assertSee('剩餘 10 天');

        $this->assertSame(3, $response->viewData('expiringDomainCount'));
        $this->assertSame(
            ['Expired domain', 'Stale domain data', 'Expiring domain'],
            $response->viewData('expiringDomains')->pluck('monitor.name')->all(),
        );
        $domainsByMonitorName = $response->viewData('expiringDomains')->keyBy('monitor.name');
        $this->assertTrue($domainsByMonitorName['Stale domain data']['is_stale']);
        $this->assertFalse($domainsByMonitorName['Stale domain data']['is_expired']);
        $this->assertNull($domainsByMonitorName['Stale domain data']['days_until_expiry']);
        $this->assertNotContains('Latest domain observation', $response->viewData('expiringDomains')->pluck('monitor.name')->all());
    }

    public function test_dashboard_limits_the_domain_expiry_table_to_the_ten_earliest_domains(): void
    {
        $this->travelTo(Carbon::parse('2026-11-19 04:00:00', 'UTC'));

        for ($domainNumber = 1; $domainNumber <= 11; $domainNumber++) {
            $monitor = $this->createMonitor('Domain '.$domainNumber, 'https://domain-'.$domainNumber.'.example/');
            $this->createObservation($monitor, now()->addDays($domainNumber));
        }

        $response = $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('另有 1 個網域未列出');

        $this->assertSame(11, $response->viewData('expiringDomainCount'));
        $this->assertCount(10, $response->viewData('expiringDomains'));
        $this->assertSame(
            ['Domain 1', 'Domain 2', 'Domain 3', 'Domain 4', 'Domain 5', 'Domain 6', 'Domain 7', 'Domain 8', 'Domain 9', 'Domain 10'],
            $response->viewData('expiringDomains')->pluck('monitor.name')->all(),
        );
    }

    public function test_monitor_details_show_the_latest_domain_expiry_result_and_unknown_status(): void
    {
        $monitor = $this->createMonitor('Domain with unsupported RDAP', 'https://unsupported.example/landing');
        DomainRegistrationObservation::factory()->for($monitor)->create([
            'observed_on' => today(),
            'domain_name' => null,
            'expires_at' => null,
            'error_type' => 'rdap_expiration_unavailable',
        ]);

        $this->actingAs(User::factory()->create())
            ->get(route('monitors.show', $monitor))
            ->assertOk()
            ->assertSee('每日網域註冊期限檢查')
            ->assertSee('註冊機構未提供網域到期日')
            ->assertSee('尚未判定');
    }

    private function createMonitor(string $name, string $url): Monitor
    {
        return Monitor::factory()->create([
            'name' => $name,
            'url' => $url,
            'url_hash' => Monitor::fingerprintUrl($url),
        ]);
    }

    private function createObservation(
        Monitor $monitor,
        Carbon $expiresAt,
        ?Carbon $observedOn = null,
        ?Carbon $observedAt = null,
    ): DomainRegistrationObservation {
        return DomainRegistrationObservation::factory()->for($monitor)->create([
            'observed_on' => $observedOn ?? today(),
            'domain_name' => 'example.com',
            'expires_at' => $expiresAt,
            'error_type' => null,
            'updated_at' => $observedAt ?? now(),
        ]);
    }
}
