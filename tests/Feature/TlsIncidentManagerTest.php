<?php

namespace Tests\Feature;

use App\Models\CheckRun;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\NotificationOutbox;
use App\Models\User;
use App\Services\IncidentManager;
use App\Services\Probe\ProbeResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class TlsIncidentManagerTest extends TestCase
{
    use RefreshDatabase;

    public function test_thirty_day_tls_warning_starts_at_the_boundary_and_appears_in_monitoring_views(): void
    {
        Config::set('monitoring.notify_email', 'owner@example.test');
        Queue::fake();
        $monitor = Monitor::factory()->create();
        $user = User::factory()->create();
        $manager = app(IncidentManager::class);
        $certificateExpiresAt = now()->addDays(31)->toDateTimeImmutable();
        $healthy = new ProbeResult(200, 50, 'https://example.com/landing', [], ['93.184.216.34'], null, null, null, null);
        $outsideWarningWindow = new ProbeResult(200, 50, 'https://example.com/landing', [], ['93.184.216.34'], null, null, null, $certificateExpiresAt);

        $manager->record($monitor, $this->createRun($monitor), $outsideWarningWindow, $healthy);

        $this->assertDatabaseMissing('incidents', ['monitor_id' => $monitor->id, 'rule_key' => 'tls_30d']);

        $this->travelTo(now()->addDay());
        $atThirtyDays = new ProbeResult(200, 50, 'https://example.com/landing', [], ['93.184.216.34'], null, null, null, $certificateExpiresAt);
        $manager->record($monitor, $this->createRun($monitor), $atThirtyDays, $healthy);
        $this->travelTo(now()->addSeconds(61));
        $manager->record($monitor, $this->createRun($monitor), $atThirtyDays, $healthy);

        $this->assertDatabaseHas('incidents', [
            'monitor_id' => $monitor->id,
            'rule_key' => 'tls_30d',
            'state' => Incident::STATE_OPEN,
        ]);
        $this->assertStringContainsString('原因：TLS 憑證將於 30 天內到期', NotificationOutbox::query()->sole()->body);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('TLS 憑證將於 30 天內到期');
        $this->actingAs($user)
            ->get(route('monitors.show', $monitor))
            ->assertOk()
            ->assertSee('TLS 憑證將於 30 天內到期');
        $this->actingAs($user)
            ->get(route('incidents.index', ['rule_key' => 'tls_30d']))
            ->assertOk()
            ->assertSee('TLS 憑證將於 30 天內到期');
    }

    public function test_an_available_expired_certificate_observation_opens_the_expired_rule(): void
    {
        Config::set('monitoring.notify_email', '');
        $monitor = Monitor::factory()->create();
        $manager = app(IncidentManager::class);
        $expired = new ProbeResult(
            null,
            50,
            'https://example.com/landing',
            [],
            ['93.184.216.34'],
            'tls_verify',
            'TLS 憑證驗證失敗。',
            null,
            now()->subDay()->toDateTimeImmutable(),
        );
        $healthy = new ProbeResult(200, 50, 'https://example.com/landing', [], ['93.184.216.34'], null, null, null, null);

        $manager->record($monitor, $this->createRun($monitor), $expired, $healthy);
        $this->travel(61)->seconds();
        $manager->record($monitor, $this->createRun($monitor), $expired, $healthy);

        $this->assertDatabaseHas('incidents', [
            'monitor_id' => $monitor->id,
            'rule_key' => 'tls_expired',
            'state' => Incident::STATE_OPEN,
        ]);
        $this->assertDatabaseMissing('incidents', ['monitor_id' => $monitor->id, 'rule_key' => 'tls_handshake']);
    }

    public function test_a_tls_handshake_without_an_expiry_observation_stays_a_handshake_rule(): void
    {
        Config::set('monitoring.notify_email', '');
        $monitor = Monitor::factory()->create();
        $manager = app(IncidentManager::class);
        $handshakeFailure = new ProbeResult(
            null,
            50,
            'https://example.com/landing',
            [],
            ['93.184.216.34'],
            'tls_handshake',
            'TLS 交握失敗。',
            null,
            null,
        );
        $healthy = new ProbeResult(200, 50, 'https://example.com/landing', [], ['93.184.216.34'], null, null, null, null);

        $manager->record($monitor, $this->createRun($monitor), $handshakeFailure, $healthy);
        $this->travel(61)->seconds();
        $manager->record($monitor, $this->createRun($monitor), $handshakeFailure, $healthy);

        $this->assertDatabaseHas('incidents', [
            'monitor_id' => $monitor->id,
            'rule_key' => 'tls_handshake',
            'state' => Incident::STATE_OPEN,
        ]);
        $this->assertDatabaseMissing('incidents', ['monitor_id' => $monitor->id, 'rule_key' => 'tls_expired']);
    }

    private function createRun(Monitor $monitor): CheckRun
    {
        return CheckRun::factory()->for($monitor)->create([
            'run_key' => hash('sha256', (string) Str::uuid()),
            'config_version' => $monitor->config_version,
            'scheduled_at' => now(),
        ]);
    }
}
