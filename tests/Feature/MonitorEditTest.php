<?php

namespace Tests\Feature;

use App\Models\Incident;
use App\Models\IncidentEvent;
use App\Models\Monitor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class MonitorEditTest extends TestCase
{
    use RefreshDatabase;

    public function test_editing_a_monitor_supersedes_old_incidents_and_keeps_them_in_history(): void
    {
        Config::set('monitoring.transport', 'fixture');
        $user = User::factory()->create();
        $url = 'http://fixture.test/original';
        $monitor = Monitor::factory()->create([
            'url' => $url,
            'url_hash' => Monitor::fingerprintUrl($url),
            'last_checked_at' => now(),
        ]);
        $incident = Incident::factory()->for($monitor)->create([
            'config_version' => 1,
            'state' => Incident::STATE_OPEN,
            'active_key' => hash('sha256', 'active-incident'),
            'opened_at' => now(),
        ]);
        IncidentEvent::factory()->create([
            'incident_id' => $incident->id,
            'check_run_id' => null,
            'event_type' => 'opened',
        ]);

        $this->actingAs($user)
            ->put(route('monitors.update', $monitor), [
                'name' => 'Updated fixture',
                'url' => 'http://healthy.fixture.test/updated',
                'label' => 'QA',
                'expected_text' => '',
                'redirect_hosts' => '',
            ])
            ->assertRedirect(route('monitors.show', $monitor));

        $this->assertDatabaseHas('monitors', ['id' => $monitor->id, 'config_version' => 2]);
        $this->assertDatabaseHas('incidents', [
            'id' => $incident->id,
            'config_version' => 1,
            'state' => Incident::STATE_SUPERSEDED,
            'active_key' => null,
            'resolved_at' => null,
        ]);
        $this->assertDatabaseHas('incident_events', [
            'incident_id' => $incident->id,
            'event_type' => 'superseded',
        ]);
        $this->assertDatabaseCount('notification_outbox', 0);

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('目前沒有進行中的事件');
        $this->get(route('monitors.show', $monitor))
            ->assertOk()
            ->assertSee('設定已變更')
            ->assertSee('舊事件已因設定變更而結束');
    }
}
