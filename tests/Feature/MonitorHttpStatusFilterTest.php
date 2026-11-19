<?php

namespace Tests\Feature;

use App\Models\CheckRun;
use App\Models\Monitor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MonitorHttpStatusFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_monitor_list_and_csv_filter_by_latest_http_status(): void
    {
        $asOf = now()->startOfMinute();
        $matchingMonitor = $this->createMonitor('Current 503');
        CheckRun::factory()->for($matchingMonitor)->create([
            'scheduled_at' => $asOf,
            'normal_status' => 503,
        ]);

        $recoveredMonitor = $this->createMonitor('Recovered from 503');
        CheckRun::factory()->for($recoveredMonitor)->create([
            'scheduled_at' => $asOf->copy()->subMinutes(2),
            'normal_status' => 503,
        ]);
        CheckRun::factory()->for($recoveredMonitor)->create([
            'scheduled_at' => $asOf->copy()->subMinute(),
            'normal_status' => 200,
        ]);

        $healthyMonitor = $this->createMonitor('Current 200');
        CheckRun::factory()->for($healthyMonitor)->create([
            'scheduled_at' => $asOf,
            'normal_status' => 200,
        ]);

        $noResponseMonitor = $this->createMonitor('No HTTP response');
        CheckRun::factory()->for($noResponseMonitor)->create([
            'scheduled_at' => $asOf,
            'status' => 'failed',
            'normal_status' => null,
        ]);

        $user = User::factory()->create();
        $filters = ['http_status' => '503'];
        $response = $this->actingAs($user)
            ->get(route('monitors.index', $filters))
            ->assertOk()
            ->assertSee('name="http_status"', false)
            ->assertSee('value="503"', false)
            ->assertSee('清除篩選')
            ->assertSee(route('monitors.export', $filters));

        $this->assertSame([$matchingMonitor->id], $response->viewData('monitors')->getCollection()->modelKeys());

        $exportResponse = $this->get(route('monitors.export', $filters))->assertOk();
        $rows = $this->csvRows($exportResponse->streamedContent());

        $this->assertSame([$matchingMonitor->name], array_column($rows, 'name'));
    }

    public function test_monitor_list_and_csv_reject_invalid_http_status_filters(): void
    {
        $user = User::factory()->create();

        foreach (['not-a-number', '99', '600'] as $httpStatus) {
            $this->actingAs($user)
                ->from(route('monitors.index'))
                ->get(route('monitors.index', ['http_status' => $httpStatus]))
                ->assertRedirect(route('monitors.index'))
                ->assertSessionHasErrors('http_status');

            $this->actingAs($user)
                ->from(route('monitors.index'))
                ->get(route('monitors.export', ['http_status' => $httpStatus]))
                ->assertRedirect(route('monitors.index'))
                ->assertSessionHasErrors('http_status');
        }
    }

    public function test_monitor_http_status_filter_is_preserved_in_pagination_links(): void
    {
        for ($index = 1; $index <= 51; $index++) {
            $monitor = $this->createMonitor('Not found '.$index);
            CheckRun::factory()->for($monitor)->create([
                'normal_status' => 404,
            ]);
        }

        $filters = ['http_status' => '404'];
        $response = $this->actingAs(User::factory()->create())
            ->get(route('monitors.index', $filters))
            ->assertOk()
            ->assertSee(route('monitors.index', [...$filters, 'page' => 2]));

        $this->assertSame(51, $response->viewData('monitors')->total());
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
