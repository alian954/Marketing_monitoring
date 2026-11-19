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
            'state' => 'enabled',
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
            ]))
            ->assertOk()
            ->assertSee(route('monitors.index', [
                'search' => 'Campaign',
                'label' => 'Campaign',
                'state' => 'enabled',
                'page' => 2,
            ]));

        $this->assertSame(51, $response->viewData('monitors')->total());
        $this->assertSame([
            'search' => 'Campaign',
            'label' => 'Campaign',
            'state' => 'enabled',
        ], $response->viewData('filters'));
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

    /** @param array{name: string, label: string, enabled?: bool} $attributes */
    private function createMonitor(array $attributes): Monitor
    {
        $url = 'https://example.com/monitors/'.hash('sha256', $attributes['name']);

        return Monitor::factory()->create([
            ...$attributes,
            'url' => $url,
            'url_hash' => Monitor::fingerprintUrl($url),
        ]);
    }
}
