<?php

namespace Tests\Feature;

use App\Models\DnsChangeEvent;
use App\Models\Monitor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class MonitorDnsChangeHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_monitor_detail_shows_only_its_latest_fifty_dns_changes(): void
    {
        $this->travelTo(Carbon::parse('2026-11-19 12:00:00', 'UTC'));
        $monitorUrl = 'https://www.threads.net/@campaign/post/abc123?token=monitor-secret';
        $monitor = Monitor::factory()->create([
            'name' => 'Campaign post',
            'url' => $monitorUrl,
            'url_hash' => Monitor::fingerprintUrl($monitorUrl),
        ]);
        $newestChange = null;
        $oldestChange = null;

        for ($index = 0; $index < 51; $index++) {
            $change = DnsChangeEvent::factory()->for($monitor)->create([
                'previous_ips' => ['203.0.113.'.$index],
                'current_ips' => ['198.51.100.'.$index],
                'detected_at' => now()->subMinutes($index),
            ]);

            if ($index === 0) {
                $newestChange = $change;
            }

            if ($index === 50) {
                $oldestChange = $change;
            }
        }

        $otherMonitor = Monitor::factory()->create();
        DnsChangeEvent::factory()->for($otherMonitor)->create([
            'current_ips' => ['192.0.2.99'],
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('monitors.show', $monitor))
            ->assertOk()
            ->assertSee('DNS 位址變更')
            ->assertSee('主機先前 IP')
            ->assertSee('主機目前 IP')
            ->assertSee('198.51.100.0')
            ->assertDontSee('198.51.100.50')
            ->assertDontSee('192.0.2.99')
            ->assertSee('token=REDACTED')
            ->assertDontSee('monitor-secret')
            ->assertSee('aria-labelledby="dns-change-history-title"', false)
            ->assertSee('tabindex="0"', false);

        $dnsChanges = $response->viewData('dnsChanges');
        $this->assertCount(50, $dnsChanges);
        $this->assertSame($newestChange->id, $dnsChanges->first()->id);
        $this->assertFalse($dnsChanges->contains('id', $oldestChange->id));
    }

    public function test_monitor_detail_explains_when_no_dns_changes_have_been_detected(): void
    {
        $monitor = Monitor::factory()->create(['name' => 'Quiet campaign post']);
        $otherMonitorUrl = 'https://other.example/landing';
        $otherMonitor = Monitor::factory()->create([
            'url' => $otherMonitorUrl,
            'url_hash' => Monitor::fingerprintUrl($otherMonitorUrl),
        ]);
        DnsChangeEvent::factory()->for($otherMonitor)->create([
            'current_ips' => ['192.0.2.77'],
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('monitors.show', $monitor))
            ->assertOk()
            ->assertSee('尚無 DNS 位址變更記錄')
            ->assertSee('累積至少兩次成功的排程檢查後')
            ->assertDontSee('192.0.2.77');

        $this->assertCount(0, $response->viewData('dnsChanges'));
    }
}
