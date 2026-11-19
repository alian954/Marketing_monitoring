<?php

namespace Tests\Feature;

use App\Jobs\RunMonitorCheck;
use App\Jobs\SendNotificationOutbox;
use App\Models\CheckRun;
use App\Models\Incident;
use App\Models\IncidentEvent;
use App\Models\Monitor;
use App\Models\NotificationOutbox;
use App\Models\User;
use App\Services\IncidentManager;
use App\Services\NotificationDispatcher;
use App\Services\NotificationEmailRecipientParser;
use App\Services\Probe\HopResponse;
use App\Services\Probe\HostResolver;
use App\Services\Probe\ProbeClient;
use App\Services\Probe\ProbeResult;
use App\Services\Probe\ProbeTransport;
use App\Services\Probe\SecureProbeClient;
use App\Services\Probe\UrlSafetyPolicy;
use App\Services\RunScheduler;
use App\Services\UrlMasker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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
        $this->assertSame([], $previewRow['accepted_status_codes']);
        $this->assertDatabaseCount('monitors', 0);

        $this->post(route('monitors.import.commit'), ['preview_token' => $preview->viewData('previewToken')])
            ->assertRedirect(route('monitors.index'));

        $monitor = Monitor::query()->sole();
        $this->assertSame('Campaign, launch headline', $monitor->expected_text);
        $this->assertSame('present', $monitor->expected_text_mode);
        $this->assertSame([], $monitor->accepted_status_codes);
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

    public function test_imported_response_time_threshold_creates_an_incident_for_a_slow_successful_post(): void
    {
        Queue::fake([RunMonitorCheck::class]);
        $url = 'https://www.instagram.com/p/SLOW123/';
        $csv = $this->csvContent(
            ['name', 'url', 'label', 'expected_text', 'timeout_seconds', 'response_time_threshold_ms'],
            [['Slow campaign post', $url, 'Campaign', 'Campaign headline', '15', '1000']],
        );

        $preview = $this->actingAs(User::factory()->create())
            ->post(route('monitors.import.preview'), [
                'csv_file' => UploadedFile::fake()->createWithContent('monitors.csv', $csv),
            ])
            ->assertOk();

        $this->assertSame(1, $preview->viewData('validCount'));
        $this->post(route('monitors.import.commit'), ['preview_token' => $preview->viewData('previewToken')])
            ->assertRedirect(route('monitors.index'));

        $monitor = Monitor::query()->sole();
        $run = app(RunScheduler::class)->enqueue($monitor, 'manual');
        $this->assertNotNull($run);
        $probe = $this->mock(ProbeClient::class);
        $probe->shouldReceive('inspect')
            ->once()
            ->with($url, [], 'MarketingMonitor/1.0 (+authorized landing page check)', 'Campaign headline', 15, 'present')
            ->andReturn(new ProbeResult(200, 1001, $url, [], ['93.184.216.34'], null, null, true, null));

        (new RunMonitorCheck($run->id))->handle($probe, app(IncidentManager::class), app(RunScheduler::class));

        $this->assertDatabaseHas('check_runs', [
            'id' => $run->id,
            'status' => 'completed',
            'normal_elapsed_ms' => 1001,
            'expected_text_found' => 1,
        ]);
        $incident = Incident::query()
            ->where('monitor_id', $monitor->id)
            ->where('rule_key', 'response_time')
            ->sole();
        $this->assertSame('warning', $incident->severity);
        $this->assertSame(Incident::STATE_SUSPECT, $incident->state);
        $failureEvent = $incident->events()->where('event_type', 'failure_seen')->sole();
        $this->assertSame(1000, $failureEvent->evidence['threshold_ms']);
    }

    public function test_csv_can_import_accepted_http_status_codes_and_treat_429_as_healthy(): void
    {
        Queue::fake([RunMonitorCheck::class]);
        $url = 'https://www.instagram.com/p/ACCEPT429/';
        $csv = $this->csvContent(
            ['name', 'url', 'label', 'expected_text', 'accepted_status_codes'],
            [['Rate-limited post', $url, 'Campaign', 'Campaign headline', '429, 400 429 599']],
        );

        $preview = $this->actingAs(User::factory()->create())
            ->post(route('monitors.import.preview'), [
                'csv_file' => UploadedFile::fake()->createWithContent('monitors.csv', $csv),
            ])
            ->assertOk()
            ->assertSee('額外接受狀態碼')
            ->assertSee('400, 429, 599')
            ->assertSee('可匯入');

        $previewRow = $preview->viewData('previewRows')[0];
        $this->assertSame([400, 429, 599], $previewRow['accepted_status_codes']);
        $this->assertSame(1, $preview->viewData('validCount'));
        $this->post(route('monitors.import.commit'), ['preview_token' => $preview->viewData('previewToken')])
            ->assertRedirect(route('monitors.index'));

        $monitor = Monitor::query()->sole();
        $this->assertSame([400, 429, 599], $monitor->accepted_status_codes);

        $run = app(RunScheduler::class)->enqueue($monitor, 'manual');
        $this->assertNotNull($run);
        $snapshot = json_decode($run->config_snapshot, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame([400, 429, 599], $snapshot['accepted_status_codes']);

        $probe = $this->mock(ProbeClient::class);
        $probe->shouldReceive('inspect')
            ->once()
            ->with($url, [], 'MarketingMonitor/1.0 (+authorized landing page check)', 'Campaign headline', 10, 'present')
            ->andReturn(new ProbeResult(429, 300, $url, [], ['93.184.216.34'], null, null, true, null));

        (new RunMonitorCheck($run->id))->handle($probe, app(IncidentManager::class), app(RunScheduler::class));

        $completedRun = $run->fresh();
        $this->assertSame(429, $completedRun->normal_status);
        $this->assertTrue($completedRun->passesMonitorChecks());
        $this->assertDatabaseCount('incidents', 0);
    }

    public function test_csv_rejects_invalid_and_oversized_accepted_http_status_codes(): void
    {
        $csv = $this->csvContent(
            ['name', 'url', 'label', 'accepted_status_codes'],
            [
                ['Text class', 'https://example.com/status-text-class', 'Ops', '4xx'],
                ['Success status', 'https://example.com/status-success', 'Ops', '399'],
                ['Out of range', 'https://example.com/status-out-of-range', 'Ops', '600'],
                ['Too long', 'https://example.com/status-too-long', 'Ops', str_repeat('4', 1001)],
            ],
        );

        $preview = $this->actingAs(User::factory()->create())
            ->post(route('monitors.import.preview'), [
                'csv_file' => UploadedFile::fake()->createWithContent('monitors.csv', $csv),
            ])
            ->assertOk()
            ->assertSee('額外接受狀態碼只能是 400 至 599 的三位數 HTTP 狀態碼。')
            ->assertSee('額外接受狀態碼欄位過長。');

        $this->assertSame(0, $preview->viewData('validCount'));
        $this->assertSame(4, $preview->viewData('invalidCount'));

        $this->post(route('monitors.import.commit'), ['preview_token' => $preview->viewData('previewToken')])
            ->assertRedirect(route('monitors.index'));

        $this->assertDatabaseCount('monitors', 0);
    }

    public function test_csv_can_import_encrypted_request_headers_without_exposing_the_values(): void
    {
        Queue::fake([RunMonitorCheck::class]);
        $url = 'https://www.instagram.com/p/HEADER123/';
        $headers = "Authorization: Bearer import-secret\nX-Region: ap-southeast-1";
        $csv = $this->csvContent(
            ['name', 'url', 'label', 'expected_text', 'accepted_status_codes', 'request_headers'],
            [['Private campaign post', $url, 'Campaign', 'Campaign headline', '', $headers]],
        );

        $preview = $this->actingAs(User::factory()->create())
            ->post(route('monitors.import.preview'), [
                'csv_file' => UploadedFile::fake()->createWithContent('monitors.csv', $csv),
            ])
            ->assertOk()
            ->assertSee('自訂請求標頭')
            ->assertSee('Authorization')
            ->assertSee('X-Region')
            ->assertSee('值已遮蔽')
            ->assertDontSee('Bearer import-secret')
            ->assertDontSee('ap-southeast-1');

        $previewRow = $preview->viewData('previewRows')[0];
        $this->assertSame(['Authorization', 'X-Region'], $previewRow['request_header_names']);
        $this->assertArrayNotHasKey('request_headers', $previewRow);
        $this->assertSame(1, $preview->viewData('validCount'));

        $this->post(route('monitors.import.commit'), ['preview_token' => $preview->viewData('previewToken')])
            ->assertRedirect(route('monitors.index'));

        $monitor = Monitor::query()->sole();
        $expectedHeaders = [
            'Authorization' => 'Bearer import-secret',
            'X-Region' => 'ap-southeast-1',
        ];
        $this->assertSame($expectedHeaders, $monitor->request_headers);
        $rawHeaders = (string) DB::table('monitors')->where('id', $monitor->id)->value('request_headers');
        $this->assertStringNotContainsString('import-secret', $rawHeaders);

        $run = app(RunScheduler::class)->enqueue($monitor, 'manual');
        $this->assertNotNull($run);
        $snapshot = json_decode($run->config_snapshot, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($expectedHeaders, $snapshot['request_headers']);

        $probe = $this->mock(ProbeClient::class);
        $probe->shouldReceive('inspect')
            ->once()
            ->with($url, [], 'MarketingMonitor/1.0 (+authorized landing page check)', 'Campaign headline', 10, 'present', $expectedHeaders)
            ->andReturn(new ProbeResult(200, 200, $url, [], ['93.184.216.34'], null, null, true, null));

        (new RunMonitorCheck($run->id))->handle($probe, app(IncidentManager::class), app(RunScheduler::class));

        $this->assertTrue($run->fresh()->passesMonitorChecks());
        $this->assertDatabaseCount('incidents', 0);
    }

    public function test_csv_rejects_reserved_request_headers_and_http_urls_with_custom_headers(): void
    {
        $csv = $this->csvContent(
            ['name', 'url', 'label', 'request_headers'],
            [
                ['Reserved header', 'https://example.com/reserved-header', 'Ops', 'Host: unsafe.example'],
                ['Insecure URL', 'http://example.com/insecure-header', 'Ops', 'Authorization: Bearer do-not-show'],
            ],
        );

        $preview = $this->actingAs(User::factory()->create())
            ->post(route('monitors.import.preview'), [
                'csv_file' => UploadedFile::fake()->createWithContent('monitors.csv', $csv),
            ])
            ->assertOk()
            ->assertSee('此標頭由安全連線設定保留，不能自訂。')
            ->assertSee('自訂 HTTP 標頭可能含敏感值，監測網址必須使用 HTTPS。')
            ->assertDontSee('unsafe.example')
            ->assertDontSee('do-not-show');

        $this->assertSame(0, $preview->viewData('validCount'));
        $this->assertSame(2, $preview->viewData('invalidCount'));

        $this->post(route('monitors.import.commit'), ['preview_token' => $preview->viewData('previewToken')])
            ->assertRedirect(route('monitors.index'));

        $this->assertDatabaseCount('monitors', 0);
    }

    public function test_csv_can_import_redirect_hosts_and_follow_an_allowed_social_post_redirect(): void
    {
        Queue::fake([RunMonitorCheck::class]);
        $url = 'https://www.facebook.com/story.php?story_fbid=123';
        $csv = $this->csvContent(
            ['name', 'url', 'label', 'expected_text', 'allowed_redirect_hosts'],
            [['Shared campaign post', $url, 'Campaign', 'Campaign headline', " CDN.Example.com,cdn.example.com\nmedia.example.com "]],
        );

        $preview = $this->actingAs(User::factory()->create())
            ->post(route('monitors.import.preview'), [
                'csv_file' => UploadedFile::fake()->createWithContent('monitors.csv', $csv),
            ])
            ->assertOk()
            ->assertSee('允許的重新導向主機')
            ->assertSee('cdn.example.com')
            ->assertSee('media.example.com')
            ->assertSee('可匯入');

        $expectedHosts = ['cdn.example.com', 'media.example.com'];
        $this->assertSame($expectedHosts, $preview->viewData('previewRows')[0]['allowed_redirect_hosts']);
        $this->assertSame(1, $preview->viewData('validCount'));
        $this->post(route('monitors.import.commit'), ['preview_token' => $preview->viewData('previewToken')])
            ->assertRedirect(route('monitors.index'));

        $monitor = Monitor::query()->sole();
        $this->assertSame($expectedHosts, $monitor->allowed_redirect_hosts);

        $run = app(RunScheduler::class)->enqueue($monitor, 'manual');
        $this->assertNotNull($run);
        $snapshot = json_decode($run->config_snapshot, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($expectedHosts, $snapshot['allowed_redirect_hosts']);

        $transport = new class implements ProbeTransport
        {
            /** @var list<string> */
            public array $requestedHosts = [];

            public function get(array $target, string $userAgent, int $timeoutMs): HopResponse
            {
                $this->requestedHosts[] = $target['host'];

                if (count($this->requestedHosts) === 1) {
                    return new HopResponse(302, 'https://cdn.example.com/public/post', '', 20, null, null, null);
                }

                return new HopResponse(200, null, '<article>Campaign headline</article>', 25, null, null, null);
            }
        };
        $probe = new SecureProbeClient(app(UrlSafetyPolicy::class), $transport, new UrlMasker);

        (new RunMonitorCheck($run->id))->handle($probe, app(IncidentManager::class), app(RunScheduler::class));

        $completedRun = $run->fresh();
        $this->assertSame(['www.facebook.com', 'cdn.example.com'], $transport->requestedHosts);
        $this->assertSame(200, $completedRun->normal_status);
        $this->assertTrue($completedRun->passesMonitorChecks());
        $this->assertDatabaseCount('incidents', 0);
    }

    public function test_csv_rejects_invalid_or_oversized_redirect_host_lists(): void
    {
        $csv = $this->csvContent(
            ['name', 'url', 'label', 'allowed_redirect_hosts'],
            [
                ['Invalid host', 'https://example.com/invalid-redirect-host', 'Ops', 'localhost'],
                ['Long host list', 'https://example.com/long-redirect-host-list', 'Ops', str_repeat('a', 2001)],
            ],
        );

        $preview = $this->actingAs(User::factory()->create())
            ->post(route('monitors.import.preview'), [
                'csv_file' => UploadedFile::fake()->createWithContent('monitors.csv', $csv),
            ])
            ->assertOk()
            ->assertSee('請輸入完整的公開網域名稱。')
            ->assertSee('重新導向主機清單不得超過 2000 個字元。');

        $this->assertSame(0, $preview->viewData('validCount'));
        $this->assertSame(2, $preview->viewData('invalidCount'));

        $this->post(route('monitors.import.commit'), ['preview_token' => $preview->viewData('previewToken')])
            ->assertRedirect(route('monitors.index'));

        $this->assertDatabaseCount('monitors', 0);
    }

    public function test_csv_can_import_encrypted_notification_recipients_and_queue_alerts_for_them(): void
    {
        config(['monitoring.notify_email' => 'global@example.test']);
        Queue::fake([RunMonitorCheck::class, SendNotificationOutbox::class]);
        $url = 'https://www.threads.com/@creator/post/NOTIFY123';
        $notificationEmailInput = "alerts@example.test, team@example.test;\nALERTS@example.test";
        $csv = $this->csvContent(
            ['name', 'url', 'label', 'expected_text', 'notification_email'],
            [['Campaign post alerts', $url, 'Campaign', 'Campaign headline', $notificationEmailInput]],
        );

        $preview = $this->actingAs(User::factory()->create())
            ->post(route('monitors.import.preview'), [
                'csv_file' => UploadedFile::fake()->createWithContent('monitors.csv', $csv),
            ])
            ->assertOk()
            ->assertSee('告警信箱')
            ->assertSee('已設定 2 個')
            ->assertDontSee('alerts@example.test')
            ->assertDontSee('team@example.test');

        $previewRow = $preview->viewData('previewRows')[0];
        $this->assertSame(2, $previewRow['notification_email_count']);
        $this->assertArrayNotHasKey('notification_email', $previewRow);
        $this->assertSame(1, $preview->viewData('validCount'));
        $this->post(route('monitors.import.commit'), ['preview_token' => $preview->viewData('previewToken')])
            ->assertRedirect(route('monitors.index'));

        $monitor = Monitor::query()->sole();
        $normalizedRecipients = "alerts@example.test\nteam@example.test";
        $this->assertSame($normalizedRecipients, $monitor->notification_email);
        $encryptedMonitorRecipients = (string) DB::table('monitors')->where('id', $monitor->id)->value('notification_email');
        $this->assertStringNotContainsString('alerts@example.test', $encryptedMonitorRecipients);

        $run = CheckRun::factory()->for($monitor)->create();
        $incident = Incident::factory()->for($monitor)->create();
        app(NotificationDispatcher::class)->queue($incident, $monitor, $run, 'opened');

        $outboxes = NotificationOutbox::query()->where('channel', NotificationOutbox::CHANNEL_EMAIL)->get();
        $this->assertEqualsCanonicalizing(
            NotificationEmailRecipientParser::parse($normalizedRecipients),
            $outboxes->pluck('recipient')->all(),
        );
        $this->assertDatabaseMissing('notification_outbox', ['recipient' => 'global@example.test']);
        Queue::assertPushed(SendNotificationOutbox::class, 2);
    }

    public function test_csv_can_import_encrypted_escalation_recipients_and_queue_both_stages(): void
    {
        Queue::fake([SendNotificationOutbox::class]);
        $firstStageInput = 'backup@example.test, ONCALL@example.test; BACKUP@example.test';
        $secondStageInput = 'manager@example.test; vp@example.test';
        $csv = $this->csvContent(
            [
                'name',
                'url',
                'label',
                'expected_text',
                'notification_escalation_email',
                'notification_escalation_delay_minutes',
                'notification_escalation_stage_2_email',
                'notification_escalation_stage_2_delay_minutes',
            ],
            [[
                'Campaign escalation',
                'https://www.threads.com/@creator/post/ESCALATE123',
                'Campaign',
                'Launch announcement',
                $firstStageInput,
                '30',
                $secondStageInput,
                '60',
            ]],
        );

        $preview = $this->actingAs(User::factory()->create())
            ->post(route('monitors.import.preview'), [
                'csv_file' => UploadedFile::fake()->createWithContent('monitors.csv', $csv),
            ])
            ->assertOk()
            ->assertSee('升級通知')
            ->assertSee('第一階段：2 個／30 分鐘')
            ->assertSee('第二階段：2 個／60 分鐘')
            ->assertDontSee('backup@example.test')
            ->assertDontSee('ONCALL@example.test')
            ->assertDontSee('manager@example.test')
            ->assertDontSee('vp@example.test');

        $previewRow = $preview->viewData('previewRows')[0];
        $this->assertSame(2, $previewRow['notification_escalation_email_count']);
        $this->assertSame(30, $previewRow['notification_escalation_delay_minutes']);
        $this->assertSame(2, $previewRow['notification_escalation_stage_2_email_count']);
        $this->assertSame(60, $previewRow['notification_escalation_stage_2_delay_minutes']);
        $this->assertArrayNotHasKey('notification_escalation_email', $previewRow);
        $this->assertArrayNotHasKey('notification_escalation_stage_2_email', $previewRow);
        $encryptedPreview = (string) session('monitor_import_preview');
        $this->assertStringNotContainsString('backup@example.test', $encryptedPreview);
        $this->assertStringNotContainsString('manager@example.test', $encryptedPreview);
        $this->assertSame(1, $preview->viewData('validCount'));

        $this->post(route('monitors.import.commit'), ['preview_token' => $preview->viewData('previewToken')])
            ->assertRedirect(route('monitors.index'));

        $monitor = Monitor::query()->sole();
        $normalizedFirstStage = "backup@example.test\nONCALL@example.test";
        $this->assertSame($normalizedFirstStage, $monitor->notification_escalation_email);
        $this->assertSame(30, $monitor->notification_escalation_delay_minutes);
        $this->assertSame("manager@example.test\nvp@example.test", $monitor->notification_escalation_stage_2_email);
        $this->assertSame(60, $monitor->notification_escalation_stage_2_delay_minutes);
        $this->assertStringNotContainsString(
            'backup@example.test',
            (string) DB::table('monitors')->where('id', $monitor->id)->value('notification_escalation_email'),
        );
        $this->assertStringNotContainsString(
            'manager@example.test',
            (string) DB::table('monitors')->where('id', $monitor->id)->value('notification_escalation_stage_2_email'),
        );

        $openedAt = now()->subMinutes(61);
        $run = CheckRun::factory()->for($monitor)->create([
            'scheduled_at' => $openedAt,
            'finished_at' => $openedAt,
            'normal_status' => 503,
        ]);
        $incident = Incident::factory()->for($monitor)->create([
            'config_version' => $monitor->config_version,
            'opened_at' => $openedAt,
            'last_seen_at' => $openedAt,
        ]);
        IncidentEvent::factory()->for($incident)->for($run)->create([
            'event_type' => 'failure_seen',
            'created_at' => $openedAt,
        ]);

        $this->assertSame(2, app(IncidentManager::class)->queueDueEscalations());
        $firstStageOutbox = NotificationOutbox::query()
            ->where('event_type', NotificationOutbox::EVENT_TYPE_ESCALATION)
            ->get();
        $secondStageOutbox = NotificationOutbox::query()
            ->where('event_type', NotificationOutbox::EVENT_TYPE_ESCALATION_STAGE_2)
            ->get();

        $this->assertEqualsCanonicalizing(
            ['backup@example.test', 'ONCALL@example.test'],
            $firstStageOutbox->pluck('recipient')->all(),
        );
        $this->assertEqualsCanonicalizing(
            ['manager@example.test', 'vp@example.test'],
            $secondStageOutbox->pluck('recipient')->all(),
        );
        Queue::assertPushed(SendNotificationOutbox::class, 4);
    }

    public function test_csv_rejects_invalid_escalation_recipients_and_stage_delays(): void
    {
        $tooManyRecipients = implode(', ', array_map(
            static fn (int $index): string => 'escalation'.$index.'@example.test',
            range(1, 11),
        ));
        $headers = [
            'name',
            'url',
            'label',
            'expected_text',
            'notification_escalation_email',
            'notification_escalation_delay_minutes',
            'notification_escalation_stage_2_email',
            'notification_escalation_stage_2_delay_minutes',
        ];
        $rows = [
            ['Missing delay', 'https://www.threads.com/@creator/post/ESCALATE001', 'Social', 'headline', 'backup@example.test', '', '', ''],
            ['Invalid email', 'https://www.threads.com/@creator/post/ESCALATE002', 'Social', 'headline', 'not-an-email', '30', '', ''],
            ['Too many emails', 'https://www.threads.com/@creator/post/ESCALATE003', 'Social', 'headline', $tooManyRecipients, '30', '', ''],
            ['Stage two without one', 'https://www.threads.com/@creator/post/ESCALATE004', 'Social', 'headline', '', '', 'manager@example.test', '60'],
            ['Missing stage two delay', 'https://www.threads.com/@creator/post/ESCALATE005', 'Social', 'headline', 'backup@example.test', '30', 'manager@example.test', ''],
            ['Stage two too early', 'https://www.threads.com/@creator/post/ESCALATE006', 'Social', 'headline', 'backup@example.test', '30', 'manager@example.test', '30'],
            ['Non-integer delay', 'https://www.threads.com/@creator/post/ESCALATE007', 'Social', 'headline', 'backup@example.test', 'later', '', ''],
            ['Delay too short', 'https://www.threads.com/@creator/post/ESCALATE008', 'Social', 'headline', 'backup@example.test', '4', '', ''],
            ['Delay too long', 'https://www.threads.com/@creator/post/ESCALATE009', 'Social', 'headline', 'backup@example.test', '1441', '', ''],
        ];
        $csv = $this->csvContent($headers, $rows);

        $preview = $this->actingAs(User::factory()->create())
            ->post(route('monitors.import.preview'), [
                'csv_file' => UploadedFile::fake()->createWithContent('monitors.csv', $csv),
            ])
            ->assertOk()
            ->assertSee('設定升級告警收件信箱時，請同時設定等待時間。')
            ->assertSee('每個告警收件信箱都必須是有效電子郵件地址，且單一地址不得超過 254 個字元。')
            ->assertSee('最多可設定 10 個告警收件信箱。')
            ->assertSee('設定第二階段時，請先設定第一階段升級收件人。')
            ->assertSee('設定第二階段升級收件人時，請同時設定等待時間。')
            ->assertSee('第二階段等待時間必須晚於第一階段。')
            ->assertSee('升級等待時間必須是整數分鐘。')
            ->assertSee('升級等待時間必須介於 5 至 1440 分鐘。')
            ->assertDontSee('not-an-email')
            ->assertDontSee('manager@example.test');

        $this->assertSame(0, $preview->viewData('validCount'));
        $this->assertSame(count($rows), $preview->viewData('invalidCount'));

        $this->post(route('monitors.import.commit'), ['preview_token' => $preview->viewData('previewToken')])
            ->assertRedirect(route('monitors.index'));

        $this->assertDatabaseCount('monitors', 0);
    }

    public function test_csv_can_import_encrypted_slack_and_discord_webhooks_and_route_alerts(): void
    {
        config([
            'monitoring.notify_email' => null,
            'monitoring.notify_slack_webhook' => 'https://hooks.slack.com/services/T123/GLOBAL/SECRET',
            'monitoring.notify_discord_webhook' => 'https://discord.com/api/webhooks/456/GLOBALTOKEN',
        ]);
        Queue::fake([SendNotificationOutbox::class]);
        $slackWebhookUrl = 'https://hooks.slack-gov.com/services/T456/MONITOR/SLACKSECRET';
        $discordWebhookUrl = 'https://discord.com/api/webhooks/123/MONITORDISCORDSECRET';
        $csv = $this->csvContent(
            ['name', 'url', 'label', 'expected_text', 'notification_slack_webhook', 'notification_discord_webhook'],
            [[
                'Campaign webhook alerts',
                'https://www.threads.com/@creator/post/WEBHOOK123',
                'Campaign',
                'Launch announcement',
                $slackWebhookUrl,
                $discordWebhookUrl,
            ]],
        );

        $preview = $this->actingAs(User::factory()->create())
            ->post(route('monitors.import.preview'), [
                'csv_file' => UploadedFile::fake()->createWithContent('monitors.csv', $csv),
            ])
            ->assertOk()
            ->assertSee('Slack、Discord 已設定（網址已遮蔽）')
            ->assertDontSee($slackWebhookUrl)
            ->assertDontSee($discordWebhookUrl)
            ->assertDontSee('SLACKSECRET')
            ->assertDontSee('MONITORDISCORDSECRET');

        $previewRow = $preview->viewData('previewRows')[0];
        $this->assertTrue($previewRow['notification_slack_webhook_configured']);
        $this->assertTrue($previewRow['notification_discord_webhook_configured']);
        $this->assertArrayNotHasKey('notification_slack_webhook', $previewRow);
        $this->assertArrayNotHasKey('notification_discord_webhook', $previewRow);
        $encryptedPreview = (string) session('monitor_import_preview');
        $this->assertStringNotContainsString('SLACKSECRET', $encryptedPreview);
        $this->assertStringNotContainsString('MONITORDISCORDSECRET', $encryptedPreview);
        $this->assertSame(1, $preview->viewData('validCount'));

        $this->post(route('monitors.import.commit'), ['preview_token' => $preview->viewData('previewToken')])
            ->assertRedirect(route('monitors.index'));

        $monitor = Monitor::query()->sole();
        $this->assertSame($slackWebhookUrl, $monitor->notification_slack_webhook);
        $this->assertSame($discordWebhookUrl, $monitor->notification_discord_webhook);
        $this->assertNotSame(
            $slackWebhookUrl,
            DB::table('monitors')->where('id', $monitor->id)->value('notification_slack_webhook'),
        );
        $this->assertNotSame(
            $discordWebhookUrl,
            DB::table('monitors')->where('id', $monitor->id)->value('notification_discord_webhook'),
        );
        $this->assertArrayNotHasKey('notification_slack_webhook', $monitor->toArray());
        $this->assertArrayNotHasKey('notification_discord_webhook', $monitor->toArray());

        $run = CheckRun::factory()->for($monitor)->create();
        $incident = Incident::factory()->for($monitor)->create();
        app(NotificationDispatcher::class)->queue($incident, $monitor, $run, 'opened');

        $outboxes = NotificationOutbox::query()->get();
        $this->assertCount(2, $outboxes);
        $this->assertSame($slackWebhookUrl, $outboxes->where('channel', NotificationOutbox::CHANNEL_SLACK)->sole()->recipient);
        $this->assertSame($discordWebhookUrl, $outboxes->where('channel', NotificationOutbox::CHANNEL_DISCORD)->sole()->recipient);
        $this->assertNotSame($slackWebhookUrl, DB::table('notification_outbox')->where('channel', NotificationOutbox::CHANNEL_SLACK)->value('recipient'));
        $this->assertNotSame($discordWebhookUrl, DB::table('notification_outbox')->where('channel', NotificationOutbox::CHANNEL_DISCORD)->value('recipient'));

        Http::fake([
            'https://hooks.slack-gov.com/*' => Http::response('', 200),
            'https://discord.com/api/webhooks/*' => Http::response('', 204),
        ]);

        foreach ($outboxes as $outbox) {
            (new SendNotificationOutbox((int) $outbox->id))->handle();
        }

        Http::assertSent(static fn (ClientRequest $request): bool => $request->url() === $slackWebhookUrl);
        Http::assertSent(static fn (ClientRequest $request): bool => $request->url() === $discordWebhookUrl.'?wait=true');
        $this->assertSame(2, NotificationOutbox::query()->where('status', 'sent')->count());
        Queue::assertPushed(SendNotificationOutbox::class, 2);
    }

    public function test_csv_rejects_unsafe_or_oversized_webhook_urls_without_exposing_them(): void
    {
        $csv = $this->csvContent(
            ['name', 'url', 'label', 'expected_text', 'notification_slack_webhook', 'notification_discord_webhook'],
            [
                [
                    'Unsafe Slack destination',
                    'https://www.threads.com/@creator/post/WEBHOOK001',
                    'Campaign',
                    'headline',
                    'https://example.com/hooks/SLACKPRIVATE',
                    '',
                ],
                [
                    'Unsafe Discord destination',
                    'https://www.threads.com/@creator/post/WEBHOOK002',
                    'Campaign',
                    'headline',
                    '',
                    'https://discord.com.attacker.test/api/webhooks/123/DISCORDPRIVATE',
                ],
                [
                    'Oversized Slack destination',
                    'https://www.threads.com/@creator/post/WEBHOOK003',
                    'Campaign',
                    'headline',
                    str_repeat('x', 2049),
                    '',
                ],
            ],
        );

        $preview = $this->actingAs(User::factory()->create())
            ->post(route('monitors.import.preview'), [
                'csv_file' => UploadedFile::fake()->createWithContent('monitors.csv', $csv),
            ])
            ->assertOk()
            ->assertSee('請使用 hooks.slack.com 或 hooks.slack-gov.com 的 HTTPS Incoming Webhook 網址。')
            ->assertSee('請使用 discord.com 的 Discord Incoming Webhook HTTPS 網址。')
            ->assertSee('Slack webhook 網址不得超過 2048 個字元。')
            ->assertDontSee('SLACKPRIVATE')
            ->assertDontSee('DISCORDPRIVATE');

        $this->assertSame(0, $preview->viewData('validCount'));
        $this->assertSame(3, $preview->viewData('invalidCount'));

        $this->post(route('monitors.import.commit'), ['preview_token' => $preview->viewData('previewToken')])
            ->assertRedirect(route('monitors.index'));

        $this->assertDatabaseCount('monitors', 0);
    }

    public function test_csv_rejects_invalid_oversized_and_excessive_notification_recipients(): void
    {
        $tooManyRecipients = implode(', ', array_map(
            static fn (int $index): string => 'recipient'.$index.'@example.test',
            range(1, 11),
        ));
        $csv = $this->csvContent(
            ['name', 'url', 'label', 'notification_email'],
            [
                ['Invalid email', 'https://example.com/invalid-email', 'Ops', 'not-an-email'],
                ['Too many emails', 'https://example.com/too-many-emails', 'Ops', $tooManyRecipients],
                ['Email list too long', 'https://example.com/email-list-too-long', 'Ops', str_repeat('x', 2550)],
            ],
        );

        $preview = $this->actingAs(User::factory()->create())
            ->post(route('monitors.import.preview'), [
                'csv_file' => UploadedFile::fake()->createWithContent('monitors.csv', $csv),
            ])
            ->assertOk()
            ->assertSee('每個告警收件信箱都必須是有效電子郵件地址，且單一地址不得超過 254 個字元。')
            ->assertSee('最多可設定 10 個告警收件信箱。')
            ->assertSee('告警收件信箱清單過長。')
            ->assertDontSee('not-an-email')
            ->assertDontSee('recipient1@example.test');

        $this->assertSame(0, $preview->viewData('validCount'));
        $this->assertSame(3, $preview->viewData('invalidCount'));

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
