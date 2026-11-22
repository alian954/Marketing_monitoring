<?php

namespace Tests\Feature;

use App\Models\CheckRun;
use App\Models\GoogleAnalyticsCampaignSnapshot;
use App\Models\Incident;
use App\Models\IncidentEvent;
use App\Models\Monitor;
use App\Models\RobotsObservation;
use App\Models\StatusPageSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class PruneMonitoringDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_prune_removes_expired_finished_runs_and_keeps_active_runs_and_incident_history(): void
    {
        Config::set('monitoring.retention_days', 30);
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

    public function test_prune_removes_expired_robots_observations_but_keeps_recent_observations(): void
    {
        Config::set('monitoring.retention_days', 30);
        $monitor = Monitor::factory()->create();
        $expiredObservation = RobotsObservation::factory()->for($monitor)->create([
            'observed_on' => today()->subDays(31),
        ]);
        $recentObservation = RobotsObservation::factory()->for($monitor)->create([
            'observed_on' => today()->subDays(29),
        ]);

        $this->artisan('monitors:prune')
            ->assertExitCode(0)
            ->expectsOutputToContain('robots.txt');

        $this->assertDatabaseMissing('robots_observations', ['id' => $expiredObservation->id]);
        $this->assertDatabaseHas('robots_observations', ['id' => $recentObservation->id]);
    }

    public function test_prune_uses_the_configured_check_history_retention_days(): void
    {
        Config::set('monitoring.retention_days', 90);
        $monitor = Monitor::factory()->create();
        $retainedRun = CheckRun::factory()->for($monitor)->create([
            'scheduled_at' => now()->subDays(31),
            'finished_at' => now()->subDays(31),
        ]);
        $expiredRun = CheckRun::factory()->for($monitor)->create([
            'scheduled_at' => now()->subDays(91),
            'finished_at' => now()->subDays(91),
        ]);
        $retainedObservation = RobotsObservation::factory()->for($monitor)->create([
            'observed_on' => today()->subDays(89),
        ]);
        $expiredObservation = RobotsObservation::factory()->for($monitor)->create([
            'observed_on' => today()->subDays(91),
        ]);

        $this->artisan('monitors:prune')->assertExitCode(0);

        $this->assertDatabaseHas('check_runs', ['id' => $retainedRun->id]);
        $this->assertDatabaseMissing('check_runs', ['id' => $expiredRun->id]);
        $this->assertDatabaseHas('robots_observations', ['id' => $retainedObservation->id]);
        $this->assertDatabaseMissing('robots_observations', ['id' => $expiredObservation->id]);
    }

    public function test_prune_removes_expired_ga4_trend_snapshots_and_keeps_recent_ones(): void
    {
        Config::set('monitoring.retention_days', 30);
        $expiredSnapshot = GoogleAnalyticsCampaignSnapshot::factory()->create([
            'snapshot_date' => today()->subDays(31),
        ]);
        $recentSnapshot = GoogleAnalyticsCampaignSnapshot::factory()->create([
            'snapshot_date' => today()->subDays(29),
        ]);

        $this->artisan('monitors:prune')
            ->assertExitCode(0)
            ->expectsOutputToContain('GA4 趨勢');

        $this->assertDatabaseMissing('google_analytics_campaign_snapshots', ['id' => $expiredSnapshot->id]);
        $this->assertDatabaseHas('google_analytics_campaign_snapshots', ['id' => $recentSnapshot->id]);
    }
}
