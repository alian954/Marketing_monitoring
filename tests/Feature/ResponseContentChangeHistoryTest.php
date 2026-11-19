<?php

namespace Tests\Feature;

use App\Jobs\RunMonitorCheck;
use App\Models\CheckRun;
use App\Models\Monitor;
use App\Models\User;
use App\Services\IncidentManager;
use App\Services\Probe\ProbeClient;
use App\Services\Probe\ProbeResult;
use App\Services\ResponseContentChangeHistory;
use App\Services\RunScheduler;
use DateTimeInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ResponseContentChangeHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_monitor_detail_reports_page_text_changes_without_exposing_fingerprints(): void
    {
        $monitor = $this->createMonitor();
        $previousHash = str_repeat('a', 64);
        $currentHash = str_repeat('b', 64);
        $this->createRun($monitor, now()->subMinutes(2), $previousHash);
        $this->createRun($monitor, now()->subMinute(), $currentHash);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('monitors.show', $monitor))
            ->assertOk()
            ->assertSee('頁面文字變更歷史')
            ->assertSee('頁面文字與前次檢查不同（內容未保存）')
            ->assertDontSee($previousHash)
            ->assertDontSee($currentHash);

        $changes = $response->viewData('responseContentChanges');

        $this->assertCount(1, $changes);
        $this->assertSame(['changed_at'], array_keys($changes[0]));
    }

    public function test_history_does_not_report_identical_page_text_fingerprints(): void
    {
        $monitor = $this->createMonitor();
        $fingerprint = str_repeat('c', 64);
        $this->createRun($monitor, now()->subMinutes(2), $fingerprint);
        $this->createRun($monitor, now()->subMinute(), $fingerprint);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('monitors.show', $monitor))
            ->assertOk()
            ->assertSee('尚未偵測到頁面文字變更');

        $this->assertSame([], $response->viewData('responseContentChanges'));
    }

    public function test_history_does_not_compare_across_a_run_without_a_complete_fingerprint(): void
    {
        $this->freezeTime();
        $monitor = $this->createMonitor();
        $this->createRun($monitor, now()->subMinutes(2), str_repeat('a', 64));
        $this->createRun($monitor, now()->subMinute(), null);
        $this->createRun($monitor, now(), str_repeat('b', 64));

        $changes = app(ResponseContentChangeHistory::class)->build($monitor);

        $this->assertSame([], $changes);
    }

    public function test_history_skips_failed_runs_daily_probes_and_configuration_boundaries(): void
    {
        $this->freezeTime();
        $monitor = $this->createMonitor();
        $this->createRun($monitor, now()->subMinutes(8), str_repeat('a', 64), configVersion: 1);
        $this->createRun($monitor, now()->subMinutes(7), str_repeat('b', 64), source: 'tls_daily', configVersion: 1);
        $this->createRun($monitor, now()->subMinutes(6), str_repeat('c', 64), source: 'domain_daily', configVersion: 1);
        $this->createRun($monitor, now()->subMinutes(5), str_repeat('d', 64), configVersion: 1);
        $this->createRun($monitor, now()->subMinutes(4), str_repeat('e', 64), status: 'failed', configVersion: 1);
        $this->createRun($monitor, now()->subMinutes(3), str_repeat('f', 64), configVersion: 2);

        $changes = app(ResponseContentChangeHistory::class)->build($monitor);

        $this->assertCount(1, $changes);
        $this->assertSame(now()->subMinutes(5)->toIso8601String(), $changes[0]['changed_at']->toIso8601String());
    }

    public function test_history_is_limited_to_the_ten_most_recent_page_text_changes(): void
    {
        $this->freezeTime();
        $monitor = $this->createMonitor();

        for ($runNumber = 0; $runNumber <= 12; $runNumber++) {
            $this->createRun(
                $monitor,
                now()->subMinutes(12 - $runNumber),
                hash('sha256', 'page text '.$runNumber),
            );
        }

        $changes = app(ResponseContentChangeHistory::class)->build($monitor);

        $this->assertCount(10, $changes);
        $this->assertSame(now()->toIso8601String(), $changes[0]['changed_at']->toIso8601String());
        $this->assertSame(now()->subMinutes(9)->toIso8601String(), $changes[9]['changed_at']->toIso8601String());
    }

    public function test_completed_social_post_check_persists_the_response_text_fingerprint_without_exposing_it_in_model_arrays(): void
    {
        Queue::fake([RunMonitorCheck::class]);
        $monitor = $this->createMonitor(expectedText: 'Public post copy');
        $run = app(RunScheduler::class)->enqueue($monitor, 'manual');
        $this->assertNotNull($run);
        $fingerprint = str_repeat('f', 64);
        $probe = $this->mock(ProbeClient::class);
        $probe->shouldReceive('inspect')
            ->once()
            ->with($monitor->url, [], 'MarketingMonitor/1.0 (+authorized landing page check)', 'Public post copy', 10, 'present')
            ->andReturn(new ProbeResult(
                statusCode: 200,
                elapsedMs: 20,
                finalUrl: $monitor->url,
                redirects: [],
                resolvedPublicIps: ['93.184.216.34'],
                errorType: null,
                errorDetail: null,
                expectedTextFound: true,
                tlsExpiresAt: null,
                monitorHostIps: ['93.184.216.34'],
                responseVisibleTextHash: $fingerprint,
            ));

        (new RunMonitorCheck($run->id))->handle(
            $probe,
            app(IncidentManager::class),
            app(RunScheduler::class),
        );

        $this->assertDatabaseHas('check_runs', [
            'id' => $run->id,
            'status' => 'completed',
            'response_visible_text_hash' => $fingerprint,
        ]);
        $this->assertArrayNotHasKey('response_visible_text_hash', $run->fresh()->toArray());
    }

    private function createMonitor(?string $expectedText = null): Monitor
    {
        $url = 'https://www.instagram.com/p/ABC123/';

        return Monitor::factory()->create([
            'url' => $url,
            'url_hash' => Monitor::fingerprintUrl($url),
            'expected_text' => $expectedText,
        ]);
    }

    private function createRun(
        Monitor $monitor,
        DateTimeInterface $scheduledAt,
        ?string $fingerprint,
        string $status = 'completed',
        string $source = 'scheduled',
        int $configVersion = 1,
    ): CheckRun {
        return CheckRun::factory()->for($monitor)->create([
            'config_version' => $configVersion,
            'scheduled_at' => $scheduledAt,
            'status' => $status,
            'source' => $source,
            'normal_status' => $status === 'completed' ? 200 : null,
            'response_visible_text_hash' => $fingerprint,
        ]);
    }
}
