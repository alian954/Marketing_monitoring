<?php

namespace Tests\Feature;

use App\Models\CheckRun;
use App\Models\Monitor;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MonitorResponseMetadataChangeTest extends TestCase
{
    use RefreshDatabase;

    public function test_latest_response_metadata_is_compared_with_the_previous_completed_run(): void
    {
        $monitor = Monitor::factory()->create();
        $this->createRun($monitor, now()->subMinutes(10), 'completed', 'Pre-launch title', 'Coming soon.');
        $this->createRun($monitor, now()->subMinutes(2), 'completed', 'Live campaign title', 'Campaign is live.');

        $this->actingAs(User::factory()->create())
            ->get(route('monitors.show', $monitor))
            ->assertOk()
            ->assertSee('與上次完成檢查相比已變更。')
            ->assertSee('上次分享標題：Pre-launch title')
            ->assertSee('分享標題：Live campaign title')
            ->assertSee('上次分享摘要：Coming soon.');
    }

    public function test_latest_response_metadata_reports_when_the_image_reference_changes(): void
    {
        $monitor = Monitor::factory()->create();
        $this->createRun($monitor, now()->subMinutes(10), 'completed', null, null, imageReferenceHash: str_repeat('a', 64));
        $this->createRun($monitor, now()->subMinutes(2), 'completed', null, null, imageReferenceHash: str_repeat('b', 64));

        $this->actingAs(User::factory()->create())
            ->get(route('monitors.show', $monitor))
            ->assertOk()
            ->assertSee('分享預覽圖片參照：已偵測')
            ->assertSee('與上次完成檢查相比已變更。')
            ->assertSee('上次分享預覽圖片參照：已偵測')
            ->assertDontSee(str_repeat('a', 64))
            ->assertDontSee(str_repeat('b', 64));
    }

    public function test_latest_response_metadata_reports_when_the_summary_is_unchanged(): void
    {
        $monitor = Monitor::factory()->create();
        $this->createRun($monitor, now()->subMinutes(10), 'completed', 'Live campaign title', 'Campaign is live.');
        $this->createRun($monitor, now()->subMinutes(2), 'completed', 'Live campaign title', 'Campaign is live.');

        $this->actingAs(User::factory()->create())
            ->get(route('monitors.show', $monitor))
            ->assertOk()
            ->assertSee('與上次完成檢查一致。')
            ->assertDontSee('與上次完成檢查相比已變更。');
    }

    public function test_latest_response_metadata_uses_run_id_to_break_equal_scheduled_time_ties(): void
    {
        $monitor = Monitor::factory()->create();
        $scheduledAt = now()->subMinute();
        $this->createRun($monitor, $scheduledAt, 'completed', 'Earlier title', 'Earlier summary.');
        $this->createRun($monitor, $scheduledAt, 'completed', 'Latest title', 'Latest summary.');

        $this->actingAs(User::factory()->create())
            ->get(route('monitors.show', $monitor))
            ->assertOk()
            ->assertSee('分享標題：Latest title')
            ->assertSee('與上次完成檢查相比已變更。')
            ->assertSee('上次分享標題：Earlier title');
    }

    public function test_latest_response_metadata_reports_when_a_previous_summary_disappears(): void
    {
        $monitor = Monitor::factory()->create();
        $this->createRun($monitor, now()->subMinutes(10), 'completed', 'Live campaign title', 'Campaign is live.');
        $this->createRun($monitor, now()->subMinutes(2), 'completed', null, null);

        $this->actingAs(User::factory()->create())
            ->get(route('monitors.show', $monitor))
            ->assertOk()
            ->assertSee('與上次完成檢查相比已變更。')
            ->assertSee('上次分享標題：Live campaign title')
            ->assertSee('回應沒有提供可用的分享預覽資料。');
    }

    public function test_latest_response_metadata_is_not_compared_across_configuration_versions(): void
    {
        $monitor = Monitor::factory()->create(['config_version' => 2]);
        $this->createRun($monitor, now()->subMinutes(10), 'completed', 'Old configuration title', 'Old summary.', 1);
        $this->createRun($monitor, now()->subMinutes(2), 'completed', 'New configuration title', 'New summary.', 2);

        $this->actingAs(User::factory()->create())
            ->get(route('monitors.show', $monitor))
            ->assertOk()
            ->assertDontSee('分享預覽變化')
            ->assertSee('分享標題：New configuration title');
    }

    public function test_incomplete_latest_run_does_not_report_a_response_metadata_change(): void
    {
        $monitor = Monitor::factory()->create();
        $this->createRun($monitor, now()->subMinutes(10), 'completed', 'Live campaign title', 'Campaign is live.');
        $this->createRun($monitor, now()->subMinutes(2), 'failed', null, null);

        $this->actingAs(User::factory()->create())
            ->get(route('monitors.show', $monitor))
            ->assertOk()
            ->assertDontSee('分享預覽變化');
    }

    private function createRun(
        Monitor $monitor,
        DateTimeInterface $scheduledAt,
        string $status,
        ?string $title,
        ?string $description,
        ?int $configVersion = null,
        ?string $imageReferenceHash = null,
    ): CheckRun {
        return CheckRun::factory()->for($monitor)->create([
            'config_version' => $configVersion ?? $monitor->config_version,
            'config_snapshot' => json_encode([
                'accepted_status_codes' => [],
                'expected_text' => null,
                'expected_text_mode' => 'present',
            ], JSON_THROW_ON_ERROR),
            'source' => 'scheduled',
            'status' => $status,
            'scheduled_at' => $scheduledAt,
            'started_at' => $scheduledAt,
            'finished_at' => $scheduledAt,
            'normal_status' => $status === 'completed' ? 200 : null,
            'error_type' => $status === 'completed' ? null : 'queue_job_failed',
            'response_title' => $title,
            'response_description' => $description,
            'response_image_reference_hash' => $imageReferenceHash,
        ]);
    }
}
