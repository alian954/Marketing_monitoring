<?php

namespace Tests\Feature;

use App\Jobs\RunMonitorCheck;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\User;
use App\Services\IncidentManager;
use App\Services\Probe\HeaderAwareProbeTransport;
use App\Services\Probe\HopResponse;
use App\Services\Probe\HostResolver;
use App\Services\Probe\ProbeClient;
use App\Services\Probe\ResponseHeaderCondition;
use App\Services\Probe\SecureProbeClient;
use App\Services\Probe\UrlSafetyPolicy;
use App\Services\RunScheduler;
use App\Services\UrlMasker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Tests\TestCase;

class MonitorResponseHeaderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('monitoring.transport', 'fixture');
    }

    public function test_condition_parser_checks_header_presence_and_case_insensitive_substrings(): void
    {
        $this->assertNull(ResponseHeaderCondition::matches([], null));
        $this->assertTrue(ResponseHeaderCondition::matches(['x-robots-tag' => ['noindex, follow']], 'X-Robots-Tag:'));
        $this->assertTrue(ResponseHeaderCondition::matches(['x-robots-tag' => ['index', 'noindex, follow']], 'X-Robots-Tag: NOINDEX'));
        $this->assertFalse(ResponseHeaderCondition::matches(['content-type' => ['text/html']], 'X-Robots-Tag: index'));
        $this->assertSame('X-Robots-Tag: noindex', ResponseHeaderCondition::normalizedCondition(' X-Robots-Tag :  noindex  '));
    }

    public function test_condition_parser_rejects_malformed_multiline_and_oversized_input(): void
    {
        foreach (['no-colon', "X-Test: one\nX-Other: two", "X-Test: value\0", "X-Test: \xFF", ': empty name', str_repeat('X', 129).': value', 'X-Test: '.str_repeat('v', 1025)] as $condition) {
            try {
                ResponseHeaderCondition::parse($condition);
                $this->fail('Invalid response header condition was accepted.');
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_secure_probe_checks_only_the_final_redirect_response_header(): void
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
            /** @var list<?string> */
            public array $selectedHeaderNames = [];

            /** @var list<HopResponse> */
            public array $responses = [];

            public function get(array $target, string $userAgent, int $timeoutMs): HopResponse
            {
                throw new \RuntimeException('Response header checks must use the header-aware transport.');
            }

            public function getWithHeaders(array $target, string $userAgent, int $timeoutMs, array $requestHeaders, ?string $responseHeaderName = null): HopResponse
            {
                $this->selectedHeaderNames[] = $responseHeaderName;

                return array_shift($this->responses);
            }
        };
        $transport->responses = [
            new HopResponse(302, 'https://landing.example/final', '', 10, null, null, null, null, ['x-robots-tag' => ['noindex']]),
            new HopResponse(200, null, '<html>ok</html>', 10, null, null, null, null, ['x-robots-tag' => ['index, follow']]),
        ];
        $probe = new SecureProbeClient(new UrlSafetyPolicy($resolver), $transport, new UrlMasker);

        $result = $probe->inspect('https://landing.example/start', [], 'MonitorTest/1.0', expectedResponseHeader: 'X-Robots-Tag: noindex');

        $this->assertSame(200, $result->statusCode);
        $this->assertFalse($result->expectedResponseHeaderFound);
        $this->assertSame(['X-Robots-Tag', 'X-Robots-Tag'], $transport->selectedHeaderNames);
    }

    public function test_create_edit_and_preview_keep_condition_values_encrypted_and_out_of_session(): void
    {
        $user = User::factory()->create();
        $condition = 'X-Robots-Tag: campaign-private-noindex';

        $response = $this->actingAs($user)
            ->post(route('monitors.store'), $this->monitorInput(['expected_response_header' => $condition]))
            ->assertRedirect();

        $monitor = Monitor::query()->sole();
        $rawCondition = (string) DB::table('monitors')->where('id', $monitor->id)->value('expected_response_header');

        $this->assertSame($condition, $monitor->expected_response_header);
        $this->assertStringNotContainsString('campaign-private-noindex', $rawCondition);
        $this->assertArrayNotHasKey('expected_response_header', $monitor->toArray());
        $this->get(route('monitors.edit', $monitor))
            ->assertOk()
            ->assertSee('X-Robots-Tag')
            ->assertDontSee('campaign-private-noindex');
        $this->get($response->headers->get('Location'))
            ->assertOk()
            ->assertSee('X-Robots-Tag')
            ->assertDontSee('campaign-private-noindex');

        $this->put(route('monitors.update', $monitor), $this->monitorInput([
            'name' => $monitor->name,
            'url' => '',
            'expected_response_header' => '',
        ]))->assertRedirect(route('monitors.show', $monitor));
        $this->assertSame($condition, $monitor->fresh()->expected_response_header);

        $this->put(route('monitors.update', $monitor), $this->monitorInput([
            'name' => $monitor->name,
            'url' => '',
            'expected_response_header' => 'X-Robots-Tag: noindex',
        ]))->assertRedirect(route('monitors.show', $monitor));
        $this->assertSame('X-Robots-Tag: noindex', $monitor->fresh()->expected_response_header);

        $this->put(route('monitors.update', $monitor), $this->monitorInput([
            'name' => $monitor->name,
            'url' => '',
            'expected_response_header' => '',
            'clear_expected_response_header' => '1',
        ]))->assertRedirect(route('monitors.show', $monitor));
        $this->assertNull($monitor->fresh()->expected_response_header);

        $this->from(route('monitors.create'))
            ->post(route('monitors.probe-preview'), $this->monitorInput(['expected_response_header' => 'X-Robots-Tag: index']))
            ->assertRedirect(route('monitors.create'))
            ->assertSessionHas('probe_preview.state', 'passed')
            ->assertSessionHas('probe_preview.expected_response_header_found', true)
            ->assertSessionMissing('_old_input.expected_response_header');

        $this->from(route('monitors.create'))
            ->post(route('monitors.probe-preview'), $this->monitorInput(['expected_response_header' => 'X-Robots-Tag: noindex']))
            ->assertRedirect(route('monitors.create'))
            ->assertSessionHas('probe_preview.state', 'failed')
            ->assertSessionHas('probe_preview.expected_response_header_found', false)
            ->assertSessionMissing('_old_input.expected_response_header');
    }

    public function test_invalid_condition_is_rejected_without_flashing_its_value(): void
    {
        $this->actingAs(User::factory()->create())
            ->from(route('monitors.create'))
            ->post(route('monitors.store'), $this->monitorInput(['expected_response_header' => 'private-value-without-colon']))
            ->assertRedirect(route('monitors.create'))
            ->assertSessionHasErrors('expected_response_header')
            ->assertSessionMissing('_old_input.expected_response_header');

        $this->assertDatabaseCount('monitors', 0);
    }

    public function test_scheduled_check_persists_boolean_result_and_opens_a_redacted_header_incident(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $monitor = Monitor::factory()->create([
            'url' => 'https://fixture.test/ok',
            'url_hash' => Monitor::fingerprintUrl('https://fixture.test/ok'),
            'expected_response_header' => 'X-Robots-Tag: private-expected-value',
        ]);

        $run = app(RunScheduler::class)->enqueue($monitor, 'scheduled');
        $this->assertNotNull($run);
        $rawSnapshot = (string) DB::table('check_runs')->where('id', $run->id)->value('config_snapshot');
        $this->assertStringNotContainsString('private-expected-value', $rawSnapshot);

        (new RunMonitorCheck($run->id))->handle(
            app(ProbeClient::class),
            app(IncidentManager::class),
            app(RunScheduler::class),
        );

        $completedRun = $run->fresh();
        $this->assertFalse($completedRun->expected_response_header_found);
        $this->assertFalse($completedRun->passesMonitorChecks());
        $incident = Incident::query()->where('rule_key', 'response_header')->sole();
        $evidence = $incident->events()->firstOrFail()->evidence;
        $this->assertSame('X-Robots-Tag', $evidence['expected_response_header_name']);
        $this->assertFalse($evidence['expected_response_header_found']);
        $this->assertStringNotContainsString('private-expected-value', json_encode($evidence, JSON_THROW_ON_ERROR));

        $this->actingAs($user)
            ->get(route('monitors.show', $monitor))
            ->assertOk()
            ->assertSee('回應標頭比對結果')
            ->assertSee('0.00%')
            ->assertDontSee('private-expected-value');

        $content = $this->actingAs($user)
            ->get(route('monitors.checks.export', $monitor))
            ->assertOk()
            ->streamedContent();
        $lines = preg_split('/\r\n|\n|\r/', trim($content));
        $headers = str_getcsv(array_shift($lines));
        $row = str_getcsv(array_shift($lines));

        $this->assertSame('0', $row[array_flip($headers)['expected_response_header_found']]);
    }

    /** @return array<string, string> */
    private function monitorInput(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Response header check',
            'url' => 'https://fixture.test/ok',
            'label' => '',
            'expected_text' => '',
            'expected_text_mode' => 'present',
            'redirect_hosts' => '',
            'request_headers' => '',
        ], $overrides);
    }
}
