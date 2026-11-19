<?php

namespace Tests\Feature;

use App\Jobs\RunMonitorCheck;
use App\Models\Monitor;
use App\Models\User;
use App\Services\Probe\HostResolver;
use App\Services\Probe\UrlSafetyPolicy;
use App\Services\RunScheduler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SocialPostImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $resolver = new class implements HostResolver
        {
            /** @return list<string> */
            public function resolve(string $hostname): array
            {
                return ['93.184.216.34'];
            }
        };

        $this->app->instance(UrlSafetyPolicy::class, new UrlSafetyPolicy($resolver));
    }

    public function test_csv_can_preview_and_import_social_post_text_conditions(): void
    {
        Queue::fake([RunMonitorCheck::class]);
        $user = User::factory()->create();
        $csv = $this->csvContent(
            ['name', 'url', 'label', 'expected_text'],
            [[
                'Spring campaign',
                'https://www.instagram.com/p/ABC123/',
                'Campaign',
                'Campaign, launch headline',
            ]],
        );

        $preview = $this->actingAs($user)
            ->post(route('monitors.import.preview'), [
                'csv_file' => UploadedFile::fake()->createWithContent('monitors.csv', $csv),
            ])
            ->assertOk()
            ->assertSee('預期文字條件')
            ->assertSee('Campaign, launch headline')
            ->assertSee('可匯入');

        $this->assertSame(1, $preview->viewData('validCount'));
        $previewRow = $preview->viewData('previewRows')[0];
        $this->assertTrue($previewRow['eligible']);
        $this->assertSame('present', $previewRow['expected_text_mode']);
        $this->assertDatabaseCount('monitors', 0);

        $this->post(route('monitors.import.commit'), ['preview_token' => $preview->viewData('previewToken')])
            ->assertRedirect(route('monitors.index'));

        $monitor = Monitor::query()->sole();
        $this->assertSame('Campaign, launch headline', $monitor->expected_text);
        $this->assertSame('present', $monitor->expected_text_mode);
        $this->assertSame('Instagram', $monitor->socialPlatform());
        $this->assertDatabaseHas('monitors', [
            'id' => $monitor->id,
            'social_platform' => 'instagram',
        ]);

        $run = app(RunScheduler::class)->enqueue($monitor, 'manual');
        $this->assertNotNull($run);
        $snapshot = json_decode($run->config_snapshot, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('Campaign, launch headline', $snapshot['expected_text']);
        $this->assertSame('present', $snapshot['expected_text_mode']);
        Queue::assertPushed(RunMonitorCheck::class, 1);
    }

    public function test_csv_can_preview_and_import_any_match_social_post_conditions(): void
    {
        $csv = $this->csvContent(
            ['name', 'url', 'label', 'expected_text', 'expected_text_mode'],
            [[
                'Alternative headline',
                'https://www.threads.com/@creator/post/ANY123',
                'Campaign',
                "Campaign headline\nSpring sale",
                'present_any',
            ]],
        );

        $response = $this->actingAs(User::factory()->create())
            ->post(route('monitors.import.preview'), [
                'csv_file' => UploadedFile::fake()->createWithContent('monitors.csv', $csv),
            ])
            ->assertOk()
            ->assertSee('至少包含一個片段：Campaign headline、Spring sale')
            ->assertSee('可匯入');

        $this->assertSame('present_any', $response->viewData('previewRows')[0]['expected_text_mode']);
        $this->post(route('monitors.import.commit'), ['preview_token' => $response->viewData('previewToken')])
            ->assertRedirect(route('monitors.index'));

        $this->assertDatabaseHas('monitors', [
            'url_hash' => Monitor::fingerprintUrl('https://www.threads.com/@creator/post/ANY123'),
            'expected_text_mode' => 'present_any',
        ]);
    }

    public function test_csv_can_import_response_header_conditions_without_exposing_the_expected_value(): void
    {
        Queue::fake([RunMonitorCheck::class]);
        $condition = 'X-Robots-Tag: private-import-value';
        $csv = $this->csvContent(
            ['name', 'url', 'label', 'expected_response_header'],
            [['Header check', 'https://example.com/robots', 'SEO', $condition]],
        );

        $preview = $this->actingAs(User::factory()->create())
            ->post(route('monitors.import.preview'), [
                'csv_file' => UploadedFile::fake()->createWithContent('monitors.csv', $csv),
            ])
            ->assertOk()
            ->assertSee('HTTP 標頭條件')
            ->assertSee('X-Robots-Tag')
            ->assertDontSee('private-import-value');

        $this->assertSame(1, $preview->viewData('validCount'));
        $previewRow = $preview->viewData('previewRows')[0];
        $this->assertSame('X-Robots-Tag', $previewRow['expected_response_header_name']);
        $this->assertArrayNotHasKey('expected_response_header', $previewRow);
        $this->assertStringNotContainsString('private-import-value', (string) session('monitor_import_preview'));
        $this->assertDatabaseCount('monitors', 0);

        $this->post(route('monitors.import.commit'), ['preview_token' => $preview->viewData('previewToken')])
            ->assertRedirect(route('monitors.index'));

        $monitor = Monitor::query()->sole();
        $rawCondition = (string) DB::table('monitors')->where('id', $monitor->id)->value('expected_response_header');
        $this->assertSame($condition, $monitor->expected_response_header);
        $this->assertStringNotContainsString('private-import-value', $rawCondition);

        $run = app(RunScheduler::class)->enqueue($monitor, 'manual');
        $this->assertNotNull($run);
        $rawSnapshot = (string) DB::table('check_runs')->where('id', $run->id)->value('config_snapshot');
        $this->assertStringNotContainsString('private-import-value', $rawSnapshot);
        $snapshot = json_decode($run->config_snapshot, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($condition, $snapshot['expected_response_header']);
        Queue::assertPushed(RunMonitorCheck::class, 1);
    }

    public function test_csv_rejects_malformed_response_header_conditions_and_skips_them_on_commit(): void
    {
        $condition = 'private-value-without-colon';
        $csv = $this->csvContent(
            ['name', 'url', 'label', 'expected_response_header'],
            [['Invalid header', 'https://example.com/invalid-header', 'SEO', $condition]],
        );

        $preview = $this->actingAs(User::factory()->create())
            ->post(route('monitors.import.preview'), [
                'csv_file' => UploadedFile::fake()->createWithContent('monitors.csv', $csv),
            ])
            ->assertOk()
            ->assertSee('請使用「標頭名稱: 預期值」格式；只檢查存在時可留空預期值。')
            ->assertDontSee($condition);

        $this->assertSame(0, $preview->viewData('validCount'));
        $this->assertSame(1, $preview->viewData('invalidCount'));

        $this->post(route('monitors.import.commit'), ['preview_token' => $preview->viewData('previewToken')])
            ->assertRedirect(route('monitors.index'));

        $this->assertDatabaseCount('monitors', 0);
    }

    public function test_csv_can_import_custom_intervals_with_social_post_conditions(): void
    {
        Queue::fake([RunMonitorCheck::class]);
        $this->freezeTime();
        config(['monitoring.request_timeout_seconds' => 10]);
        $condition = 'X-Robots-Tag: campaign-index';
        $csv = $this->csvContent(
            ['name', 'url', 'label', 'expected_text', 'expected_text_mode', 'expected_response_header', 'interval_seconds', 'timeout_seconds', 'response_time_threshold_ms'],
            [[
                'Spring campaign',
                'https://www.instagram.com/reel/ABC123/',
                'Campaign',
                "Campaign headline\nSpring sale",
                'present_any',
                $condition,
                '300',
                '15',
                '14000',
            ], [
                'Default frequency',
                'https://example.com/default-frequency',
                'Ops',
                '',
                'present',
                '',
                '',
                '',
                '',
            ]],
        );

        $preview = $this->actingAs(User::factory()->create())
            ->post(route('monitors.import.preview'), [
                'csv_file' => UploadedFile::fake()->createWithContent('monitors.csv', $csv),
            ])
            ->assertOk()
            ->assertSee('至少包含一個片段：Campaign headline、Spring sale')
            ->assertSee('HTTP 標頭條件')
            ->assertSee('5 分鐘')
            ->assertSee('15 分鐘')
            ->assertSee('14,000 毫秒')
            ->assertDontSee('campaign-index');

        $this->assertSame(2, $preview->viewData('validCount'));
        $previewRows = $preview->viewData('previewRows');
        $previewRow = $previewRows[0];
        $this->assertSame(300, $previewRow['interval_seconds']);
        $this->assertSame('X-Robots-Tag', $previewRow['expected_response_header_name']);
        $this->assertSame(15, $previewRow['timeout_seconds']);
        $this->assertSame(14000, $previewRow['response_time_threshold_ms']);
        $this->assertSame((int) config('monitoring.interval_seconds'), $previewRows[1]['interval_seconds']);
        $this->assertSame((int) config('monitoring.request_timeout_seconds'), $previewRows[1]['timeout_seconds']);
        $this->assertNull($previewRows[1]['response_time_threshold_ms']);
        $this->assertDatabaseCount('monitors', 0);

        $this->post(route('monitors.import.commit'), ['preview_token' => $preview->viewData('previewToken')])
            ->assertRedirect(route('monitors.index'));

        $this->assertDatabaseCount('monitors', 2);
        $monitor = Monitor::query()->where('name', 'Spring campaign')->sole();
        $this->assertSame(300, $monitor->interval_seconds);
        $this->assertSame('present_any', $monitor->expected_text_mode);
        $this->assertSame($condition, $monitor->expected_response_header);
        $this->assertSame(15, $monitor->timeout_seconds);
        $this->assertSame(14000, $monitor->response_time_threshold_ms);
        $defaultFrequencyMonitor = Monitor::query()->where('name', 'Default frequency')->sole();
        $this->assertSame((int) config('monitoring.interval_seconds'), $defaultFrequencyMonitor->interval_seconds);
        $this->assertSame((int) config('monitoring.request_timeout_seconds'), $defaultFrequencyMonitor->timeout_seconds);
        $this->assertNull($defaultFrequencyMonitor->response_time_threshold_ms);

        $run = app(RunScheduler::class)->enqueue($monitor, 'scheduled', now());
        $this->assertNotNull($run);
        $snapshot = json_decode($run->config_snapshot, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(300, $snapshot['interval_seconds']);
        $this->assertSame($condition, $snapshot['expected_response_header']);
        $this->assertSame(15, $snapshot['timeout_seconds']);
        $this->assertSame(14000, $snapshot['response_time_threshold_ms']);
        $this->assertSame(
            now()->addSeconds(300)->utc()->toIso8601String(),
            $monitor->fresh()->next_check_at->utc()->toIso8601String(),
        );
        Queue::assertPushed(RunMonitorCheck::class, 1);
    }

    public function test_csv_rejects_intervals_outside_the_supported_choices(): void
    {
        $csv = $this->csvContent(
            ['name', 'url', 'label', 'interval_seconds'],
            [
                ['Unsupported frequency', 'https://example.com/unsupported-frequency', 'Ops', '120'],
                ['Non-numeric frequency', 'https://example.com/non-numeric-frequency', 'Ops', 'every-five-minutes'],
            ],
        );

        $preview = $this->actingAs(User::factory()->create())
            ->post(route('monitors.import.preview'), [
                'csv_file' => UploadedFile::fake()->createWithContent('monitors.csv', $csv),
            ])
            ->assertOk()
            ->assertSee('檢查間隔只能使用 60、300、900、1800、3600 秒。');

        $this->assertSame(0, $preview->viewData('validCount'));
        $this->assertSame(2, $preview->viewData('invalidCount'));

        $this->post(route('monitors.import.commit'), ['preview_token' => $preview->viewData('previewToken')])
            ->assertRedirect(route('monitors.index'));

        $this->assertDatabaseCount('monitors', 0);
    }

    public function test_csv_rejects_invalid_timeouts_and_response_time_thresholds(): void
    {
        config(['monitoring.request_timeout_seconds' => 10]);
        $csv = $this->csvContent(
            ['name', 'url', 'label', 'timeout_seconds', 'response_time_threshold_ms'],
            [
                ['Invalid timeout text', 'https://example.com/invalid-timeout-text', 'Ops', 'slow', '2500'],
                ['Timeout below minimum', 'https://example.com/timeout-below-minimum', 'Ops', '0', '2500'],
                ['Timeout above maximum', 'https://example.com/timeout-above-maximum', 'Ops', '16', '2500'],
                ['Threshold not integer', 'https://example.com/threshold-not-integer', 'Ops', '10', 'fast'],
                ['Threshold below minimum', 'https://example.com/threshold-below-minimum', 'Ops', '10', '99'],
                ['Threshold above maximum', 'https://example.com/threshold-above-maximum', 'Ops', '15', '14001'],
                ['Threshold reaches timeout', 'https://example.com/threshold-reaches-timeout', 'Ops', '10', '10000'],
                ['Threshold reaches custom timeout', 'https://example.com/threshold-reaches-custom-timeout', 'Ops', '14', '14000'],
            ],
        );

        $preview = $this->actingAs(User::factory()->create())
            ->post(route('monitors.import.preview'), [
                'csv_file' => UploadedFile::fake()->createWithContent('monitors.csv', $csv),
            ])
            ->assertOk()
            ->assertSee('請求逾時秒數必須是整數。')
            ->assertSee('請求逾時秒數不得低於 1 秒。')
            ->assertSee('請求逾時秒數不得高於 15 秒。')
            ->assertSee('回應時間門檻必須是整數。')
            ->assertSee('回應時間門檻不得低於 100 毫秒。')
            ->assertSee('回應時間門檻不得高於 14000 毫秒。')
            ->assertSee('回應時間門檻必須低於請求逾時上限（10000 毫秒）。');

        $this->assertSame(0, $preview->viewData('validCount'));
        $this->assertSame(8, $preview->viewData('invalidCount'));

        $this->post(route('monitors.import.commit'), ['preview_token' => $preview->viewData('previewToken')])
            ->assertRedirect(route('monitors.index'));

        $this->assertDatabaseCount('monitors', 0);
    }

    public function test_csv_rejects_optional_columns_in_a_noncanonical_order(): void
    {
        $csv = $this->csvContent(
            ['name', 'url', 'label', 'interval_seconds', 'expected_text'],
            [],
        );

        $this->actingAs(User::factory()->create())
            ->from(route('monitors.import'))
            ->post(route('monitors.import.preview'), [
                'csv_file' => UploadedFile::fake()->createWithContent('monitors.csv', $csv),
            ])
            ->assertRedirect(route('monitors.import'))
            ->assertSessionHasErrors('csv_file');

        $this->assertDatabaseCount('monitors', 0);
    }

    public function test_csv_import_accepts_every_supported_interval_choice(): void
    {
        $intervals = [60, 300, 900, 1800, 3600];
        $rows = array_map(static fn (int $seconds): array => [
            'Frequency '.$seconds,
            'https://example.com/frequency-'.$seconds,
            'Ops',
            (string) $seconds,
        ], $intervals);
        $csv = $this->csvContent(
            ['name', 'url', 'label', 'interval_seconds'],
            $rows,
        );

        $preview = $this->actingAs(User::factory()->create())
            ->post(route('monitors.import.preview'), [
                'csv_file' => UploadedFile::fake()->createWithContent('monitors.csv', $csv),
            ])
            ->assertOk();

        $this->assertSame(5, $preview->viewData('validCount'));
        $this->assertSame($intervals, array_column($preview->viewData('previewRows'), 'interval_seconds'));

        $this->post(route('monitors.import.commit'), ['preview_token' => $preview->viewData('previewToken')])
            ->assertRedirect(route('monitors.index'));

        $savedIntervals = Monitor::query()
            ->orderBy('interval_seconds')
            ->get()
            ->map(static fn (Monitor $monitor): int => $monitor->interval_seconds)
            ->all();

        $this->assertSame($intervals, $savedIntervals);
    }

    public function test_csv_rejects_social_posts_without_expected_text(): void
    {
        $csv = $this->csvContent(
            ['name', 'url', 'label', 'expected_text'],
            [['Missing text', 'https://www.threads.com/@creator/post/ABC123', 'Campaign', '']],
        );

        $preview = $this->actingAs(User::factory()->create())
            ->post(route('monitors.import.preview'), [
                'csv_file' => UploadedFile::fake()->createWithContent('monitors.csv', $csv),
            ])
            ->assertOk()
            ->assertSee('社群貼文必須提供 expected_text 欄位中的預期文字。');

        $this->assertSame(0, $preview->viewData('validCount'));
        $this->assertSame(1, $preview->viewData('invalidCount'));
        $this->assertDatabaseCount('monitors', 0);
    }

    public function test_csv_rejects_absent_text_mode_for_social_posts(): void
    {
        $csv = $this->csvContent(
            ['name', 'url', 'label', 'expected_text', 'expected_text_mode'],
            [['Absent social text', 'https://www.facebook.com/story.php?story_fbid=123', 'Campaign', 'Expected phrase', 'absent']],
        );

        $preview = $this->actingAs(User::factory()->create())
            ->post(route('monitors.import.preview'), [
                'csv_file' => UploadedFile::fake()->createWithContent('monitors.csv', $csv),
            ])
            ->assertOk()
            ->assertSee('社群貼文的 expected_text_mode 必須是 present 或 present_any。');

        $this->assertSame(0, $preview->viewData('validCount'));
        $this->assertDatabaseCount('monitors', 0);
    }

    public function test_csv_can_import_absent_text_conditions_for_regular_web_pages(): void
    {
        $csv = $this->csvContent(
            ['name', 'url', 'label', 'expected_text', 'expected_text_mode'],
            [['Maintenance banner', 'https://example.com/status', 'Operations', 'Scheduled maintenance', 'absent']],
        );

        $preview = $this->actingAs(User::factory()->create())
            ->post(route('monitors.import.preview'), [
                'csv_file' => UploadedFile::fake()->createWithContent('monitors.csv', $csv),
            ])
            ->assertOk()
            ->assertSee('不得出現任一片段：Scheduled maintenance');

        $this->assertSame(1, $preview->viewData('validCount'));
        $this->post(route('monitors.import.commit'), ['preview_token' => $preview->viewData('previewToken')])
            ->assertRedirect(route('monitors.index'));

        $monitor = Monitor::query()->sole();
        $this->assertSame('Scheduled maintenance', $monitor->expected_text);
        $this->assertSame('absent', $monitor->expected_text_mode);
    }

    public function test_csv_rejects_invalid_text_modes_oversized_text_and_extra_cells(): void
    {
        $csv = $this->csvContent(
            ['name', 'url', 'label', 'expected_text', 'expected_text_mode'],
            [
                ['Invalid mode', 'https://example.com/invalid-mode', 'Ops', 'Expected', 'contains'],
                ['Long text', 'https://example.com/long-text', 'Ops', str_repeat('x', 501), 'present'],
                ['Extra cell', 'https://example.com/extra-cell', 'Ops', 'Expected', 'present', 'unexpected'],
            ],
        );

        $preview = $this->actingAs(User::factory()->create())
            ->post(route('monitors.import.preview'), [
                'csv_file' => UploadedFile::fake()->createWithContent('monitors.csv', $csv),
            ])
            ->assertOk()
            ->assertSee('預期文字比對方式只能是 present、present_any 或 absent。')
            ->assertSee('預期文字不得超過 500 個字元。')
            ->assertSee('資料欄位數量與標題列不一致。');

        $this->assertSame(0, $preview->viewData('validCount'));
        $this->assertSame(3, $preview->viewData('invalidCount'));
        $this->assertDatabaseCount('monitors', 0);
    }

    /** @param list<string> $headers
     * @param  list<list<string>>  $rows
     */
    private function csvContent(array $headers, array $rows): string
    {
        $stream = fopen('php://temp', 'r+');
        fputcsv($stream, $headers, ',', '"', '');

        foreach ($rows as $row) {
            fputcsv($stream, $row, ',', '"', '');
        }

        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return $csv === false ? '' : $csv;
    }
}
