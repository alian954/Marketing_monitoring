<?php

namespace Tests\Feature;

use App\Models\Monitor;
use App\Models\TlsObservation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DashboardTlsExpiryTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_lists_expired_and_expiring_certificates_using_latest_observations(): void
    {
        $this->travelTo(Carbon::parse('2026-11-19 04:00:00', 'UTC'));
        $expiredMonitor = $this->createMonitor('Expired certificate');
        $expiringMonitor = $this->createMonitor('Expiring certificate');
        $boundaryMonitor = $this->createMonitor('Thirty day certificate');
        $freshnessBoundaryMonitor = $this->createMonitor('Forty eight hour observation');
        $staleMonitor = $this->createMonitor('Stale observation');
        $safeMonitor = $this->createMonitor('Safe certificate');
        $updatedMonitor = $this->createMonitor('Updated certificate');
        $failedMonitor = $this->createMonitor('Unobserved certificate');

        $this->createObservation($expiredMonitor, now(), now()->subDays(2));
        $this->createObservation($expiringMonitor, now(), now()->addDays(10));
        $this->createObservation($boundaryMonitor, now(), now()->addDays(30));
        $this->createObservation($freshnessBoundaryMonitor, now()->subDays(2), now()->addDays(20), recordedAt: now()->subHours(48));
        $this->createObservation($staleMonitor, now()->subDays(3), now()->addDays(5), recordedAt: now()->subHours(49));
        $this->createObservation($safeMonitor, now(), now()->addDays(31));
        $this->createObservation($updatedMonitor, now()->subDays(2), now()->addDays(5));
        $this->createObservation($updatedMonitor, now(), now()->addDays(60));
        $this->createObservation($failedMonitor, now(), null, 'tls_handshake');

        $response = $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('TLS 憑證期限')
            ->assertSee('Expired certificate')
            ->assertSee('Expiring certificate')
            ->assertSee('Thirty day certificate')
            ->assertSee('Stale observation')
            ->assertSee('已過期')
            ->assertSee('資料逾時，需重新檢查')
            ->assertSee('剩餘 10 天')
            ->assertSee('剩餘 30 天');

        $this->assertSame(5, $response->viewData('expiringCertificateCount'));
        $this->assertSame(
            ['Expired certificate', 'Stale observation', 'Expiring certificate', 'Forty eight hour observation', 'Thirty day certificate'],
            $response->viewData('expiringCertificates')->pluck('monitor.name')->all(),
        );
        $certificatesByMonitorName = $response->viewData('expiringCertificates')->keyBy('monitor.name');
        $this->assertTrue($certificatesByMonitorName['Stale observation']['is_stale']);
        $this->assertFalse($certificatesByMonitorName['Stale observation']['is_expired']);
        $this->assertNull($certificatesByMonitorName['Stale observation']['days_until_expiry']);
        $this->assertFalse($certificatesByMonitorName['Forty eight hour observation']['is_stale']);
    }

    public function test_dashboard_limits_the_expiry_table_to_the_ten_earliest_certificates(): void
    {
        $this->travelTo(Carbon::parse('2026-11-19 04:00:00', 'UTC'));

        for ($certificateNumber = 1; $certificateNumber <= 11; $certificateNumber++) {
            $monitor = $this->createMonitor('Expiry test '.$certificateNumber);
            $this->createObservation($monitor, now(), now()->addDays($certificateNumber));
        }

        $response = $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('另有 1 張憑證未列出');

        $this->assertSame(11, $response->viewData('expiringCertificateCount'));
        $this->assertCount(10, $response->viewData('expiringCertificates'));
        $this->assertSame(
            ['Expiry test 1', 'Expiry test 2', 'Expiry test 3', 'Expiry test 4', 'Expiry test 5', 'Expiry test 6', 'Expiry test 7', 'Expiry test 8', 'Expiry test 9', 'Expiry test 10'],
            $response->viewData('expiringCertificates')->pluck('monitor.name')->all(),
        );
    }

    public function test_dashboard_shows_an_empty_state_when_no_saved_certificate_expires_within_thirty_days(): void
    {
        $this->travelTo(Carbon::parse('2026-11-19 04:00:00', 'UTC'));
        $monitor = $this->createMonitor('Healthy certificate');
        $this->createObservation($monitor, now(), now()->addDays(31));

        $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('沒有即將到期的憑證')
            ->assertSee('Healthy certificate');
    }

    public function test_dashboard_does_not_report_tls_expiry_for_social_post_urls(): void
    {
        $this->travelTo(Carbon::parse('2026-11-19 04:00:00', 'UTC'));
        $socialMonitor = Monitor::factory()->create([
            'name' => 'Social post certificate',
            'url' => 'https://www.facebook.com/share/p/12345/',
            'url_hash' => Monitor::fingerprintUrl('https://www.facebook.com/share/p/12345/'),
        ]);
        $this->createObservation($socialMonitor, now(), now()->addDays(5));

        $response = $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('沒有即將到期的憑證')
            ->assertSee('Social post certificate');

        $this->assertSame(0, $response->viewData('expiringCertificateCount'));
        $this->assertTrue($response->viewData('expiringCertificates')->isEmpty());

        $responseContent = $response->getContent();
        $certificateSectionStart = strpos($responseContent, 'id="tls-expiry-title"');
        $domainSectionStart = strpos($responseContent, 'id="domain-expiry-title"');
        $certificateSection = substr($responseContent, $certificateSectionStart, $domainSectionStart - $certificateSectionStart);

        $this->assertIsInt($certificateSectionStart);
        $this->assertIsInt($domainSectionStart);
        $this->assertStringNotContainsString('Social post certificate', $certificateSection);
    }

    private function createObservation(
        Monitor $monitor,
        Carbon $observedOn,
        ?Carbon $expiresAt,
        ?string $errorType = null,
        ?Carbon $recordedAt = null,
    ): TlsObservation {
        $observation = TlsObservation::query()->create([
            'monitor_id' => $monitor->id,
            'observed_on' => $observedOn->toDateString(),
            'expires_at' => $expiresAt,
            'error_type' => $errorType,
        ]);

        if ($recordedAt !== null) {
            TlsObservation::query()
                ->whereKey($observation->getKey())
                ->update(['created_at' => $recordedAt, 'updated_at' => $recordedAt]);

            return TlsObservation::query()->findOrFail($observation->getKey());
        }

        return $observation;
    }

    private function createMonitor(string $name): Monitor
    {
        $url = 'https://'.str()->slug($name).'.example.test/';

        return Monitor::factory()->create([
            'name' => $name,
            'url' => $url,
            'url_hash' => Monitor::fingerprintUrl($url),
        ]);
    }
}
