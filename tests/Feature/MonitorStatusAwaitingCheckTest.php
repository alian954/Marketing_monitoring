<?php

namespace Tests\Feature;

use App\Models\CheckRun;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MonitorStatusAwaitingCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_result_from_an_earlier_configuration_leaves_the_monitor_pending(): void
    {
        $monitor = Monitor::factory()->create(['config_version' => 2, 'last_checked_at' => now()]);
        CheckRun::factory()->for($monitor)->create(['config_version' => 1]);

        $this->assertSame('pending', $monitor->healthState());
    }

    public function test_a_resumed_monitor_with_an_interrupted_incident_is_pending_until_the_next_check(): void
    {
        $this->freezeSecond();
        $user = User::factory()->create();
        $monitor = Monitor::factory()->create(['user_id' => $user->id, 'last_checked_at' => now()]);
        CheckRun::factory()->for($monitor)->create();
        $incident = Incident::factory()->for($monitor)->create(['state' => Incident::STATE_OPEN, 'config_version' => 1]);
        $this->actingAs($user);
        $this->travel(1)->minutes();

        $this->post(route('monitors.toggle', $monitor));
        $this->post(route('monitors.toggle', $monitor));

        $this->assertSame(Incident::STATE_PAUSED, $incident->fresh()->state);
        $this->assertSame('pending', $monitor->fresh()->healthState());

        $this->travel(1)->minutes();
        CheckRun::factory()->for($monitor)->create();
        $monitor->fresh()->forceFill(['last_checked_at' => now()])->save();

        $this->assertSame('healthy', $monitor->fresh()->healthState());
    }
}
