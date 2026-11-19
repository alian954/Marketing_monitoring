<?php

namespace Tests\Feature;

use App\Models\CheckRun;
use App\Models\Monitor;
use App\Models\User;
use App\Services\ResponseMetadataChangeHistory;
use DateTimeInterface;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ResponseMetadataChangeHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_monitor_detail_lists_recent_metadata_changes_and_escapes_captured_page_content(): void
    {
        $this->travelTo(Carbon::parse('2026-11-19 12:00:00', 'UTC'));
        $monitor = $this->createMonitor();
        $this->createRun($monitor, now()->subMinutes(20), 'Pre-launch', 'Coming soon.');
        $this->createRun($monitor, now()->subMinutes(15), 'Pre-launch', 'Coming soon.');
        $this->createRun($monitor, now()->subMinutes(10), 'Live campaign', 'The launch is live.');
        $this->createRun($monitor, now()->subMinutes(5), 'Launch <script>alert(1)</script>', null);
        $this->createRun($monitor, now()->subMinutes(3), null, null, status: 'failed');
        $this->createRun($monitor, now()->subMinutes(2), 'TLS detail', null, source: 'tls_daily');

        $response = $this->actingAs(User::factory()->create())
            ->get(route('monitors.show', $monitor))
            ->assertOk()
            ->assertSee('分享預覽變更歷史')
            ->assertSee('分享摘要：The launch is live.')
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false);

        $changes = $response->viewData('responseMetadataChanges');

        $this->assertCount(2, $changes);
        $this->assertSame(['title', 'description'], $changes[0]['changed_fields']);
        $this->assertSame('Live campaign', $changes[0]['previous_title']);
        $this->assertSame('Launch <script>alert(1)</script>', $changes[0]['current_title']);
        $this->assertSame('The launch is live.', $changes[0]['previous_description']);
        $this->assertNull($changes[0]['current_description']);
        $this->assertSame(['title', 'description'], $changes[1]['changed_fields']);
        $this->assertSame('Pre-launch', $changes[1]['previous_title']);
        $this->assertSame('Live campaign', $changes[1]['current_title']);
    }

    public function test_monitor_detail_does_not_compare_metadata_across_configuration_versions(): void
    {
        $monitor = $this->createMonitor();
        $this->createRun($monitor, now()->subMinutes(2), 'Old settings', 'Old description.', configVersion: 1);
        $this->createRun($monitor, now()->subMinute(), 'New settings', 'New description.', configVersion: 2);
        $monitor->forceFill(['config_version' => 2])->save();

        $response = $this->actingAs(User::factory()->create())
            ->get(route('monitors.show', $monitor))
            ->assertOk()
            ->assertSee('尚未偵測到分享預覽變更');

        $this->assertSame([], $response->viewData('responseMetadataChanges'));
    }

    public function test_monitor_detail_uses_run_id_to_order_metadata_changes_with_equal_scheduled_times(): void
    {
        $monitor = $this->createMonitor();
        $sameTime = now()->subMinute();
        $this->createRun($monitor, $sameTime, 'Earlier', 'First summary.');
        $this->createRun($monitor, $sameTime, 'Later', 'Second summary.');

        $response = $this->actingAs(User::factory()->create())
            ->get(route('monitors.show', $monitor))
            ->assertOk()
            ->assertSee('變更時間');

        $changes = $response->viewData('responseMetadataChanges');

        $this->assertCount(1, $changes);
        $this->assertSame('Earlier', $changes[0]['previous_title']);
        $this->assertSame('Later', $changes[0]['current_title']);
    }

    public function test_monitor_detail_shows_image_reference_only_changes_without_exposing_hashes(): void
    {
        $monitor = $this->createMonitor();
        $previousHash = str_repeat('a', 64);
        $currentHash = str_repeat('b', 64);
        $this->createRun($monitor, now()->subMinutes(2), null, null, imageReferenceHash: $previousHash);
        $this->createRun($monitor, now()->subMinute(), null, null, imageReferenceHash: $currentHash);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('monitors.show', $monitor))
            ->assertOk()
            ->assertSee('分享預覽圖片參照')
            ->assertSee('已偵測（參照已變更）')
            ->assertDontSee($previousHash)
            ->assertDontSee($currentHash);
        $changes = $response->viewData('responseMetadataChanges');

        $this->assertTrue($response->viewData('responseMetadataChanged'));
        $this->assertSame(['image_reference'], $changes[0]['changed_fields']);
        $this->assertTrue($changes[0]['previous_image_reference_present']);
        $this->assertTrue($changes[0]['current_image_reference_present']);
        $this->assertArrayNotHasKey('previous_image_reference_hash', $changes[0]);
        $this->assertArrayNotHasKey('current_image_reference_hash', $changes[0]);
    }

    public function test_monitor_detail_limits_the_change_history_to_the_ten_most_recent_differences(): void
    {
        $this->travelTo(Carbon::parse('2026-11-19 12:00:00', 'UTC'));
        $monitor = $this->createMonitor();

        for ($runNumber = 0; $runNumber <= 12; $runNumber++) {
            $this->createRun(
                $monitor,
                now()->subMinutes(12 - $runNumber),
                'Title '.$runNumber,
                'Description '.$runNumber,
            );
        }

        $response = $this->actingAs(User::factory()->create())
            ->get(route('monitors.show', $monitor))
            ->assertOk();
        $changes = $response->viewData('responseMetadataChanges');

        $this->assertCount(10, $changes);
        $this->assertSame('Title 12', $changes[0]['current_title']);
        $this->assertSame('Title 3', $changes[9]['current_title']);
        $this->assertNotContains('Title 2', array_column($changes, 'current_title'));
    }

    public function test_metadata_history_reads_more_than_one_batch_in_one_database_query(): void
    {
        $monitor = $this->createMonitor();
        CheckRun::factory()->count(501)->for($monitor)->create([
            'response_title' => 'Stable title',
            'response_description' => 'Stable summary.',
        ]);
        $checkRunQueryCount = 0;
        DB::listen(function (QueryExecuted $query) use (&$checkRunQueryCount): void {
            if (str_contains(mb_strtolower($query->sql), 'check_runs')) {
                $checkRunQueryCount++;
            }
        });

        $changes = app(ResponseMetadataChangeHistory::class)->build($monitor);

        $this->assertSame([], $changes);
        $this->assertSame(1, $checkRunQueryCount);
    }

    private function createMonitor(): Monitor
    {
        $url = 'https://campaign.example.test/post';

        return Monitor::factory()->create([
            'url' => $url,
            'url_hash' => Monitor::fingerprintUrl($url),
        ]);
    }

    private function createRun(
        Monitor $monitor,
        DateTimeInterface $scheduledAt,
        ?string $title,
        ?string $description,
        string $status = 'completed',
        string $source = 'scheduled',
        ?int $configVersion = null,
        ?string $imageReferenceHash = null,
    ): CheckRun {
        return CheckRun::factory()->for($monitor)->create([
            'config_version' => $configVersion ?? $monitor->config_version,
            'source' => $source,
            'status' => $status,
            'scheduled_at' => $scheduledAt,
            'finished_at' => $scheduledAt,
            'normal_status' => $status === 'completed' ? 200 : null,
            'error_type' => $status === 'completed' ? null : 'queue_job_failed',
            'response_title' => $title,
            'response_description' => $description,
            'response_image_reference_hash' => $imageReferenceHash,
        ]);
    }
}
