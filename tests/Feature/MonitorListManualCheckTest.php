<?php

namespace Tests\Feature;

use App\Jobs\RunMonitorCheck;
use App\Models\Monitor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MonitorListManualCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_monitor_list_shows_manual_check_for_enabled_monitors_only(): void
    {
        $enabledMonitor = $this->createMonitor('Enabled monitor', true);
        $pausedMonitor = $this->createMonitor('Paused monitor', false);

        $this->actingAs(User::factory()->create())
            ->get(route('monitors.index'))
            ->assertOk()
            ->assertSee('action="'.route('monitors.check', $enabledMonitor).'"', false)
            ->assertSee('>立即檢查</button>', false)
            ->assertDontSee(route('monitors.check', $pausedMonitor), false);
    }

    public function test_manual_check_from_filtered_list_preserves_schedule_and_enforces_cooldown(): void
    {
        Queue::fake([RunMonitorCheck::class]);
        $url = 'https://www.threads.net/@creator/post/ABC123';
        $nextCheckAt = now()->addHour();
        $monitor = Monitor::factory()->create([
            'name' => 'Threads campaign post',
            'url' => $url,
            'url_hash' => Monitor::fingerprintUrl($url),
            'next_check_at' => $nextCheckAt,
        ]);
        $filters = ['platform' => 'threads', 'state' => 'enabled'];
        $listUrl = route('monitors.index', $filters);
        Cache::forget('manual-check:'.$monitor->id);

        $this->actingAs(User::factory()->create())
            ->from($listUrl)
            ->post(route('monitors.check', $monitor))
            ->assertRedirect($listUrl)
            ->assertSessionHas('status', '手動檢查已排入佇列，並會套用與排程檢查相同的安全及確認規則。');

        $this->assertDatabaseHas('check_runs', [
            'monitor_id' => $monitor->id,
            'source' => 'manual',
            'status' => 'queued',
        ]);
        $this->assertSame($nextCheckAt->toDateTimeString(), $monitor->fresh()->next_check_at->toDateTimeString());

        $this->from($listUrl)
            ->post(route('monitors.check', $monitor))
            ->assertRedirect($listUrl)
            ->assertSessionHasErrors(['check' => '剛才已提出手動檢查，請等待五分鐘後再試。']);

        $this->assertDatabaseCount('check_runs', 1);
        Queue::assertPushed(RunMonitorCheck::class, 1);
        Cache::forget('manual-check:'.$monitor->id);
    }

    public function test_paused_monitor_has_no_list_action_and_rejects_manual_checks(): void
    {
        Queue::fake([RunMonitorCheck::class]);
        $monitor = $this->createMonitor('Paused monitor', false);
        Cache::forget('manual-check:'.$monitor->id);

        $listUrl = route('monitors.index', ['state' => 'paused']);
        $this->actingAs(User::factory()->create())
            ->get($listUrl)
            ->assertOk()
            ->assertDontSee(route('monitors.check', $monitor), false);

        $this->from($listUrl)
            ->post(route('monitors.check', $monitor))
            ->assertRedirect($listUrl)
            ->assertSessionHasErrors(['check' => '請先恢復監測，再提出手動檢查。']);

        $this->assertDatabaseCount('check_runs', 0);
        Queue::assertNothingPushed();
        Cache::forget('manual-check:'.$monitor->id);
    }

    private function createMonitor(string $name, bool $enabled): Monitor
    {
        $url = 'https://example.com/'.str()->slug($name);

        return Monitor::factory()->create([
            'name' => $name,
            'url' => $url,
            'url_hash' => Monitor::fingerprintUrl($url),
            'enabled' => $enabled,
        ]);
    }
}
