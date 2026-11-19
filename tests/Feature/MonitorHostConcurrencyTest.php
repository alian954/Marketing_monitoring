<?php

namespace Tests\Feature;

use App\Jobs\CheckTlsCertificate;
use App\Jobs\RunMonitorCheck;
use App\Models\CheckRun;
use App\Models\Monitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Tests\TestCase;

class MonitorHostConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_regular_and_tls_jobs_share_a_lock_for_the_same_normalized_host(): void
    {
        app('cache')->setDefaultDriver('database');
        $regularMonitor = $this->createMonitor('https://WWW.Example.com./campaign');
        $tlsMonitor = $this->createMonitor('https://www.example.com/certificate');
        $run = CheckRun::factory()->for($regularMonitor)->create();
        $regularMiddleware = (new RunMonitorCheck($run->id))->middleware()[0];
        $tlsMiddleware = (new CheckTlsCertificate($tlsMonitor->id))->middleware()[0];
        $regularQueueJob = new class
        {
            /** @var list<int> */
            public array $releasedDelays = [];

            public function release(int $delay = 0): void
            {
                $this->releasedDelays[] = $delay;
            }
        };
        $tlsQueueJob = new class
        {
            /** @var list<int> */
            public array $releasedDelays = [];

            public function release(int $delay = 0): void
            {
                $this->releasedDelays[] = $delay;
            }
        };
        $tlsJobExecuted = false;

        $this->assertInstanceOf(WithoutOverlapping::class, $regularMiddleware);
        $this->assertInstanceOf(WithoutOverlapping::class, $tlsMiddleware);
        $this->assertSame($regularMiddleware->getLockKey($regularQueueJob), $tlsMiddleware->getLockKey($tlsQueueJob));

        $regularMiddleware->handle($regularQueueJob, function () use ($tlsMiddleware, $tlsQueueJob, &$tlsJobExecuted): void {
            $tlsMiddleware->handle($tlsQueueJob, function () use (&$tlsJobExecuted): void {
                $tlsJobExecuted = true;
            });
        });

        $this->assertFalse($tlsJobExecuted);
        $this->assertSame([30], $tlsQueueJob->releasedDelays);
        $this->assertSame(20, (new RunMonitorCheck($run->id))->tries);
        $this->assertSame(3, (new RunMonitorCheck($run->id))->maxExceptions);
        $this->assertSame(3, (new CheckTlsCertificate($tlsMonitor->id))->maxExceptions);
    }

    public function test_different_hosts_can_run_at_the_same_time(): void
    {
        app('cache')->setDefaultDriver('array');
        $regularMonitor = $this->createMonitor('https://www.example.com/campaign');
        $tlsMonitor = $this->createMonitor('https://api.example.com/certificate');
        $run = CheckRun::factory()->for($regularMonitor)->create();
        $regularMiddleware = (new RunMonitorCheck($run->id))->middleware()[0];
        $tlsMiddleware = (new CheckTlsCertificate($tlsMonitor->id))->middleware()[0];
        $regularQueueJob = new class
        {
            public function release(int $delay = 0): void {}
        };
        $tlsQueueJob = new class
        {
            public function release(int $delay = 0): void {}
        };
        $tlsJobExecuted = false;

        $regularMiddleware->handle($regularQueueJob, function () use ($tlsMiddleware, $tlsQueueJob, &$tlsJobExecuted): void {
            $tlsMiddleware->handle($tlsQueueJob, function () use (&$tlsJobExecuted): void {
                $tlsJobExecuted = true;
            });
        });

        $this->assertTrue($tlsJobExecuted);
    }

    private function createMonitor(string $url): Monitor
    {
        return Monitor::factory()->create([
            'url' => $url,
            'url_hash' => Monitor::fingerprintUrl($url),
        ]);
    }
}
