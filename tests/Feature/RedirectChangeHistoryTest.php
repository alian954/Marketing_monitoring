<?php

namespace Tests\Feature;

use App\Models\CheckRun;
use App\Models\Monitor;
use App\Models\User;
use App\Services\RedirectChangeHistory;
use DateTimeInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RedirectChangeHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_monitor_detail_lists_redirect_path_changes_without_query_or_fragment_data(): void
    {
        $monitor = Monitor::factory()->create();
        $this->createRun($monitor, now()->subMinutes(2), []);
        $this->createRun($monitor, now()->subMinute(), [
            'https://LOGIN.EXAMPLE.test:443/auth?token=private-token#callback',
            'https://www.example.test/post/123?utm_source=private-campaign#post',
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('monitors.show', $monitor))
            ->assertOk()
            ->assertSee('重新導向變更歷史')
            ->assertSee('https://login.example.test/auth')
            ->assertSee('https://www.example.test/post/123')
            ->assertSee('無重新導向')
            ->assertDontSee('private-token')
            ->assertDontSee('#callback');

        $changes = $response->viewData('redirectChanges');

        $this->assertCount(1, $changes);
        $this->assertSame([], $changes[0]['previous_redirects']);
        $this->assertSame([
            'https://login.example.test/auth',
            'https://www.example.test/post/123',
        ], $changes[0]['current_redirects']);
    }

    public function test_query_or_fragment_only_differences_do_not_create_redirect_changes(): void
    {
        $monitor = Monitor::factory()->create();
        $this->createRun($monitor, now()->subMinutes(2), [
            'https://www.example.test:443/post/123?token=first#initial',
        ]);
        $this->createRun($monitor, now()->subMinute(), [
            'https://www.example.test/post/123?token=second#updated',
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('monitors.show', $monitor))
            ->assertOk()
            ->assertSee('尚未偵測到重新導向變更');

        $this->assertSame([], $response->viewData('redirectChanges'));
    }

    public function test_history_skips_daily_probes_failed_runs_and_configuration_version_boundaries(): void
    {
        $monitor = Monitor::factory()->create();
        $this->createRun($monitor, now()->subMinutes(7), ['https://example.test/old'], configVersion: 1);
        $this->createRun($monitor, now()->subMinutes(6), ['https://example.test/domain'], source: 'domain_daily', configVersion: 1);
        $this->createRun($monitor, now()->subMinutes(5), ['https://example.test/tls'], source: 'tls_daily', configVersion: 1);
        $this->createRun($monitor, now()->subMinutes(4), ['https://example.test/new'], configVersion: 1);
        $this->createRun($monitor, now()->subMinutes(3), ['https://example.test/failed'], status: 'failed', configVersion: 1);
        $this->createRun($monitor, now()->subMinutes(2), ['https://example.test/new-config'], configVersion: 2);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('monitors.show', $monitor))
            ->assertOk();
        $changes = $response->viewData('redirectChanges');

        $this->assertCount(1, $changes);
        $this->assertSame(['https://example.test/old'], $changes[0]['previous_redirects']);
        $this->assertSame(['https://example.test/new'], $changes[0]['current_redirects']);
    }

    public function test_history_is_limited_to_the_ten_most_recent_redirect_changes(): void
    {
        $monitor = Monitor::factory()->create();

        for ($runNumber = 0; $runNumber <= 12; $runNumber++) {
            $this->createRun(
                $monitor,
                now()->subMinutes(12 - $runNumber),
                ['https://example.test/path-'.$runNumber],
            );
        }

        $changes = app(RedirectChangeHistory::class)->build($monitor);

        $this->assertCount(10, $changes);
        $this->assertSame(['https://example.test/path-12'], $changes[0]['current_redirects']);
        $this->assertSame(['https://example.test/path-3'], $changes[9]['current_redirects']);
    }

    /** @param list<string> $redirects */
    private function createRun(
        Monitor $monitor,
        DateTimeInterface $scheduledAt,
        array $redirects,
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
            'redirects' => $redirects,
        ]);
    }
}
