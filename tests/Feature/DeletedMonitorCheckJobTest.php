<?php

namespace Tests\Feature;

use App\Jobs\RunMonitorCheck;
use App\Models\CheckRun;
use App\Models\Monitor;
use App\Services\Probe\ProbeClient;
use App\Services\Probe\ProbeResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class DeletedMonitorCheckJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_queued_check_for_a_deleted_monitor_finishes_without_probing(): void
    {
        $run = CheckRun::factory()->create(['status' => 'queued', 'finished_at' => null]);
        $this->mock(ProbeClient::class, fn (MockInterface $probe) => $probe->shouldNotReceive('inspect'));
        $run->monitor->delete();

        app()->call([new RunMonitorCheck($run->id), 'handle']);

        $this->assertDatabaseEmpty('check_runs');
    }

    public function test_a_monitor_deleted_while_being_probed_discards_the_result(): void
    {
        $monitor = Monitor::factory()->create();
        $run = CheckRun::factory()->for($monitor)->create(['status' => 'queued', 'finished_at' => null]);
        $this->mock(ProbeClient::class, fn (MockInterface $probe) => $probe->shouldReceive('inspect')->andReturnUsing(
            function () use ($monitor): ProbeResult {
                Monitor::query()->whereKey($monitor->id)->delete();

                return new ProbeResult(200, 2, 'https://example.com/landing', [], ['93.184.216.34'], null, null, null, null);
            },
        ));

        app()->call([new RunMonitorCheck($run->id), 'handle']);

        $this->assertDatabaseEmpty('check_runs');
        $this->assertDatabaseEmpty('incidents');
    }
}
