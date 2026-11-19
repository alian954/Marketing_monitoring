<?php

namespace Tests\Feature;

use App\Models\CheckRun;
use App\Models\Monitor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MonitorCheckRunFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_monitor_detail_filters_check_runs_and_csv_by_status_and_source(): void
    {
        $monitor = Monitor::factory()->create();
        $user = User::factory()->create();
        $matchingRun = CheckRun::factory()->for($monitor)->create([
            'source' => 'scheduled',
            'status' => 'failed',
            'error_type' => 'scheduled_failure_signal',
        ]);
        CheckRun::factory()->for($monitor)->create([
            'source' => 'manual',
            'status' => 'failed',
            'error_type' => 'manual_failure_signal',
        ]);
        CheckRun::factory()->for($monitor)->create([
            'source' => 'scheduled',
            'status' => 'completed',
            'error_type' => null,
        ]);
        $filters = ['status' => 'failed', 'source' => 'scheduled'];

        $response = $this->actingAs($user)
            ->get(route('monitors.show', ['monitor' => $monitor, ...$filters]))
            ->assertOk()
            ->assertSee(route('monitors.checks.export', ['monitor' => $monitor, ...$filters]));
        $runs = $response->viewData('runs');

        $this->assertSame([$matchingRun->id], $runs->getCollection()->modelKeys());
        $this->assertSame(1, $runs->total());

        $csvResponse = $this->actingAs($user)
            ->get(route('monitors.checks.export', ['monitor' => $monitor, ...$filters]))
            ->assertOk();
        $csvRows = $this->csvRows($csvResponse->streamedContent());

        $this->assertCount(1, $csvRows);
        $this->assertSame((string) $matchingRun->id, $csvRows[0]['check_run_id']);
    }

    public function test_monitor_detail_filters_and_exports_each_text_match_result(): void
    {
        $monitor = Monitor::factory()->create(['expected_text' => 'Campaign headline']);
        $user = User::factory()->create();
        $runsByTextMatch = [
            'found' => CheckRun::factory()->for($monitor)->create(['expected_text_found' => true]),
            'missing' => CheckRun::factory()->for($monitor)->create(['expected_text_found' => false]),
            'not_checked' => CheckRun::factory()->for($monitor)->create(['expected_text_found' => null]),
        ];

        $filterCases = [
            ['filter' => 'found', 'run' => $runsByTextMatch['found'], 'label' => '比對結果為「找到」', 'csv_value' => '1'],
            ['filter' => 'missing', 'run' => $runsByTextMatch['missing'], 'label' => '比對結果為「未找到」', 'csv_value' => '0'],
            ['filter' => 'not_checked', 'run' => $runsByTextMatch['not_checked'], 'label' => '未進行比對', 'csv_value' => ''],
        ];

        foreach ($filterCases as $filterCase) {
            $filters = ['content_match' => $filterCase['filter']];
            $detailResponse = $this->actingAs($user)
                ->get(route('monitors.show', ['monitor' => $monitor, ...$filters]))
                ->assertOk()
                ->assertSee('文字比對結果')
                ->assertSee($filterCase['label'])
                ->assertSee(route('monitors.checks.export', ['monitor' => $monitor, ...$filters]));

            $this->assertSame([$filterCase['run']->id], $detailResponse->viewData('runs')->getCollection()->modelKeys());

            $exportResponse = $this->actingAs($user)
                ->get(route('monitors.checks.export', ['monitor' => $monitor, ...$filters]))
                ->assertOk();
            $exportRows = $this->csvRows($exportResponse->streamedContent());

            $this->assertCount(1, $exportRows);
            $this->assertSame((string) $filterCase['run']->id, $exportRows[0]['check_run_id']);
            $this->assertSame($filterCase['csv_value'], $exportRows[0]['expected_text_found']);
        }
    }

    public function test_monitor_detail_uses_forbidden_phrase_labels_for_absent_conditions(): void
    {
        $monitor = Monitor::factory()->create([
            'expected_text' => "Internal draft\nEmbargoed",
            'expected_text_mode' => 'absent',
        ]);
        $absentSnapshot = json_encode(['expected_text_mode' => 'absent'], JSON_THROW_ON_ERROR);
        $foundRun = CheckRun::factory()->for($monitor)->create([
            'config_snapshot' => $absentSnapshot,
            'expected_text_found' => true,
        ]);
        $missingRun = CheckRun::factory()->for($monitor)->create([
            'config_snapshot' => $absentSnapshot,
            'expected_text_found' => false,
        ]);
        $user = User::factory()->create();

        $foundResponse = $this->actingAs($user)
            ->get(route('monitors.show', ['monitor' => $monitor, 'content_match' => 'found']))
            ->assertOk()
            ->assertSee('比對結果為「找到」')
            ->assertSee('找到禁止文字');
        $this->assertSame([$foundRun->id], $foundResponse->viewData('runs')->getCollection()->modelKeys());

        $missingResponse = $this->actingAs($user)
            ->get(route('monitors.show', ['monitor' => $monitor, 'content_match' => 'missing']))
            ->assertOk()
            ->assertSee('比對結果為「未找到」')
            ->assertSee('未找到禁止文字');
        $this->assertSame([$missingRun->id], $missingResponse->viewData('runs')->getCollection()->modelKeys());
    }

    public function test_historical_text_results_keep_the_mode_captured_at_check_time(): void
    {
        $monitor = Monitor::factory()->create([
            'expected_text' => "Internal draft\nEmbargoed",
            'expected_text_mode' => 'absent',
        ]);
        $run = CheckRun::factory()->for($monitor)->create([
            'config_snapshot' => json_encode(['expected_text_mode' => 'present'], JSON_THROW_ON_ERROR),
            'expected_text_found' => false,
        ]);
        $user = User::factory()->create();

        $detailResponse = $this->actingAs($user)
            ->get(route('monitors.show', $monitor))
            ->assertOk()
            ->assertSee('原始回應缺少至少一個指定片段')
            ->assertSee('缺少至少一個指定片段');
        $this->assertSame([$run->id], $detailResponse->viewData('runs')->getCollection()->modelKeys());

        $this->actingAs($user)
            ->get(route('monitors.index'))
            ->assertOk()
            ->assertSee('缺少至少一個片段')
            ->assertDontSee('未找到禁止文字');
    }

    public function test_monitor_without_a_text_condition_does_not_show_text_filter_or_history_column(): void
    {
        $monitor = Monitor::factory()->create();
        CheckRun::factory()->for($monitor)->create();

        $this->actingAs(User::factory()->create())
            ->get(route('monitors.show', $monitor))
            ->assertOk()
            ->assertDontSee('id="check-run-content-match"', false)
            ->assertDontSee('<th scope="col">文字比對</th>', false);
    }

    public function test_monitor_detail_filters_and_exports_by_inclusive_taiwan_calendar_dates(): void
    {
        $monitor = Monitor::factory()->create();
        $user = User::factory()->create();
        $runs = [
            'before' => CheckRun::factory()->for($monitor)->create(['scheduled_at' => '2026-11-18 15:59:59']),
            'start' => CheckRun::factory()->for($monitor)->create(['scheduled_at' => '2026-11-18 16:00:00']),
            'end' => CheckRun::factory()->for($monitor)->create(['scheduled_at' => '2026-11-19 15:59:59']),
            'after' => CheckRun::factory()->for($monitor)->create(['scheduled_at' => '2026-11-19 16:00:00']),
        ];
        $filters = ['date_from' => '2026-11-19', 'date_to' => '2026-11-19'];

        $detailResponse = $this->actingAs($user)
            ->get(route('monitors.show', ['monitor' => $monitor, ...$filters]))
            ->assertOk()
            ->assertSee('日期採台灣時間，開始與結束日期均包含當日。')
            ->assertSee('type="date"', false)
            ->assertSee(route('monitors.checks.export', ['monitor' => $monitor, ...$filters]));

        $this->assertEqualsCanonicalizing(
            [$runs['start']->id, $runs['end']->id],
            $detailResponse->viewData('runs')->getCollection()->modelKeys(),
        );

        $exportResponse = $this->actingAs($user)
            ->get(route('monitors.checks.export', ['monitor' => $monitor, ...$filters]))
            ->assertOk();
        $exportRows = $this->csvRows($exportResponse->streamedContent());

        $this->assertEqualsCanonicalizing(
            [(string) $runs['start']->id, (string) $runs['end']->id],
            array_column($exportRows, 'check_run_id'),
        );
    }

    public function test_monitor_detail_displays_confirmation_checks_with_their_actual_source(): void
    {
        $monitor = Monitor::factory()->create();
        $user = User::factory()->create();
        $confirmationRun = CheckRun::factory()->for($monitor)->create([
            'source' => 'confirmation',
            'status' => 'completed',
        ]);

        $response = $this->actingAs($user)
            ->get(route('monitors.show', ['monitor' => $monitor, 'source' => 'confirmation']))
            ->assertOk()
            ->assertSee('確認延遲檢查');
        $runs = $response->viewData('runs');

        $this->assertSame([$confirmationRun->id], $runs->getCollection()->modelKeys());
        $this->assertSame(1, $runs->total());
    }

    public function test_monitor_detail_shows_a_filtered_empty_state_when_no_checks_match(): void
    {
        $monitor = Monitor::factory()->create();
        $user = User::factory()->create();
        CheckRun::factory()->for($monitor)->create(['source' => 'scheduled']);

        $response = $this->actingAs($user)
            ->get(route('monitors.show', ['monitor' => $monitor, 'source' => 'manual']))
            ->assertOk()
            ->assertSee('沒有符合篩選條件的檢查紀錄')
            ->assertSee('清除篩選')
            ->assertSee(route('monitors.show', $monitor));

        $this->assertSame(0, $response->viewData('runs')->total());
    }

    public function test_monitor_detail_rejects_unknown_history_filter_values(): void
    {
        $monitor = Monitor::factory()->create();
        $user = User::factory()->create();
        $monitorUrl = route('monitors.show', $monitor);

        $this->actingAs($user)
            ->from($monitorUrl)
            ->get(route('monitors.show', ['monitor' => $monitor, 'status' => 'unexpected', 'source' => 'unexpected', 'content_match' => 'unexpected']))
            ->assertRedirect($monitorUrl)
            ->assertSessionHasErrors([
                'status' => '請選擇有效的檢查執行狀態。',
                'source' => '請選擇有效的檢查來源。',
                'content_match' => '請選擇有效的文字比對篩選條件。',
            ]);
    }

    public function test_monitor_detail_rejects_invalid_or_reversed_date_filters(): void
    {
        $monitor = Monitor::factory()->create();
        $user = User::factory()->create();
        $monitorUrl = route('monitors.show', $monitor);

        $this->actingAs($user)
            ->from($monitorUrl)
            ->get(route('monitors.show', ['monitor' => $monitor, 'date_from' => '2026-02-30']))
            ->assertRedirect($monitorUrl)
            ->assertSessionHasErrors(['date_from' => '開始日期格式無效。']);

        $this->actingAs($user)
            ->from($monitorUrl)
            ->get(route('monitors.show', ['monitor' => $monitor, 'date_from' => '2026-11-20', 'date_to' => '2026-11-19']))
            ->assertRedirect($monitorUrl)
            ->assertSessionHasErrors(['date_to' => '結束日期不可早於開始日期。']);
    }

    public function test_monitor_detail_keeps_filters_in_check_history_pagination_links(): void
    {
        $monitor = Monitor::factory()->create(['expected_text' => 'Campaign headline']);
        $user = User::factory()->create();
        CheckRun::factory()->for($monitor)->count(51)->create([
            'source' => 'scheduled',
            'status' => 'failed',
            'expected_text_found' => true,
        ]);
        $filters = [
            'status' => 'failed',
            'source' => 'scheduled',
            'content_match' => 'found',
            'date_from' => '2026-11-01',
            'date_to' => '2026-11-30',
        ];

        $response = $this->actingAs($user)
            ->get(route('monitors.show', ['monitor' => $monitor, ...$filters]))
            ->assertOk()
            ->assertSee(route('monitors.show', ['monitor' => $monitor, ...$filters, 'page' => 2]));

        $this->assertSame(51, $response->viewData('runs')->total());
    }

    /** @return array<int, array<string, string>> */
    private function csvRows(string $content): array
    {
        $lines = preg_split('/\r\n|\n|\r/', trim($content));
        $headers = str_getcsv(array_shift($lines));
        $rows = array_map(static fn (string $line): array => str_getcsv($line), $lines);

        return array_map(
            static fn (array $row): array => array_combine($headers, $row),
            $rows,
        );
    }
}
