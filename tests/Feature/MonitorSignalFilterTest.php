<?php

namespace Tests\Feature;

use App\Models\CheckRun;
use App\Models\Monitor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MonitorSignalFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_monitor_list_displays_and_filters_latest_general_and_adsbot_signals(): void
    {
        $asOf = now()->startOfMinute();
        $generalTimeoutMonitor = $this->createMonitor('General timeout');
        CheckRun::factory()->for($generalTimeoutMonitor)->create([
            'scheduled_at' => $asOf,
            'normal_status' => null,
            'error_type' => 'timeout',
        ]);

        $adsbotTimeoutMonitor = $this->createMonitor('AdsBot timeout');
        CheckRun::factory()->for($adsbotTimeoutMonitor)->create([
            'scheduled_at' => $asOf,
            'adsbot_error_type' => 'timeout',
        ]);

        $mixedConditionMonitor = $this->createMonitor('HTTP 503 AdsBot timeout');
        CheckRun::factory()->for($mixedConditionMonitor)->create([
            'scheduled_at' => $asOf,
            'normal_status' => 503,
            'adsbot_error_type' => 'timeout',
        ]);

        $otherSignalMonitor = $this->createMonitor('DNS failure');
        CheckRun::factory()->for($otherSignalMonitor)->create([
            'scheduled_at' => $asOf,
            'normal_status' => null,
            'error_type' => 'dns_no_address',
        ]);

        $recoveredMonitor = $this->createMonitor('Recovered timeout');
        CheckRun::factory()->for($recoveredMonitor)->create([
            'scheduled_at' => $asOf->copy()->subMinutes(2),
            'normal_status' => null,
            'error_type' => 'timeout',
        ]);
        CheckRun::factory()->for($recoveredMonitor)->create([
            'scheduled_at' => $asOf->copy()->subMinute(),
            'normal_status' => 200,
            'error_type' => null,
            'adsbot_error_type' => null,
        ]);

        $user = User::factory()->create();
        $filters = ['signal' => 'timeout'];
        $response = $this->actingAs($user)
            ->get(route('monitors.index', $filters))
            ->assertOk()
            ->assertSee('<th scope="col">最新錯誤訊號</th>', false)
            ->assertSee('一般探測：timeout')
            ->assertSee('AdsBot 模擬：timeout')
            ->assertDontSee('DNS failure')
            ->assertDontSee('Recovered timeout')
            ->assertSee('name="signal"', false)
            ->assertSee('value="timeout"', false)
            ->assertSee(route('monitors.export', $filters));

        $this->assertEqualsCanonicalizing(
            [$generalTimeoutMonitor->id, $adsbotTimeoutMonitor->id, $mixedConditionMonitor->id],
            $response->viewData('monitors')->getCollection()->modelKeys(),
        );

        $combinedResponse = $this->get(route('monitors.index', ['http_status' => '503', 'signal' => 'timeout']))
            ->assertOk();
        $this->assertSame([$mixedConditionMonitor->id], $combinedResponse->viewData('monitors')->getCollection()->modelKeys());

        $exportResponse = $this->get(route('monitors.export', $filters))->assertOk();
        $rows = $this->csvRows($exportResponse->streamedContent());

        $this->assertSame(
            [$generalTimeoutMonitor->name, $adsbotTimeoutMonitor->name, $mixedConditionMonitor->name],
            array_column($rows, 'name'),
        );
        $this->assertSame(['timeout', '', ''], array_column($rows, 'error_type'));
        $this->assertSame(['', 'timeout', 'timeout'], array_column($rows, 'adsbot_error_type'));
    }

    public function test_monitor_list_and_csv_reject_invalid_signal_filters(): void
    {
        $user = User::factory()->create();

        foreach (['UPPERCASE', 'invalid-signal', str_repeat('a', 41)] as $signal) {
            $this->actingAs($user)
                ->from(route('monitors.index'))
                ->get(route('monitors.index', ['signal' => $signal]))
                ->assertRedirect(route('monitors.index'))
                ->assertSessionHasErrors('signal');

            $this->actingAs($user)
                ->from(route('monitors.index'))
                ->get(route('monitors.export', ['signal' => $signal]))
                ->assertRedirect(route('monitors.index'))
                ->assertSessionHasErrors('signal');
        }
    }

    private function createMonitor(string $name): Monitor
    {
        $url = 'https://example.com/monitors/'.str()->slug($name);

        return Monitor::factory()->create([
            'name' => $name,
            'url' => $url,
            'url_hash' => Monitor::fingerprintUrl($url),
        ]);
    }

    /** @return list<array<string, string>> */
    private function csvRows(string $content): array
    {
        $lines = preg_split('/\r\n|\n|\r/', trim($content));
        $headers = str_getcsv(array_shift($lines));
        $rows = array_map(static fn (string $line): array => str_getcsv($line), $lines);

        return array_map(static fn (array $row): array => array_combine($headers, $row), $rows);
    }
}
