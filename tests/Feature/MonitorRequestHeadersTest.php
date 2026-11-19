<?php

namespace Tests\Feature;

use App\Jobs\RunMonitorCheck;
use App\Models\Monitor;
use App\Models\User;
use App\Services\IncidentManager;
use App\Services\Probe\HeaderAwareProbeTransport;
use App\Services\Probe\HopResponse;
use App\Services\Probe\HostResolver;
use App\Services\Probe\ProbeClient;
use App\Services\Probe\ProbeResult;
use App\Services\Probe\RequestHeaderParser;
use App\Services\Probe\SecureProbeClient;
use App\Services\Probe\UrlSafetyPolicy;
use App\Services\RunScheduler;
use App\Services\UrlMasker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MonitorRequestHeadersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('monitoring.transport', 'fixture');
    }

    public function test_custom_request_headers_are_encrypted_hidden_and_snapshotted_for_checks(): void
    {
        Queue::fake();
        $headers = "Authorization: Bearer monitor-secret\nX-Region: ap-southeast-1";

        $response = $this->actingAs(User::factory()->create())
            ->post(route('monitors.store'), $this->monitorInput(['request_headers' => $headers]))
            ->assertRedirect();

        $monitor = Monitor::query()->sole();
        $rawHeaders = (string) DB::table('monitors')->where('id', $monitor->id)->value('request_headers');

        $this->assertSame([
            'Authorization' => 'Bearer monitor-secret',
            'X-Region' => 'ap-southeast-1',
        ], $monitor->request_headers);
        $this->assertStringNotContainsString('monitor-secret', $rawHeaders);
        $this->assertArrayNotHasKey('request_headers', $monitor->toArray());
        $this->get(route('monitors.edit', $monitor))
            ->assertOk()
            ->assertSee('目前已加密保存 2 個標頭')
            ->assertSee('Authorization')
            ->assertDontSee('monitor-secret');
        $this->get($response->headers->get('Location'))
            ->assertOk()
            ->assertSee('Authorization')
            ->assertDontSee('monitor-secret');

        $run = app(RunScheduler::class)->enqueue($monitor, 'manual');
        $this->assertNotNull($run);
        $rawSnapshot = (string) DB::table('check_runs')->where('id', $run->id)->value('config_snapshot');
        $this->assertStringNotContainsString('monitor-secret', $rawSnapshot);
        $this->assertSame($monitor->request_headers, json_decode($run->fresh()->config_snapshot, true, flags: JSON_THROW_ON_ERROR)['request_headers']);
        $this->assertArrayNotHasKey('config_snapshot', $run->fresh()->toArray());
    }

    public function test_edit_can_replace_retain_or_clear_custom_request_headers(): void
    {
        $user = User::factory()->create();
        $monitor = Monitor::factory()->create([
            'url' => 'https://fixture.test/ok',
            'url_hash' => Monitor::fingerprintUrl('https://fixture.test/ok'),
            'request_headers' => ['X-Original' => 'original-secret'],
        ]);

        $this->actingAs($user)
            ->put(route('monitors.update', $monitor), $this->monitorInput([
                'name' => $monitor->name,
                'url' => '',
                'request_headers' => '',
            ]))
            ->assertRedirect(route('monitors.show', $monitor));
        $this->assertSame(['X-Original' => 'original-secret'], $monitor->fresh()->request_headers);

        $this->put(route('monitors.update', $monitor), $this->monitorInput([
            'name' => $monitor->name,
            'url' => '',
            'request_headers' => 'X-New: replacement-secret',
        ]))->assertRedirect(route('monitors.show', $monitor));
        $this->assertSame(['X-New' => 'replacement-secret'], $monitor->fresh()->request_headers);

        $this->put(route('monitors.update', $monitor), $this->monitorInput([
            'name' => $monitor->name,
            'url' => '',
            'request_headers' => '',
            'clear_request_headers' => '1',
        ]))->assertRedirect(route('monitors.show', $monitor));
        $this->assertSame([], $monitor->fresh()->request_headers);
    }

    public function test_invalid_or_reserved_headers_are_rejected_without_flashing_secret_values(): void
    {
        $this->actingAs(User::factory()->create())
            ->from(route('monitors.create'))
            ->post(route('monitors.store'), $this->monitorInput([
                'request_headers' => "Host: override.example\nAuthorization: Bearer do-not-flash",
            ]))
            ->assertRedirect(route('monitors.create'))
            ->assertSessionHasErrors('request_headers')
            ->assertSessionMissing('_old_input.request_headers');

        $this->assertDatabaseCount('monitors', 0);
    }

    public function test_custom_request_headers_require_an_https_monitor_url(): void
    {
        $this->actingAs(User::factory()->create())
            ->from(route('monitors.create'))
            ->post(route('monitors.store'), $this->monitorInput([
                'url' => 'http://fixture.test/ok',
                'request_headers' => 'X-Token: secret-value',
            ]))
            ->assertRedirect(route('monitors.create'))
            ->assertSessionHasErrors('request_headers')
            ->assertSessionMissing('_old_input.request_headers');

        $this->assertDatabaseCount('monitors', 0);
    }

    public function test_header_parser_accepts_safe_headers_and_rejects_duplicate_or_reserved_names(): void
    {
        $this->assertSame([
            'X-Api-Key' => 'secret-value',
            'Accept-Language' => 'zh-TW',
        ], RequestHeaderParser::parse("X-Api-Key: secret-value\nAccept-Language: zh-TW"));

        $tooManyHeaders = implode("\n", array_map(static fn (int $index): string => 'X-Header-'.$index.': value', range(1, RequestHeaderParser::MAX_HEADERS + 1)));
        $tooLongHeader = 'X-Header: '.str_repeat('x', RequestHeaderParser::MAX_FIELD_LENGTH + 1);
        $tooLargeTotal = [];

        for ($index = 1; $index <= RequestHeaderParser::MAX_HEADERS; $index++) {
            $tooLargeTotal['X-Header-'.$index] = str_repeat('x', 500);
        }

        foreach ([
            "X-Test: a\nx-Test: b",
            "X-Test: a\nX-Test: b",
            'Host: unsafe.example',
            'User-Agent: custom-agent',
            'Bad Header: value',
            "X-Test: trailing-control\x00",
            $tooManyHeaders,
            $tooLongHeader,
        ] as $input) {
            try {
                RequestHeaderParser::parse($input);
                $this->fail('Invalid custom header input should be rejected.');
            } catch (\InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }

        try {
            RequestHeaderParser::normalize($tooLargeTotal);
            $this->fail('Oversized aggregate custom header values should be rejected.');
        } catch (\InvalidArgumentException) {
            $this->assertTrue(true);
        }
    }

    public function test_probe_preview_sends_custom_headers_and_does_not_keep_them_in_session_input(): void
    {
        $probe = $this->mock(ProbeClient::class);
        $probe->shouldReceive('inspect')
            ->once()
            ->with('https://fixture.test/ok', [], 'MarketingMonitor/1.0 (+authorized landing page check)', null, 10, 'present', ['Authorization' => 'Bearer preview-secret'])
            ->andReturn(new ProbeResult(200, 25, 'https://fixture.test/ok', [], ['203.0.113.10'], null, null, null, null));

        $this->actingAs(User::factory()->create())
            ->from(route('monitors.create'))
            ->post(route('monitors.probe-preview'), $this->monitorInput([
                'request_headers' => 'Authorization: Bearer preview-secret',
            ]))
            ->assertRedirect(route('monitors.create'))
            ->assertSessionMissing('_old_input.request_headers')
            ->assertSessionHas('probe_preview.state', 'passed');

        $this->get(route('monitors.create'))
            ->assertOk()
            ->assertDontSee('preview-secret');
        $this->assertDatabaseCount('monitors', 0);
    }

    public function test_scheduled_social_check_forwards_the_encrypted_headers_to_the_probe(): void
    {
        Queue::fake();
        $url = 'https://www.instagram.com/p/HEADER123/';
        $headers = ['Authorization' => 'Bearer scheduled-secret'];
        $monitor = Monitor::factory()->create([
            'url' => $url,
            'url_hash' => Monitor::fingerprintUrl($url),
            'expected_text' => 'Campaign headline',
            'request_headers' => $headers,
        ]);
        $run = app(RunScheduler::class)->enqueue($monitor, 'manual');
        $this->assertNotNull($run);
        $probe = $this->mock(ProbeClient::class);
        $probe->shouldReceive('inspect')
            ->once()
            ->with($url, [], 'MarketingMonitor/1.0 (+authorized landing page check)', 'Campaign headline', 10, 'present', $headers)
            ->andReturn(new ProbeResult(200, 20, $url, [], ['93.184.216.34'], null, null, true, null));

        (new RunMonitorCheck($run->id))->handle(
            $probe,
            app(IncidentManager::class),
            app(RunScheduler::class),
        );

        $this->assertDatabaseHas('check_runs', [
            'id' => $run->id,
            'status' => 'completed',
            'expected_text_found' => true,
        ]);
    }

    public function test_secure_probe_sends_custom_headers_only_to_the_original_origin(): void
    {
        $resolver = new class implements HostResolver
        {
            public function resolve(string $hostname): array
            {
                return ['93.184.216.34'];
            }
        };
        $transport = new class implements HeaderAwareProbeTransport
        {
            /** @var list<array<string, string>> */
            public array $headersByRequest = [];

            /** @var list<HopResponse> */
            public array $responses = [];

            public function get(array $target, string $userAgent, int $timeoutMs): HopResponse
            {
                $this->headersByRequest[] = [];

                return array_shift($this->responses);
            }

            public function getWithHeaders(array $target, string $userAgent, int $timeoutMs, array $requestHeaders, ?string $responseHeaderName = null): HopResponse
            {
                $this->headersByRequest[] = $requestHeaders;

                return array_shift($this->responses);
            }
        };
        $transport->responses = [
            new HopResponse(302, 'https://cdn.example.test/next', '', 10, null, null, null),
            new HopResponse(200, null, '<html>ok</html>', 10, null, null, null),
        ];
        $probe = new SecureProbeClient(new UrlSafetyPolicy($resolver), $transport, new UrlMasker);

        $result = $probe->inspect(
            'https://landing.example/start',
            ['cdn.example.test'],
            'MonitorTest/1.0',
            requestHeaders: ['Authorization' => 'Bearer private-token'],
        );

        $this->assertSame(200, $result->statusCode);
        $this->assertSame([['Authorization' => 'Bearer private-token'], []], $transport->headersByRequest);
    }

    public function test_custom_request_headers_are_rejected_over_plain_http(): void
    {
        $resolver = new class implements HostResolver
        {
            public function resolve(string $hostname): array
            {
                return ['93.184.216.34'];
            }
        };
        $transport = new class implements HeaderAwareProbeTransport
        {
            public function get(array $target, string $userAgent, int $timeoutMs): HopResponse
            {
                throw new \RuntimeException('The probe must reject HTTP before sending headers.');
            }

            public function getWithHeaders(array $target, string $userAgent, int $timeoutMs, array $requestHeaders, ?string $responseHeaderName = null): HopResponse
            {
                throw new \RuntimeException('The probe must reject HTTP before sending headers.');
            }
        };
        $probe = new SecureProbeClient(new UrlSafetyPolicy($resolver), $transport, new UrlMasker);

        $result = $probe->inspect(
            'http://landing.example/start',
            [],
            'MonitorTest/1.0',
            requestHeaders: ['Authorization' => 'Bearer private-token'],
        );

        $this->assertSame('request_header_requires_https', $result->errorType);
    }

    public function test_secure_probe_keeps_custom_headers_on_a_same_origin_redirect(): void
    {
        $resolver = new class implements HostResolver
        {
            public function resolve(string $hostname): array
            {
                return ['93.184.216.34'];
            }
        };
        $transport = new class implements HeaderAwareProbeTransport
        {
            /** @var list<array<string, string>> */
            public array $headersByRequest = [];

            /** @var list<HopResponse> */
            public array $responses = [];

            public function get(array $target, string $userAgent, int $timeoutMs): HopResponse
            {
                $this->headersByRequest[] = [];

                return array_shift($this->responses);
            }

            public function getWithHeaders(array $target, string $userAgent, int $timeoutMs, array $requestHeaders, ?string $responseHeaderName = null): HopResponse
            {
                $this->headersByRequest[] = $requestHeaders;

                return array_shift($this->responses);
            }
        };
        $transport->responses = [
            new HopResponse(302, 'https://landing.example/next', '', 10, null, null, null),
            new HopResponse(200, null, '<html>ok</html>', 10, null, null, null),
        ];
        $probe = new SecureProbeClient(new UrlSafetyPolicy($resolver), $transport, new UrlMasker);
        $headers = ['Authorization' => 'Bearer private-token'];

        $result = $probe->inspect('https://landing.example/start', [], 'MonitorTest/1.0', requestHeaders: $headers);

        $this->assertSame(200, $result->statusCode);
        $this->assertSame([$headers, $headers], $transport->headersByRequest);
    }

    /** @return array<string, string> */
    private function monitorInput(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Header check',
            'url' => 'https://fixture.test/ok',
            'label' => '',
            'expected_text' => '',
            'expected_text_mode' => 'present',
            'redirect_hosts' => '',
            'request_headers' => '',
        ], $overrides);
    }
}
