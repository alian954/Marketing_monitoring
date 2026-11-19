<?php

namespace Tests\Feature;

use App\Models\CheckRun;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BulkMonitorStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_pause_selected_monitors_and_their_queued_work(): void
    {
        $firstMonitor = $this->createMonitor();
        $secondMonitor = $this->createMonitor();
        $untouchedMonitor = $this->createMonitor();
        $queuedRun = CheckRun::factory()->for($firstMonitor)->create([
            'status' => 'queued',
            'started_at' => null,
            'finished_at' => null,
        ]);
        $runningRun = CheckRun::factory()->for($firstMonitor)->create([
            'status' => 'running',
            'finished_at' => null,
        ]);
        $openIncident = Incident::factory()->for($firstMonitor)->create([
            'state' => Incident::STATE_OPEN,
        ]);
        $suspectIncident = Incident::factory()->for($secondMonitor)->create([
            'state' => Incident::STATE_SUSPECT,
        ]);

        $this->actingAs(User::factory()->create())
            ->from(route('monitors.index'))
            ->post(route('monitors.bulk-status'), [
                'action' => 'pause',
                'monitor_ids' => [$firstMonitor->id, $secondMonitor->id],
            ])
            ->assertRedirect(route('monitors.index'))
            ->assertSessionHas('status', '已暫停 2 個監測項目。');

        foreach ([$firstMonitor, $secondMonitor] as $monitor) {
            $this->assertDatabaseHas('monitors', [
                'id' => $monitor->id,
                'enabled' => false,
                'next_check_at' => null,
            ]);
        }

        $this->assertDatabaseHas('check_runs', [
            'id' => $queuedRun->id,
            'status' => 'paused',
        ]);
        $this->assertNotNull($queuedRun->fresh()->finished_at);
        $this->assertDatabaseHas('check_runs', [
            'id' => $runningRun->id,
            'status' => 'running',
            'finished_at' => null,
        ]);
        $this->assertDatabaseHas('incidents', [
            'id' => $openIncident->id,
            'state' => Incident::STATE_PAUSED,
            'active_key' => null,
            'resolved_at' => null,
        ]);
        $this->assertDatabaseHas('incidents', [
            'id' => $suspectIncident->id,
            'state' => Incident::STATE_PAUSED,
            'active_key' => null,
            'resolved_at' => null,
        ]);
        $this->assertDatabaseCount('incident_events', 2);
        $this->assertDatabaseHas('incident_events', [
            'incident_id' => $openIncident->id,
            'event_type' => 'paused',
        ]);
        $this->assertDatabaseHas('incident_events', [
            'incident_id' => $suspectIncident->id,
            'event_type' => 'paused',
        ]);
        $this->assertTrue($untouchedMonitor->fresh()->enabled);
        $this->assertNotNull($untouchedMonitor->fresh()->next_check_at);
    }

    public function test_bulk_resume_schedules_only_paused_monitors_for_an_immediate_check(): void
    {
        $this->travelTo(now()->startOfMinute());
        $pausedMonitor = $this->createMonitor([
            'enabled' => false,
            'next_check_at' => null,
        ]);
        $alreadyEnabledMonitor = $this->createMonitor([
            'enabled' => true,
            'next_check_at' => now()->addHour(),
        ]);
        $existingDueAt = $alreadyEnabledMonitor->next_check_at->toDateTimeString();

        $this->actingAs(User::factory()->create())
            ->from(route('monitors.index'))
            ->post(route('monitors.bulk-status'), [
                'action' => 'resume',
                'monitor_ids' => [$pausedMonitor->id, $alreadyEnabledMonitor->id],
            ])
            ->assertRedirect(route('monitors.index'))
            ->assertSessionHas('status', '已恢復 1 個監測項目。 1 個原本已啟用，未變更。');

        $this->assertDatabaseHas('monitors', [
            'id' => $pausedMonitor->id,
            'enabled' => true,
            'next_check_at' => now()->toDateTimeString(),
        ]);
        $this->assertSame($existingDueAt, $alreadyEnabledMonitor->fresh()->next_check_at->toDateTimeString());
    }

    public function test_bulk_status_rejects_empty_duplicate_and_unknown_monitor_selections(): void
    {
        $monitor = $this->createMonitor();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(route('monitors.index'))
            ->post(route('monitors.bulk-status'), ['action' => 'pause'])
            ->assertRedirect(route('monitors.index'))
            ->assertSessionHasErrors(['monitor_ids']);

        $this->actingAs($user)
            ->from(route('monitors.index'))
            ->followingRedirects()
            ->post(route('monitors.bulk-status'), [
                'action' => 'pause',
                'monitor_ids' => [$monitor->id, $monitor->id],
            ])
            ->assertOk()
            ->assertSee('監測項目不可重複選取。');

        $this->actingAs($user)
            ->from(route('monitors.index'))
            ->post(route('monitors.bulk-status'), [
                'action' => 'resume',
                'monitor_ids' => [999999],
            ])
            ->assertRedirect(route('monitors.index'))
            ->assertSessionHasErrors(['monitor_ids.0']);

        $this->assertTrue($monitor->fresh()->enabled);
    }

    public function test_bulk_status_rejects_more_than_fifty_monitors_without_changing_any(): void
    {
        $monitorIds = [];

        for ($index = 0; $index < 51; $index++) {
            $monitorIds[] = $this->createMonitor()->id;
        }

        $this->actingAs(User::factory()->create())
            ->from(route('monitors.index'))
            ->post(route('monitors.bulk-status'), [
                'action' => 'pause',
                'monitor_ids' => $monitorIds,
            ])
            ->assertRedirect(route('monitors.index'))
            ->assertSessionHasErrors(['monitor_ids']);

        $this->assertSame(51, Monitor::query()->where('enabled', true)->count());
    }

    public function test_guest_cannot_submit_bulk_monitor_status_changes(): void
    {
        $monitor = $this->createMonitor();

        $this->from(route('monitors.index'))
            ->post(route('monitors.bulk-status'), [
                'action' => 'pause',
                'monitor_ids' => [$monitor->id],
            ])
            ->assertRedirect(route('login'));

        $this->assertTrue($monitor->fresh()->enabled);
    }

    public function test_monitor_list_renders_bulk_controls_and_selectable_rows(): void
    {
        $monitor = $this->createMonitor(['name' => 'Campaign status']);

        $this->actingAs(User::factory()->create())
            ->get(route('monitors.index'))
            ->assertOk()
            ->assertSee(route('monitors.bulk-status'), false)
            ->assertSee('data-monitor-select-all', false)
            ->assertSee('data-monitor-select', false)
            ->assertSee('name="monitor_ids[]" value="'.$monitor->id.'"', false)
            ->assertSee('暫停所選監測項目')
            ->assertSee('恢復所選監測項目');
    }

    /** @param array<string, mixed> $attributes */
    private function createMonitor(array $attributes = []): Monitor
    {
        $url = 'https://example.com/monitors/'.Str::uuid();

        return Monitor::factory()->create([
            ...$attributes,
            'url' => $url,
            'url_hash' => Monitor::fingerprintUrl($url),
        ]);
    }
}
