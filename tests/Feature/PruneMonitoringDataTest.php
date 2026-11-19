<?php

namespace Tests\Feature;

use App\Models\CheckRun;
use App\Models\Incident;
use App\Models\IncidentEvent;
use App\Models\Monitor;
use App\Models\StatusPageSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PruneMonitoringDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_prune_removes_expired_finished_runs_and_keeps_active_runs_and_incident_history(): void
    {
        $monitor = Monitor::factory()->create();
        $oldRun = CheckRun::factory()->for($monitor)->create([
            'scheduled_at' => now()->subDays(31),
            'finished_at' => now()->subDays(31),
        ]);
        $oldFailedRun = CheckRun::factory()->for($monitor)->create([
            'status' => 'failed',
            'scheduled_at' => now()->subDays(31),
            'started_at' => now()->subDays(31),
            'finished_at' => now()->subDays(31),
        ]);
        $oldActiveRun = CheckRun::factory()->for($monitor)->create([
            'status' => 'running',
            'scheduled_at' => now()->subDays(31),
            'started_at' => now()->subDays(31),
            'finished_at' => null,
        ]);
        $recentRun = CheckRun::factory()->for($monitor)->create();
        $incident = Incident::factory()->for($monitor)->create();
        $event = IncidentEvent::factory()->create([
            'incident_id' => $incident->id,
            'check_run_id' => $oldRun->id,
        ]);

        $this->artisan('monitors:prune')
            ->assertExitCode(0)
            ->expectsOutputToContain('已清除');

        $this->assertDatabaseMissing('check_runs', ['id' => $oldRun->id]);
        $this->assertDatabaseMissing('check_runs', ['id' => $oldFailedRun->id]);
        $this->assertDatabaseHas('check_runs', ['id' => $oldActiveRun->id]);
        $this->assertDatabaseHas('check_runs', ['id' => $recentRun->id]);
        $this->assertDatabaseHas('incidents', ['id' => $incident->id]);
        $this->assertDatabaseHas('incident_events', [
            'id' => $event->id,
            'incident_id' => $incident->id,
            'check_run_id' => null,
        ]);
    }

    public function test_prune_removes_expired_pending_status_subscriptions_but_keeps_confirmed_and_unexpired_ones(): void
    {
        $expired = StatusPageSubscription::factory()->create([
            'confirmed_at' => null,
            'confirmation_expires_at' => now()->subSecond(),
        ]);
        $unexpired = StatusPageSubscription::factory()->create([
            'confirmed_at' => null,
            'confirmation_expires_at' => now()->addHour(),
        ]);
        $confirmed = StatusPageSubscription::factory()->create([
            'confirmation_expires_at' => null,
        ]);

        $this->artisan('monitors:prune')->assertExitCode(0);

        $this->assertDatabaseMissing('status_page_subscriptions', ['id' => $expired->id]);
        $this->assertDatabaseHas('status_page_subscriptions', ['id' => $unexpired->id]);
        $this->assertDatabaseHas('status_page_subscriptions', ['id' => $confirmed->id]);
    }
}
