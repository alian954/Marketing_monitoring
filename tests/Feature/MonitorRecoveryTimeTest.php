<?php

namespace Tests\Feature;

use App\Models\Incident;
use App\Models\Monitor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MonitorRecoveryTimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_recovery_summary_averages_confirmed_http_incidents_resolved_in_the_last_30_days(): void
    {
        $this->freezeTime();
        $monitor = Monitor::factory()->create();
        Incident::factory()->for($monitor)->create([
            'rule_key' => 'http',
            'state' => Incident::STATE_RESOLVED,
            'classification' => 'true_issue',
            'opened_at' => now()->subHours(3),
            'resolved_at' => now()->subHours(2)->subMinutes(30),
        ]);
        Incident::factory()->for($monitor)->create([
            'rule_key' => 'http',
            'state' => Incident::STATE_RESOLVED,
            'opened_at' => now()->subHours(5),
            'resolved_at' => now()->subHours(3)->subMinutes(30),
        ]);

        Incident::factory()->for($monitor)->create([
            'rule_key' => 'http',
            'state' => Incident::STATE_RESOLVED,
            'classification' => 'false_alarm',
            'opened_at' => now()->subHour(),
            'resolved_at' => now()->subMinutes(20),
        ]);
        Incident::factory()->for($monitor)->create([
            'rule_key' => 'content',
            'state' => Incident::STATE_RESOLVED,
            'opened_at' => now()->subHours(3),
            'resolved_at' => now()->subHour(),
        ]);
        Incident::factory()->for($monitor)->create([
            'rule_key' => 'http',
            'state' => Incident::STATE_OPEN,
            'opened_at' => now()->subMinutes(30),
            'resolved_at' => null,
        ]);
        Incident::factory()->for($monitor)->create([
            'rule_key' => 'http',
            'state' => Incident::STATE_RESOLVED,
            'opened_at' => now()->subDays(31),
            'resolved_at' => now()->subDays(29),
        ]);
        $oldIncidentStartedAt = now()->subDays(31);
        Incident::factory()->for($monitor)->create([
            'rule_key' => 'http',
            'state' => Incident::STATE_RESOLVED,
            'opened_at' => $oldIncidentStartedAt,
            'resolved_at' => $oldIncidentStartedAt->copy()->addHour(),
        ]);
        Incident::factory()->for($monitor)->create([
            'rule_key' => 'http',
            'state' => Incident::STATE_RESOLVED,
            'opened_at' => null,
            'resolved_at' => now()->subMinutes(10),
        ]);
        Incident::factory()->for($monitor)->create([
            'rule_key' => 'http',
            'state' => Incident::STATE_RESOLVED,
            'opened_at' => now()->subHour(),
            'resolved_at' => now()->subHours(2),
        ]);
        $otherMonitorUrl = 'https://other.example/landing';
        $otherMonitor = Monitor::factory()->create([
            'url' => $otherMonitorUrl,
            'url_hash' => Monitor::fingerprintUrl($otherMonitorUrl),
        ]);
        Incident::factory()->for($otherMonitor)->create([
            'rule_key' => 'http',
            'state' => Incident::STATE_RESOLVED,
            'opened_at' => now()->subDays(25),
            'resolved_at' => now()->subDays(24),
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('monitors.show', $monitor))
            ->assertOk()
            ->assertSee('平均確認後恢復時間')
            ->assertSee('1,000.0 分鐘')
            ->assertSee('2,880.0 分鐘');

        $recoveryStats = $response->viewData('httpRecoveryStats');
        $this->assertSame(3, $recoveryStats['count']);
        $this->assertSame(1000.0, $recoveryStats['average_minutes']);
        $this->assertSame(2880.0, $recoveryStats['maximum_minutes']);
    }

    public function test_recovery_summary_shows_an_empty_state_without_resolved_http_incidents(): void
    {
        $this->freezeTime();
        $monitor = Monitor::factory()->create();

        $response = $this->actingAs(User::factory()->create())
            ->get(route('monitors.show', $monitor))
            ->assertOk()
            ->assertSee('尚無已恢復的 HTTP 故障事件');

        $recoveryStats = $response->viewData('httpRecoveryStats');
        $this->assertSame(0, $recoveryStats['count']);
        $this->assertNull($recoveryStats['average_minutes']);
        $this->assertNull($recoveryStats['maximum_minutes']);
    }
}
