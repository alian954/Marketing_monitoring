<?php

namespace Tests\Feature;

use App\Models\DnsChangeEvent;
use App\Models\Monitor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MonitorDnsChangeExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_export_a_monitors_dns_change_history(): void
    {
        $monitor = Monitor::factory()->create();

        $this->get(route('monitors.dns-changes.export', $monitor))
            ->assertRedirect(route('login'));
    }

    public function test_authenticated_user_exports_scoped_formula_safe_dns_change_history(): void
    {
        $user = User::factory()->create();
        $monitor = Monitor::factory()->create();
        $otherMonitor = Monitor::factory()->create([
            'url' => 'https://other.example/post',
            'url_hash' => Monitor::fingerprintUrl('https://other.example/post'),
        ]);
        $laterEvent = DnsChangeEvent::factory()->for($monitor)->create([
            'previous_ips' => ['93.184.216.34'],
            'current_ips' => ['93.184.216.35'],
            'detected_at' => '2026-11-18 02:03:04',
            'monitor_host_only' => false,
        ]);
        $earlierEvent = DnsChangeEvent::factory()->for($monitor)->create([
            'previous_ips' => ['=1+1', '10.0.0.1'],
            'current_ips' => ['93.184.216.36'],
            'detected_at' => '2026-11-18 01:02:03',
            'monitor_host_only' => true,
        ]);
        DnsChangeEvent::factory()->for($otherMonitor)->create([
            'previous_ips' => ['other-monitor-private-ip'],
            'current_ips' => ['other-monitor-current-ip'],
        ]);

        $this->actingAs($user)
            ->get(route('monitors.show', $monitor))
            ->assertOk()
            ->assertSee('匯出 DNS 變更 CSV')
            ->assertSee(route('monitors.dns-changes.export', $monitor));

        $response = $this->actingAs($user)
            ->get(route('monitors.dns-changes.export', $monitor))
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $this->assertStringContainsString('dns-changes-'.$monitor->id.'.csv', (string) $response->headers->get('content-disposition'));

        $content = $response->streamedContent();
        $lines = preg_split('/\r\n|\n|\r/', trim($content));
        $headers = str_getcsv(array_shift($lines));
        $rows = array_map(static fn (string $line): array => str_getcsv($line), $lines);
        $columns = array_flip($headers);

        $this->assertSame([
            'dns_change_id',
            'detected_at_utc',
            'previous_ips',
            'current_ips',
            'monitor_host_only',
        ], $headers);
        $this->assertCount(2, $rows);
        $this->assertSame((string) $earlierEvent->id, $rows[0][$columns['dns_change_id']]);
        $this->assertSame('2026-11-18T01:02:03+00:00', $rows[0][$columns['detected_at_utc']]);
        $this->assertSame("'=1+1, 10.0.0.1", $rows[0][$columns['previous_ips']]);
        $this->assertSame('93.184.216.36', $rows[0][$columns['current_ips']]);
        $this->assertSame('1', $rows[0][$columns['monitor_host_only']]);
        $this->assertSame((string) $laterEvent->id, $rows[1][$columns['dns_change_id']]);
        $this->assertSame('2026-11-18T02:03:04+00:00', $rows[1][$columns['detected_at_utc']]);
        $this->assertSame('93.184.216.34', $rows[1][$columns['previous_ips']]);
        $this->assertSame('93.184.216.35', $rows[1][$columns['current_ips']]);
        $this->assertSame('0', $rows[1][$columns['monitor_host_only']]);
        $this->assertStringNotContainsString('other-monitor-private-ip', $content);
    }

    public function test_authenticated_user_exports_every_dns_change_across_streaming_batches(): void
    {
        $user = User::factory()->create();
        $monitor = Monitor::factory()->create();
        DnsChangeEvent::factory()->for($monitor)->count(501)->create();

        $response = $this->actingAs($user)
            ->get(route('monitors.dns-changes.export', $monitor))
            ->assertOk();
        $lines = preg_split('/\r\n|\n|\r/', trim($response->streamedContent()));

        $this->assertCount(502, $lines);
    }
}
