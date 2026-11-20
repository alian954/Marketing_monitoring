<?php

namespace Tests\Feature;

use App\Jobs\RunMonitorCheck;
use App\Models\CheckRun;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\User;
use App\Services\IncidentManager;
use App\Services\Probe\ProbeResult;
use App\Services\RunScheduler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MonitorResponseBodySizeThresholdTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['monitoring.transport' => 'fixture']);
    }

    public function test_create_and_update_save_thresholds_and_snapshot_each_configuration_version(): void
    {
        Queue::fake([RunMonitorCheck::class]);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('monitors.store'), $this->monitorInput([
                'min_response_body_bytes' => '512',
                'max_response_body_bytes' => '2048',
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $monitor = Monitor::query()->sole();
        $this->assertSame(512, $monitor->min_response_body_bytes);
        $this->assertSame(2048, $monitor->max_response_body_bytes);
        $this->get(route('monitors.edit', $monitor))
            ->assertOk()
            ->assertSee('最小回應本文大小（bytes）')
            ->assertSee('最大回應本文大小（bytes）');
        $this->get(route('monitors.show', $monitor))
            ->assertOk()
            ->assertSee('512–2,048 bytes');

        $firstRun = app(RunScheduler::class)->enqueue($monitor, 'manual');
        $this->assertNotNull($firstRun);
        $firstSnapshot = json_decode($firstRun->config_snapshot, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(512, $firstSnapshot['min_response_body_bytes']);
        $this->assertSame(2048, $firstSnapshot['max_response_body_bytes']);

        $this->put(route('monitors.update', $monitor), $this->monitorInput([
            'name' => $monitor->name,
            'url' => '',
            'min_response_body_bytes' => '768',
            'max_response_body_bytes' => '2500',
        ]))->assertRedirect(route('monitors.show', $monitor));

        $monitor->refresh();
        $this->assertSame(2, $monitor->config_version);
        $this->assertSame('stale_config', $firstRun->fresh()->status);

        $secondRun = app(RunScheduler::class)->enqueue($monitor, 'manual');
        $this->assertNotNull($secondRun);
        $secondSnapshot = json_decode($secondRun->config_snapshot, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(768, $secondSnapshot['min_response_body_bytes']);
        $this->assertSame(2500, $secondSnapshot['max_response_body_bytes']);
        Queue::assertPushed(RunMonitorCheck::class, 2);
    }

    public function test_size_thresholds_reject_invalid_ranges_and_values_above_the_probe_limit(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(route('monitors.create'))
            ->post(route('monitors.store'), $this->monitorInput([
                'min_response_body_bytes' => '2048',
                'max_response_body_bytes' => '1024',
            ]))
            ->assertRedirect(route('monitors.create'))
            ->assertSessionHasErrors('max_response_body_bytes');

        $this->actingAs($user)
            ->from(route('monitors.create'))
            ->post(route('monitors.store'), $this->monitorInput([
                'max_response_body_bytes' => (string) ((int) config('monitoring.max_body_bytes') + 1),
            ]))
            ->assertRedirect(route('monitors.create'))
            ->assertSessionHasErrors('max_response_body_bytes');

        $this->assertDatabaseCount('monitors', 0);
    }

    public function test_check_runs_require_inclusive_size_bounds_and_mark_unreadable_bodies_indeterminate(): void
    {
        $monitor = Monitor::factory()->create();
        $cases = [
            ['bytes' => 100, 'passes' => true],
            ['bytes' => 200, 'passes' => true],
            ['bytes' => 99, 'passes' => false],
            ['bytes' => 201, 'passes' => false],
        ];

        foreach ($cases as $case) {
            $run = CheckRun::factory()->for($monitor)->create([
                'config_snapshot' => $this->configSnapshot(['min_response_body_bytes' => 100, 'max_response_body_bytes' => 200]),
                'response_body_bytes' => $case['bytes'],
            ]);

            $this->assertSame($case['passes'], $run->passesMonitorChecks());
        }

        $incompleteRun = CheckRun::factory()->for($monitor)->create([
            'config_snapshot' => $this->configSnapshot(['min_response_body_bytes' => null, 'max_response_body_bytes' => 200]),
            'response_body_bytes' => null,
        ]);

        $this->assertTrue($incompleteRun->hasIndeterminateResponseSizeCheck());
        $this->assertFalse($incompleteRun->passesMonitorChecks());

        $unconfiguredRun = CheckRun::factory()->for($monitor)->create([
            'config_snapshot' => $this->configSnapshot(),
            'response_body_bytes' => null,
        ]);

        $this->assertFalse($unconfiguredRun->hasIndeterminateResponseSizeCheck());
        $this->assertTrue($unconfiguredRun->passesMonitorChecks());
    }

    public function test_size_violations_and_indeterminate_responses_record_warning_incidents(): void
    {
        $monitor = Monitor::factory()->create([
            'max_response_body_bytes' => 1024,
            'confirmation_delay_seconds' => 60,
        ]);
        $run = CheckRun::factory()->for($monitor)->create([
            'config_snapshot' => $this->configSnapshot(['max_response_body_bytes' => 1024]),
            'response_body_bytes' => 2048,
            'normal_status' => 200,
        ]);
        $probe = $this->probeResult(2048);

        $delay = app(IncidentManager::class)->record($monitor, $run, $probe, null);
        $incident = Incident::query()->where('rule_key', 'response_size')->sole();
        $evidence = $incident->events()->where('event_type', 'failure_seen')->sole()->evidence;

        $this->assertSame(60, $delay);
        $this->assertSame(Incident::STATE_SUSPECT, $incident->state);
        $this->assertSame(2048, $evidence['response_body_bytes']);
        $this->assertSame(1024, $evidence['max_response_body_bytes']);
        $this->assertFalse($run->fresh()->passesMonitorChecks());
        $this->actingAs(User::factory()->create())
            ->get(route('monitors.show', $monitor))
            ->assertOk()
            ->assertSee('HTTP 回應本文大小超出設定範圍或無法判定')
            ->assertSee('2,048 bytes')
            ->assertSee('至多 1,024 bytes');

        $unknownMonitor = Monitor::factory()->create([
            'url' => 'https://example.com/unknown-size',
            'url_hash' => Monitor::fingerprintUrl('https://example.com/unknown-size'),
            'max_response_body_bytes' => 1024,
            'confirmation_delay_seconds' => 60,
        ]);
        $unknownRun = CheckRun::factory()->for($unknownMonitor)->create([
            'config_snapshot' => $this->configSnapshot(['max_response_body_bytes' => 1024]),
            'response_body_bytes' => null,
            'normal_status' => 200,
        ]);

        app(IncidentManager::class)->record($unknownMonitor, $unknownRun, $this->probeResult(null), null);
        $unknownIncident = Incident::query()->where('monitor_id', $unknownMonitor->id)->where('rule_key', 'response_size')->sole();
        $unknownEvidence = $unknownIncident->events()->where('event_type', 'failure_seen')->sole()->evidence;

        $this->assertTrue($unknownEvidence['indeterminate']);
        $this->assertNull($unknownEvidence['response_body_bytes']);
        $this->assertTrue($unknownRun->fresh()->hasIndeterminateResponseSizeCheck());
    }

    /** @param array<string, mixed> $overrides */
    private function configSnapshot(array $overrides = []): string
    {
        return json_encode(array_merge([
            'accepted_status_codes' => [],
            'expected_text' => null,
            'expected_text_mode' => 'present',
            'expected_response_header' => null,
            'min_response_body_bytes' => null,
            'max_response_body_bytes' => null,
        ], $overrides), JSON_THROW_ON_ERROR);
    }

    private function probeResult(?int $responseBodyBytes): ProbeResult
    {
        return new ProbeResult(
            statusCode: 200,
            elapsedMs: 20,
            finalUrl: 'https://example.com/landing',
            redirects: [],
            resolvedPublicIps: [],
            errorType: null,
            errorDetail: null,
            expectedTextFound: null,
            tlsExpiresAt: null,
            responseBodyBytes: $responseBodyBytes,
        );
    }

    /** @param array<string, mixed> $overrides
     * @return array<string, string>
     */
    private function monitorInput(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Response size check',
            'url' => 'https://fixture.test/ok',
            'label' => '',
            'expected_text' => '',
            'expected_text_mode' => 'present',
            'expected_response_header' => '',
            'redirect_hosts' => '',
            'request_headers' => '',
        ], $overrides);
    }
}
