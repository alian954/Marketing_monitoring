<?php

namespace Tests\Feature;

use App\Jobs\RunMonitorCheck;
use App\Models\CheckRun;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\User;
use App\Services\IncidentManager;
use App\Services\NotificationDispatcher;
use App\Services\Probe\ProbeClient;
use App\Services\Probe\ProbeResult;
use App\Services\RunScheduler;
use App\Services\StatusPageSubscriptionDispatcher;
use App\Services\UrlMasker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MonitorConfirmationDelayTest extends TestCase
{
    use RefreshDatabase;

    public function test_monitor_form_uses_the_global_default_and_exposes_the_supported_range(): void
    {
        Config::set('monitoring.confirmation_delay_seconds', 90);

        $this->actingAs(User::factory()->create())
            ->get(route('monitors.create'))
            ->assertOk()
            ->assertSee('name="confirmation_delay_seconds"', false)
            ->assertSee('min="30" max="300" step="15"', false)
            ->assertSee('value="90"', false);
    }

    public function test_create_and_update_persist_delay_in_new_check_snapshots(): void
    {
        Config::set('monitoring.transport', 'fixture');
        Queue::fake([RunMonitorCheck::class]);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('monitors.store'), [
                'name' => 'Campaign page',
                'url' => 'https://fixture.test/ok',
                'confirmation_delay_seconds' => 180,
            ])
            ->assertSessionHasNoErrors();

        $monitor = Monitor::query()->sole();
        $this->assertSame(180, $monitor->confirmation_delay_seconds);

        $oldRun = app(RunScheduler::class)->enqueue($monitor, 'manual');
        $this->assertNotNull($oldRun);
        $this->assertSame(180, json_decode($oldRun->config_snapshot, true, flags: JSON_THROW_ON_ERROR)['confirmation_delay_seconds']);

        $this->actingAs($user)
            ->put(route('monitors.update', $monitor), [
                'name' => 'Campaign page updated',
                'confirmation_delay_seconds' => 240,
            ])
            ->assertRedirect(route('monitors.show', $monitor));

        $this->assertSame(240, $monitor->fresh()->confirmation_delay_seconds);
        $this->assertDatabaseHas('check_runs', ['id' => $oldRun->id, 'status' => 'stale_config']);
        $this->actingAs($user)
            ->get(route('monitors.show', $monitor))
            ->assertOk()
            ->assertSee('異常與恢復確認延遲')
            ->assertSee('240 秒');

        $nextRun = app(RunScheduler::class)->enqueue($monitor->fresh(), 'manual');
        $this->assertNotNull($nextRun);
        $this->assertSame(240, json_decode($nextRun->config_snapshot, true, flags: JSON_THROW_ON_ERROR)['confirmation_delay_seconds']);
    }

    public function test_confirmation_delay_rejects_values_outside_the_supported_range(): void
    {
        Config::set('monitoring.transport', 'fixture');
        $user = User::factory()->create();

        foreach ([29, 301] as $confirmationDelaySeconds) {
            $this->actingAs($user)
                ->from(route('monitors.create'))
                ->post(route('monitors.store'), [
                    'name' => 'Invalid confirmation delay',
                    'url' => 'https://fixture.test/ok',
                    'confirmation_delay_seconds' => $confirmationDelaySeconds,
                ])
                ->assertRedirect(route('monitors.create'))
                ->assertSessionHasErrors(['confirmation_delay_seconds']);
        }
    }

    public function test_run_monitor_check_schedules_confirmation_after_the_selected_delay(): void
    {
        Config::set('monitoring.retry_after_fallback_seconds', 0);
        Queue::fake([RunMonitorCheck::class]);
        $url = 'https://www.facebook.com/story.php?story_fbid=123';
        $monitor = Monitor::factory()->create([
            'url' => $url,
            'url_hash' => Monitor::fingerprintUrl($url),
            'confirmation_delay_seconds' => 135,
        ]);
        $run = app(RunScheduler::class)->enqueue($monitor, 'manual');
        $this->assertNotNull($run);
        $failure = new ProbeResult(503, 40, $url, [], ['93.184.216.34'], null, null, null, null);
        $probe = $this->mock(ProbeClient::class);
        $probe->shouldReceive('inspect')->once()->andReturn($failure);

        (new RunMonitorCheck($run->id))->handle(
            $probe,
            app(IncidentManager::class),
            app(RunScheduler::class),
        );

        $confirmationRun = CheckRun::query()->where('source', 'confirmation')->sole();
        $this->assertSame(135, $confirmationRun->scheduled_at->getTimestamp() - now()->getTimestamp());
        Queue::assertPushed(RunMonitorCheck::class, 2);
    }

    public function test_incident_open_and_recovery_confirmation_use_the_monitor_delay(): void
    {
        $monitor = Monitor::factory()->create(['confirmation_delay_seconds' => 120]);
        $manager = new IncidentManager(new NotificationDispatcher(new UrlMasker, app(StatusPageSubscriptionDispatcher::class)));
        $failure = new ProbeResult(503, 40, 'https://example.com/down', [], ['93.184.216.34'], null, null, null, null);
        $success = new ProbeResult(200, 40, 'https://example.com/up', [], ['93.184.216.34'], null, null, null, null);

        $firstFailure = CheckRun::factory()->for($monitor)->create(['config_version' => $monitor->config_version]);
        $this->assertSame(120, $manager->record($monitor, $firstFailure, $failure, $success));
        $incident = Incident::query()->sole();
        $this->assertSame(Incident::STATE_SUSPECT, $incident->state);
        $this->assertSame(now()->addSeconds(120)->getTimestamp(), $incident->confirm_after->getTimestamp());

        $this->travelTo(now()->addSeconds(121));
        $secondFailure = CheckRun::factory()->for($monitor)->create(['config_version' => $monitor->config_version]);
        $manager->record($monitor, $secondFailure, $failure, $success);
        $this->assertSame(Incident::STATE_OPEN, $incident->fresh()->state);

        $firstRecovery = CheckRun::factory()->for($monitor)->create(['config_version' => $monitor->config_version]);
        $this->assertSame(120, $manager->record($monitor, $firstRecovery, $success, $success));
        $this->assertSame(now()->addSeconds(120)->getTimestamp(), $incident->fresh()->confirm_after->getTimestamp());

        $this->travelTo(now()->addSeconds(121));
        $secondRecovery = CheckRun::factory()->for($monitor)->create(['config_version' => $monitor->config_version]);
        $manager->record($monitor, $secondRecovery, $success, $success);
        $this->assertSame(Incident::STATE_RESOLVED, $incident->fresh()->state);
    }
}
