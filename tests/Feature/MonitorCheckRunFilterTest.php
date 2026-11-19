<?php

namespace Tests\Feature;

use App\Models\CheckRun;
use App\Models\Incident;
use App\Models\IncidentEvent;
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

    public function test_monitor_detail_filters_and_exports_by_http_response_code(): void
    {
        $monitor = Monitor::factory()->create();
        $user = User::factory()->create();
        $matchingRun = CheckRun::factory()->for($monitor)->create(['normal_status' => 429]);
        CheckRun::factory()->for($monitor)->create(['normal_status' => 403]);
        CheckRun::factory()->for($monitor)->create(['normal_status' => null]);
        $filters = ['http_status' => 429];

        $detailResponse = $this->actingAs($user)
            ->get(route('monitors.show', ['monitor' => $monitor, ...$filters]))
            ->assertOk()
            ->assertSee('HTTP 回應碼')
            ->assertSee('value="429"', false)
            ->assertSee(route('monitors.checks.export', ['monitor' => $monitor, ...$filters]));

        $this->assertSame([$matchingRun->id], $detailResponse->viewData('runs')->getCollection()->modelKeys());
        $this->assertSame(1, $detailResponse->viewData('runs')->total());

        $exportResponse = $this->actingAs($user)
            ->get(route('monitors.checks.export', ['monitor' => $monitor, ...$filters]))
            ->assertOk();
        $exportRows = $this->csvRows($exportResponse->streamedContent());

        $this->assertCount(1, $exportRows);
        $this->assertSame((string) $matchingRun->id, $exportRows[0]['check_run_id']);
        $this->assertSame('429', $exportRows[0]['http_status']);
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
            ->get(route('monitors.show', ['monitor' => $monitor, 'status' => 'unexpected', 'source' => 'unexpected', 'http_status' => '700', 'content_match' => 'unexpected']))
            ->assertRedirect($monitorUrl)
            ->assertSessionHasErrors([
                'status' => '請選擇有效的檢查執行狀態。',
                'source' => '請選擇有效的檢查來源。',
                'http_status' => 'HTTP 回應碼必須介於 100 到 599。',
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
            'http_status' => '200',
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

    public function test_historical_unaccepted_http_responses_are_indeterminate_in_views_filters_and_export(): void
    {
        $monitor = Monitor::factory()->create([
            'expected_text' => 'Published announcement',
            'expected_response_header' => 'X-Post-State: published',
        ]);
        $user = User::factory()->create();
        $configurationSnapshot = static fn (array $acceptedStatusCodes): string => json_encode([
            'expected_text' => 'Published announcement',
            'expected_text_mode' => 'present',
            'expected_response_header' => 'X-Post-State: published',
            'accepted_status_codes' => $acceptedStatusCodes,
        ], JSON_THROW_ON_ERROR);

        $acceptedResponse = CheckRun::factory()->for($monitor)->create([
            'config_version' => 1,
            'config_snapshot' => $configurationSnapshot([]),
            'normal_status' => 200,
            'expected_text_found' => false,
            'expected_text_term_matches' => [false],
            'expected_response_header_found' => false,
            'scheduled_at' => now()->subMinutes(2),
        ]);
        $customAcceptedResponse = CheckRun::factory()->for($monitor)->create([
            'config_version' => 2,
            'config_snapshot' => $configurationSnapshot([404]),
            'normal_status' => 404,
            'expected_text_found' => false,
            'expected_text_term_matches' => [false],
            'expected_response_header_found' => false,
            'scheduled_at' => now()->subMinute(),
        ]);
        $legacyUnacceptedResponse = CheckRun::factory()->for($monitor)->create([
            'config_version' => 1,
            'config_snapshot' => $configurationSnapshot([]),
            'normal_status' => 403,
            'expected_text_found' => false,
            'expected_text_term_matches' => [false],
            'expected_response_header_found' => false,
            'scheduled_at' => now(),
        ]);
        $incident = Incident::factory()->for($monitor)->create([
            'config_version' => 1,
            'rule_key' => 'content',
        ]);
        IncidentEvent::factory()->for($incident)->for($legacyUnacceptedResponse, 'checkRun')->create([
            'event_type' => 'failure_seen',
            'evidence' => [
                'status' => 403,
                'expected_text_mode' => 'present',
                'expected_text_found' => false,
            ],
        ]);
        $headerIncident = Incident::factory()->for($monitor)->create([
            'config_version' => 1,
            'rule_key' => 'response_header',
        ]);
        IncidentEvent::factory()->for($headerIncident)->for($legacyUnacceptedResponse, 'checkRun')->create([
            'event_type' => 'failure_seen',
            'evidence' => [
                'status' => 403,
                'expected_response_header_name' => 'X-Post-State',
                'expected_response_header_found' => false,
            ],
        ]);

        $notCheckedResponse = $this->actingAs($user)
            ->get(route('monitors.show', ['monitor' => $monitor, 'content_match' => 'not_checked']))
            ->assertOk()
            ->assertSee('未進行比對或無法判定')
            ->assertSee('未檢查或無法判定')
            ->assertSee('無法判定')
            ->assertSee('標頭 X-Post-State；比對：無法判定')
            ->assertDontSee('查看逐項片段結果')
            ->assertDontSee('缺少至少一個指定片段')
            ->assertDontSee('原始回應缺少至少一個片段');

        $this->assertSame([$legacyUnacceptedResponse->id], $notCheckedResponse->viewData('runs')->getCollection()->modelKeys());

        $missingResponse = $this->actingAs($user)
            ->get(route('monitors.show', ['monitor' => $monitor, 'content_match' => 'missing']))
            ->assertOk();
        $this->assertEqualsCanonicalizing(
            [$acceptedResponse->id, $customAcceptedResponse->id],
            $missingResponse->viewData('runs')->getCollection()->modelKeys(),
        );

        $exportResponse = $this->actingAs($user)
            ->get(route('monitors.checks.export', ['monitor' => $monitor, 'content_match' => 'not_checked']))
            ->assertOk();
        $exportRows = $this->csvRows($exportResponse->streamedContent());

        $this->assertCount(1, $exportRows);
        $this->assertSame((string) $legacyUnacceptedResponse->id, $exportRows[0]['check_run_id']);
        $this->assertSame('', $exportRows[0]['expected_text_found']);
        $this->assertSame('', $exportRows[0]['expected_response_header_found']);

        $this->actingAs($user)
            ->get(route('monitors.index'))
            ->assertOk()
            ->assertSee('尚未比對')
            ->assertDontSee('缺少至少一個片段');
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
