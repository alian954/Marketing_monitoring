<?php

namespace Tests\Feature;

use App\Models\CheckRun;
use App\Models\Monitor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class MonitorCheckSuccessRateTest extends TestCase
{
    use RefreshDatabase;

    public function test_failed_scheduled_runs_lower_the_success_rate_while_incomplete_and_other_runs_are_excluded(): void
    {
        $monitor = Monitor::factory()->create();
        CheckRun::factory()->for($monitor)->create([
            'source' => 'scheduled',
            'status' => 'completed',
            'scheduled_at' => now()->subMinutes(20),
            'finished_at' => now()->subMinutes(20),
            'normal_status' => 200,
            'error_type' => null,
        ]);
        CheckRun::factory()->for($monitor)->create([
            'source' => 'scheduled',
            'status' => 'failed',
            'scheduled_at' => now()->subMinutes(10),
            'finished_at' => now()->subMinutes(10),
            'normal_status' => 200,
            'error_type' => 'queue_job_failed',
        ]);
        CheckRun::factory()->for($monitor)->create([
            'source' => 'scheduled',
            'status' => 'queued',
            'scheduled_at' => now()->subMinutes(5),
            'finished_at' => null,
        ]);
        CheckRun::factory()->for($monitor)->create([
            'source' => 'manual',
            'status' => 'completed',
            'scheduled_at' => now()->subMinutes(4),
            'finished_at' => now()->subMinutes(4),
        ]);
        CheckRun::factory()->for($monitor)->create([
            'source' => 'tls_daily',
            'status' => 'completed',
            'scheduled_at' => now()->subMinutes(3),
            'finished_at' => now()->subMinutes(3),
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('monitors.show', $monitor))
            ->assertOk()
            ->assertSee('依排程檢查結果計算成功率')
            ->assertSee('可判定')
            ->assertSee('2 次');

        foreach ($response->viewData('checkSuccessStats') as $period) {
            $this->assertSame(50.0, $period['success_rate']);
            $this->assertSame(1, $period['successful_checks']);
            $this->assertSame(2, $period['ended_checks']);
        }
    }

    public function test_incomplete_scheduled_runs_do_not_create_a_success_rate(): void
    {
        $monitor = Monitor::factory()->create();
        CheckRun::factory()->for($monitor)->create([
            'source' => 'scheduled',
            'status' => 'running',
            'finished_at' => null,
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('monitors.show', $monitor))
            ->assertOk();

        foreach ($response->viewData('checkSuccessStats') as $period) {
            $this->assertNull($period['success_rate']);
            $this->assertSame(0, $period['successful_checks']);
            $this->assertSame(0, $period['ended_checks']);
        }

    }

    public function test_monitor_detail_shows_daily_success_history_for_thirty_taiwan_calendar_dates(): void
    {
        $this->travelTo(Carbon::parse('2026-11-21 00:30:00', 'UTC'));
        $monitor = Monitor::factory()->create();
        $today = Carbon::parse('2026-11-21 00:10:00', 'UTC');
        $this->createScheduledRun($monitor, $today, 200);
        $this->createScheduledRun($monitor, $today->copy()->addMinutes(10), 503);
        $this->createScheduledRun($monitor, Carbon::parse('2026-11-20 15:59:00', 'UTC'), 503);
        $this->createScheduledRun($monitor, Carbon::parse('2026-10-22 15:00:00', 'UTC'), 503);
        CheckRun::factory()->for($monitor)->create([
            'source' => 'manual',
            'status' => 'completed',
            'finished_at' => $today,
            'normal_status' => 200,
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('monitors.show', $monitor))
            ->assertOk()
            ->assertSee('查看每日檢查明細')
            ->assertSee('近 30 個台灣日期每日檢查成功率');

        $dailyHistory = collect($response->viewData('dailyCheckHistory'))->keyBy('date');

        $this->assertCount(30, $dailyHistory);
        $this->assertSame('2026-10-23', $dailyHistory->keys()->first());
        $this->assertSame('2026-11-21', $dailyHistory->keys()->last());
        $this->assertSame(50.0, $dailyHistory['2026-11-21']['success_rate']);
        $this->assertSame('warning', $dailyHistory['2026-11-21']['state']);
        $this->assertSame(2, $dailyHistory['2026-11-21']['total_checks']);
        $this->assertSame(1, $dailyHistory['2026-11-21']['successful_checks']);
        $this->assertSame(0.0, $dailyHistory['2026-11-20']['success_rate']);
        $this->assertSame('issue', $dailyHistory['2026-11-20']['state']);
        $this->assertNull($dailyHistory['2026-11-19']['success_rate']);
        $this->assertSame('unknown', $dailyHistory['2026-11-19']['state']);
        $this->assertStringContainsString('成功 1／2 次', $dailyHistory['2026-11-21']['accessible_label']);
    }

    public function test_missing_required_text_lowers_the_scheduled_check_success_rate(): void
    {
        $monitor = Monitor::factory()->create();
        $snapshot = $this->configSnapshot('Campaign headline');
        CheckRun::factory()->for($monitor)->create([
            'config_snapshot' => $snapshot,
            'source' => 'scheduled',
            'status' => 'completed',
            'scheduled_at' => now()->subMinutes(10),
            'finished_at' => now()->subMinutes(10),
            'normal_status' => 200,
            'expected_text_found' => true,
        ]);
        CheckRun::factory()->for($monitor)->create([
            'config_snapshot' => $snapshot,
            'source' => 'scheduled',
            'status' => 'completed',
            'scheduled_at' => now()->subMinutes(5),
            'finished_at' => now()->subMinutes(5),
            'normal_status' => 200,
            'expected_text_found' => false,
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('monitors.show', $monitor))
            ->assertOk();

        foreach ($response->viewData('checkSuccessStats') as $period) {
            $this->assertSame(50.0, $period['success_rate']);
            $this->assertSame(1, $period['successful_checks']);
            $this->assertSame(2, $period['ended_checks']);
        }
    }

    public function test_social_post_checks_without_expected_text_are_excluded_from_success_rate(): void
    {
        $monitor = Monitor::factory()->create();
        $configuredSnapshot = json_encode([
            'url' => 'https://www.dcard.tw/f/makeup/p/262476944',
            'accepted_status_codes' => [],
            'expected_text' => 'Campaign headline',
            'expected_text_mode' => 'present',
        ], JSON_THROW_ON_ERROR);
        $uncheckedSnapshot = json_encode([
            'url' => 'https://www.dcard.tw/f/makeup/p/262476944',
            'accepted_status_codes' => [],
            'expected_text' => null,
            'expected_text_mode' => 'present',
        ], JSON_THROW_ON_ERROR);
        CheckRun::factory()->for($monitor)->create([
            'config_snapshot' => $configuredSnapshot,
            'source' => 'scheduled',
            'status' => 'completed',
            'scheduled_at' => now()->subMinutes(10),
            'finished_at' => now()->subMinutes(10),
            'normal_status' => 200,
            'expected_text_found' => true,
        ]);
        $uncheckedRun = CheckRun::factory()->for($monitor)->create([
            'config_snapshot' => $uncheckedSnapshot,
            'source' => 'scheduled',
            'status' => 'completed',
            'scheduled_at' => now()->subMinutes(5),
            'finished_at' => now()->subMinutes(5),
            'normal_status' => 200,
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('monitors.show', $monitor))
            ->assertOk();

        $this->assertTrue($uncheckedRun->hasIndeterminateContentMatch());

        foreach ($response->viewData('checkSuccessStats') as $period) {
            $this->assertSame(100.0, $period['success_rate']);
            $this->assertSame(1, $period['successful_checks']);
            $this->assertSame(1, $period['ended_checks']);
        }
    }

    public function test_social_baseline_checks_skip_initial_sample_and_fail_when_content_changes(): void
    {
        $monitor = Monitor::factory()->create();
        $snapshot = json_encode([
            'url' => 'https://www.dcard.tw/f/makeup/p/262476944',
            'accepted_status_codes' => [],
            'expected_text' => null,
            'expected_text_mode' => 'present',
            'alert_on_visible_text_change' => true,
        ], JSON_THROW_ON_ERROR);
        $initialRun = CheckRun::factory()->for($monitor)->create([
            'config_snapshot' => $snapshot,
            'source' => 'scheduled',
            'status' => 'completed',
            'scheduled_at' => now()->subMinutes(15),
            'finished_at' => now()->subMinutes(15),
            'normal_status' => 200,
            'response_visible_text_changed' => null,
        ]);
        $stableRun = CheckRun::factory()->for($monitor)->create([
            'config_snapshot' => $snapshot,
            'source' => 'scheduled',
            'status' => 'completed',
            'scheduled_at' => now()->subMinutes(10),
            'finished_at' => now()->subMinutes(10),
            'normal_status' => 200,
            'response_visible_text_changed' => false,
        ]);
        $changedRun = CheckRun::factory()->for($monitor)->create([
            'config_snapshot' => $snapshot,
            'source' => 'scheduled',
            'status' => 'completed',
            'scheduled_at' => now()->subMinutes(5),
            'finished_at' => now()->subMinutes(5),
            'normal_status' => 200,
            'response_visible_text_changed' => true,
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('monitors.show', $monitor))
            ->assertOk();

        $this->assertTrue($initialRun->hasIndeterminateContentMatch());
        $this->assertTrue($stableRun->passesMonitorChecks());
        $this->assertFalse($changedRun->passesMonitorChecks());

        foreach ($response->viewData('checkSuccessStats') as $period) {
            $this->assertSame(50.0, $period['success_rate']);
            $this->assertSame(1, $period['successful_checks']);
            $this->assertSame(2, $period['ended_checks']);
        }
    }

    public function test_social_dns_failures_without_expected_text_lower_success_rate(): void
    {
        $monitor = Monitor::factory()->create();
        $uncheckedSnapshot = json_encode([
            'url' => 'https://www.dcard.tw/f/makeup/p/262476944',
            'accepted_status_codes' => [],
            'expected_text' => null,
            'expected_text_mode' => 'present',
        ], JSON_THROW_ON_ERROR);
        CheckRun::factory()->for($monitor)->create([
            'config_snapshot' => $uncheckedSnapshot,
            'source' => 'scheduled',
            'status' => 'completed',
            'scheduled_at' => now()->subMinutes(10),
            'finished_at' => now()->subMinutes(10),
            'normal_status' => 200,
        ]);
        $failedRun = CheckRun::factory()->for($monitor)->create([
            'config_snapshot' => $uncheckedSnapshot,
            'source' => 'scheduled',
            'status' => 'completed',
            'scheduled_at' => now()->subMinutes(5),
            'finished_at' => now()->subMinutes(5),
            'normal_status' => null,
            'error_type' => 'dns_timeout',
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('monitors.show', $monitor))
            ->assertOk();

        $this->assertFalse($failedRun->hasIndeterminateContentMatch());

        foreach ($response->viewData('checkSuccessStats') as $period) {
            $this->assertSame(0.0, $period['success_rate']);
            $this->assertSame(0, $period['successful_checks']);
            $this->assertSame(1, $period['ended_checks']);
        }
    }

    public function test_absent_text_condition_counts_a_missing_phrase_as_success(): void
    {
        $monitor = Monitor::factory()->create();
        $snapshot = $this->configSnapshot('Expired promotion', 'absent');
        CheckRun::factory()->for($monitor)->create([
            'config_snapshot' => $snapshot,
            'source' => 'scheduled',
            'status' => 'completed',
            'scheduled_at' => now()->subMinutes(10),
            'finished_at' => now()->subMinutes(10),
            'normal_status' => 200,
            'expected_text_found' => false,
        ]);
        CheckRun::factory()->for($monitor)->create([
            'config_snapshot' => $snapshot,
            'source' => 'scheduled',
            'status' => 'completed',
            'scheduled_at' => now()->subMinutes(5),
            'finished_at' => now()->subMinutes(5),
            'normal_status' => 200,
            'expected_text_found' => true,
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('monitors.show', $monitor))
            ->assertOk();

        foreach ($response->viewData('checkSuccessStats') as $period) {
            $this->assertSame(50.0, $period['success_rate']);
            $this->assertSame(1, $period['successful_checks']);
            $this->assertSame(2, $period['ended_checks']);
        }
    }

    public function test_only_accepted_status_codes_count_as_successful_checks(): void
    {
        $monitor = Monitor::factory()->create();
        $runCases = [
            ['status' => 200, 'accepted_status_codes' => []],
            ['status' => 429, 'accepted_status_codes' => []],
            ['status' => 429, 'accepted_status_codes' => [429]],
        ];

        foreach ($runCases as $index => $runCase) {
            $finishedAt = now()->subMinutes(15 - ($index * 5));

            CheckRun::factory()->for($monitor)->create([
                'config_snapshot' => $this->configSnapshot(
                    expectedText: null,
                    acceptedStatusCodes: $runCase['accepted_status_codes'],
                ),
                'source' => 'scheduled',
                'status' => 'completed',
                'scheduled_at' => $finishedAt,
                'finished_at' => $finishedAt,
                'normal_status' => $runCase['status'],
            ]);
        }

        $response = $this->actingAs(User::factory()->create())
            ->get(route('monitors.show', $monitor))
            ->assertOk();

        foreach ($response->viewData('checkSuccessStats') as $period) {
            $this->assertSame(66.67, $period['success_rate']);
            $this->assertSame(2, $period['successful_checks']);
            $this->assertSame(3, $period['ended_checks']);
        }
    }

    /** @param list<int> $acceptedStatusCodes */
    private function configSnapshot(?string $expectedText, string $expectedTextMode = 'present', array $acceptedStatusCodes = []): string
    {
        return json_encode([
            'name' => 'Marketing post',
            'url' => 'https://example.com/landing',
            'accepted_status_codes' => $acceptedStatusCodes,
            'expected_text' => $expectedText,
            'expected_text_mode' => $expectedTextMode,
            'response_time_threshold_ms' => null,
            'allowed_redirect_hosts' => [],
        ], JSON_THROW_ON_ERROR);
    }

    private function createScheduledRun(Monitor $monitor, Carbon $finishedAt, int $normalStatus): CheckRun
    {
        return CheckRun::factory()->for($monitor)->create([
            'source' => 'scheduled',
            'status' => 'completed',
            'scheduled_at' => $finishedAt,
            'finished_at' => $finishedAt,
            'normal_status' => $normalStatus,
            'error_type' => null,
        ]);
    }
}
