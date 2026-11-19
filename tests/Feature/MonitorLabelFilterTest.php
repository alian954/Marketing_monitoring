<?php

namespace Tests\Feature;

use App\Models\Monitor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MonitorLabelFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_monitor_list_filters_by_exact_label_alongside_search_and_state(): void
    {
        $targetMonitor = $this->createMonitor([
            'name' => 'Spring campaign',
            'label' => 'Campaign A',
            'enabled' => true,
        ]);
        $this->createMonitor([
            'name' => 'Autumn campaign',
            'label' => 'Campaign A',
            'enabled' => true,
        ]);
        $this->createMonitor([
            'name' => 'Spring campaign other label',
            'label' => 'Campaign B',
            'enabled' => true,
        ]);
        $this->createMonitor([
            'name' => 'Spring campaign paused',
            'label' => 'Campaign A',
            'enabled' => false,
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('monitors.index', [
                'search' => 'Spring',
                'label' => 'Campaign A',
                'state' => 'enabled',
            ]))
            ->assertOk()
            ->assertSee($targetMonitor->name)
            ->assertDontSee('Autumn campaign')
            ->assertDontSee('Spring campaign other label')
            ->assertDontSee('Spring campaign paused');

        $this->assertSame(1, $response->viewData('monitors')->total());
        $this->assertSame([
            'search' => 'Spring',
            'label' => 'Campaign A',
            'platform' => 'all',
            'state' => 'enabled',
            'http_status' => '',
            'sort' => 'name',
        ], $response->viewData('filters'));
    }

    public function test_monitor_label_suggestions_are_distinct_sorted_and_unfiltered(): void
    {
        $this->createMonitor(['name' => 'Zebra', 'label' => 'Beta']);
        $this->createMonitor(['name' => 'Alpha', 'label' => 'Alpha']);
        $this->createMonitor(['name' => 'Beta duplicate', 'label' => 'Beta']);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('monitors.index', ['search' => 'Zebra', 'state' => 'enabled']))
            ->assertOk()
            ->assertSee('name="label"', false)
            ->assertSee('list="monitor-label-options"', false)
            ->assertSee('<option value="Alpha">Alpha</option>', false)
            ->assertSee('<option value="Beta">Beta</option>', false);

        $this->assertSame(['Alpha', 'Beta'], $response->viewData('labels')->all());
        $this->assertSame(1, $response->viewData('monitors')->total());
    }

    public function test_monitor_search_finds_a_social_post_by_its_full_encrypted_url(): void
    {
        $url = 'https://www.threads.net/@creator/post/'.str_repeat('A', 130);
        $monitor = Monitor::factory()->create([
            'name' => 'Long Threads post URL',
            'url' => $url,
            'url_hash' => Monitor::fingerprintUrl($url),
        ]);
        $this->createMonitor(['name' => 'Unrelated website', 'label' => 'Operations']);
        $filters = ['search' => $url];

        $response = $this->actingAs(User::factory()->create())
            ->get(route('monitors.index', $filters))
            ->assertOk()
            ->assertSee('完整網址')
            ->assertSee('網址請貼上完整連結。')
            ->assertSee(route('monitors.export', $filters));

        $this->assertSame([$monitor->id], $response->viewData('monitors')->getCollection()->modelKeys());
        $this->assertSame(1, $response->viewData('monitors')->total());

        $exportResponse = $this->actingAs(User::factory()->create())
            ->get(route('monitors.export', $filters))
            ->assertOk();
        $rows = $this->csvRows($exportResponse->streamedContent());

        $this->assertCount(1, $rows);
        $this->assertSame($monitor->name, $rows[0]['name']);
    }

    public function test_monitor_list_sorts_by_check_time_and_places_unchecked_items_first_when_oldest_is_selected(): void
    {
        $this->travelTo('2026-11-19 12:00:00');
        $oldestMonitor = $this->createMonitor([
            'name' => 'Older check',
            'label' => 'Operations',
            'last_checked_at' => '2026-11-19 08:00:00',
        ]);
        $newestMonitor = $this->createMonitor([
            'name' => 'Recent check',
            'label' => 'Operations',
            'last_checked_at' => '2026-11-19 10:00:00',
        ]);
        $uncheckedMonitor = $this->createMonitor(['name' => 'Never checked', 'label' => 'Operations']);
        $user = User::factory()->create();

        $newestFirst = $this->actingAs($user)
            ->get(route('monitors.index', ['sort' => 'last_checked_desc']))
            ->assertOk()
            ->assertSee('value="last_checked_desc"', false)
            ->viewData('monitors');
        $oldestFirst = $this->actingAs($user)
            ->get(route('monitors.index', ['sort' => 'last_checked_asc']))
            ->assertOk()
            ->assertSee('value="last_checked_asc"', false)
            ->assertSee('清除篩選')
            ->viewData('monitors');

        $this->assertSame(
            [$newestMonitor->id, $oldestMonitor->id, $uncheckedMonitor->id],
            $newestFirst->getCollection()->modelKeys(),
        );
        $this->assertSame(
            [$uncheckedMonitor->id, $oldestMonitor->id, $newestMonitor->id],
            $oldestFirst->getCollection()->modelKeys(),
        );
    }

    public function test_monitor_list_and_csv_export_filter_stale_monitors_using_interval_and_grace_period(): void
    {
        $this->travelTo('2026-11-19 12:00:00');
        $intervalSeconds = 300;
        $staleBefore = now()->copy()->subSeconds($intervalSeconds + (int) config('monitoring.stale_grace_seconds'));
        $staleCheckedMonitor = $this->createMonitor([
            'name' => 'Stale checked monitor',
            'label' => 'Operations',
            'interval_seconds' => $intervalSeconds,
            'last_checked_at' => $staleBefore->copy()->subSecond()->toDateTimeString(),
        ]);
        $staleUncheckedMonitor = $this->createMonitor([
            'name' => 'Stale unchecked monitor',
            'label' => 'Operations',
            'interval_seconds' => $intervalSeconds,
        ]);
        $staleUncheckedMonitor->forceFill(['created_at' => $staleBefore->copy()->subSecond()])->save();
        $this->createMonitor([
            'name' => 'At stale boundary',
            'label' => 'Operations',
            'interval_seconds' => $intervalSeconds,
            'last_checked_at' => $staleBefore->toDateTimeString(),
        ]);
        $this->createMonitor([
            'name' => 'Different interval still fresh',
            'label' => 'Operations',
            'interval_seconds' => 1800,
            'last_checked_at' => now()->copy()->subSeconds(1801)->toDateTimeString(),
        ]);
        $this->createMonitor([
            'name' => 'Paused stale monitor',
            'label' => 'Operations',
            'enabled' => false,
            'interval_seconds' => $intervalSeconds,
            'last_checked_at' => $staleBefore->copy()->subSecond()->toDateTimeString(),
        ]);
        $filters = ['state' => 'stale'];

        $response = $this->actingAs(User::factory()->create())
            ->get(route('monitors.index', $filters))
            ->assertOk()
            ->assertSee('<option value="stale" selected>檢查已逾時</option>', false);
        $monitors = $response->viewData('monitors')->getCollection();

        $this->assertEqualsCanonicalizing(
            [$staleCheckedMonitor->id, $staleUncheckedMonitor->id],
            $monitors->modelKeys(),
        );
        $this->assertSame(['stale', 'stale'], $monitors->map->healthState()->all());

        $exportResponse = $this->actingAs(User::factory()->create())
            ->get(route('monitors.export', $filters))
            ->assertOk();
        $rows = $this->csvRows($exportResponse->streamedContent());

        $this->assertEqualsCanonicalizing(
            [$staleCheckedMonitor->name, $staleUncheckedMonitor->name],
            array_column($rows, 'name'),
        );
    }

    public function test_monitor_search_label_and_state_filters_are_preserved_in_pagination(): void
    {
        for ($index = 1; $index <= 51; $index++) {
            $this->createMonitor([
                'name' => 'Campaign '.$index,
                'label' => 'Campaign',
                'enabled' => true,
            ]);
        }

        $response = $this->actingAs(User::factory()->create())
            ->get(route('monitors.index', [
                'search' => 'Campaign',
                'label' => 'Campaign',
                'state' => 'enabled',
                'sort' => 'last_checked_desc',
            ]))
            ->assertOk()
            ->assertSee(route('monitors.index', [
                'search' => 'Campaign',
                'label' => 'Campaign',
                'state' => 'enabled',
                'sort' => 'last_checked_desc',
                'page' => 2,
            ]));

        $this->assertSame(51, $response->viewData('monitors')->total());
        $this->assertSame([
            'search' => 'Campaign',
            'label' => 'Campaign',
            'platform' => 'all',
            'state' => 'enabled',
            'http_status' => '',
            'sort' => 'last_checked_desc',
        ], $response->viewData('filters'));
    }

    public function test_monitor_list_rejects_unknown_sort_values(): void
    {
        $this->actingAs(User::factory()->create())
            ->from(route('monitors.index'))
            ->get(route('monitors.index', ['sort' => 'random']))
            ->assertRedirect(route('monitors.index'))
            ->assertSessionHasErrors(['sort' => '請選擇有效的監測排序方式。']);
    }

    public function test_monitor_csv_export_uses_the_current_search_label_and_state_filters(): void
    {
        $matchingMonitor = $this->createMonitor([
            'name' => 'Spring campaign',
            'label' => 'Campaign A',
            'enabled' => true,
        ]);
        $this->createMonitor(['name' => 'Autumn campaign', 'label' => 'Campaign A', 'enabled' => true]);
        $this->createMonitor(['name' => 'Spring campaign other label', 'label' => 'Campaign B', 'enabled' => true]);
        $this->createMonitor(['name' => 'Spring campaign paused', 'label' => 'Campaign A', 'enabled' => false]);
        $filters = ['search' => 'Spring', 'label' => 'Campaign A', 'state' => 'enabled'];

        $this->actingAs(User::factory()->create())
            ->get(route('monitors.index', $filters))
            ->assertOk()
            ->assertSee(route('monitors.export', $filters))
            ->assertSee('匯出符合條件 CSV');

        $exportResponse = $this->actingAs(User::factory()->create())
            ->get(route('monitors.export', $filters))
            ->assertOk();
        $rows = $this->csvRows($exportResponse->streamedContent());

        $this->assertCount(1, $rows);
        $this->assertSame($matchingMonitor->name, $rows[0]['name']);
    }

    public function test_monitor_csv_export_without_filters_includes_every_monitor(): void
    {
        $firstMonitor = $this->createMonitor(['name' => 'First monitor', 'label' => 'First']);
        $secondMonitor = $this->createMonitor(['name' => 'Second monitor', 'label' => 'Second']);

        $exportResponse = $this->actingAs(User::factory()->create())
            ->get(route('monitors.export'))
            ->assertOk();
        $rows = $this->csvRows($exportResponse->streamedContent());

        $this->assertEqualsCanonicalizing(
            [$firstMonitor->name, $secondMonitor->name],
            array_column($rows, 'name'),
        );
    }

    public function test_monitor_csv_export_rejects_invalid_filters_instead_of_exporting_every_monitor(): void
    {
        $this->actingAs(User::factory()->create())
            ->from(route('monitors.index'))
            ->get(route('monitors.export', ['state' => 'unknown']))
            ->assertRedirect(route('monitors.index'))
            ->assertSessionHasErrors(['state' => '請選擇有效的監測狀態篩選條件。']);
    }

    public function test_monitor_list_rejects_oversized_label_filter(): void
    {
        $this->actingAs(User::factory()->create())
            ->from(route('monitors.index'))
            ->get(route('monitors.index', ['label' => str_repeat('a', 101)]))
            ->assertRedirect(route('monitors.index'))
            ->assertSessionHasErrors([
                'label' => '標籤篩選不得超過 100 個字元。',
            ]);
    }

    /** @param array{name: string, label: string, enabled?: bool, interval_seconds?: int, last_checked_at?: ?string} $attributes */
    private function createMonitor(array $attributes): Monitor
    {
        $url = 'https://example.com/monitors/'.hash('sha256', $attributes['name']);

        return Monitor::factory()->create([
            ...$attributes,
            'url' => $url,
            'url_hash' => Monitor::fingerprintUrl($url),
        ]);
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
