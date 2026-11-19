<?php

namespace Tests\Feature;

use App\Models\Incident;
use App\Models\Monitor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IncidentHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('incidents.index'))->assertRedirect(route('login'));
    }

    public function test_authenticated_user_can_filter_and_page_through_incident_history(): void
    {
        $user = User::factory()->create();
        $monitor = $this->createMonitor('Launch Campaign');
        $otherMonitor = $this->createMonitor('Other Campaign');
        $incidents = collect();

        foreach (range(1, 26) as $index) {
            $incidents->push(Incident::factory()->for($monitor)->create([
                'rule_key' => 'http',
                'state' => Incident::STATE_OPEN,
                'last_seen_at' => now()->addSeconds($index),
            ]));
        }

        Incident::factory()->for($monitor)->create([
            'rule_key' => 'content',
            'state' => Incident::STATE_OPEN,
        ]);
        Incident::factory()->for($monitor)->create([
            'rule_key' => 'http',
            'state' => Incident::STATE_RESOLVED,
        ]);
        Incident::factory()->for($otherMonitor)->create([
            'rule_key' => 'http',
            'state' => Incident::STATE_OPEN,
        ]);

        $query = [
            'search' => 'Launch',
            'state' => Incident::STATE_OPEN,
            'monitor_id' => $monitor->id,
            'rule_key' => 'http',
        ];

        $this->actingAs($user)
            ->get(route('incidents.index', $query))
            ->assertOk()
            ->assertSee('26 筆符合條件')
            ->assertSee('HTTP 回應異常')
            ->assertSee(route('incidents.export', $query))
            ->assertSee('page=2')
            ->assertSee('#'.$incidents->last()->id);

        $this->actingAs($user)
            ->get(route('incidents.index', [...$query, 'page' => 2]))
            ->assertOk()
            ->assertSee('顯示第 26–26 筆，共 26 筆')
            ->assertSee('#'.$incidents->first()->id)
            ->assertDontSee('#'.$incidents->last()->id);
    }

    public function test_incident_history_shows_an_empty_state(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('incidents.index'))
            ->assertOk()
            ->assertSee('尚無事件紀錄')
            ->assertSee('匯出符合條件的 CSV');
    }

    public function test_incident_history_labels_incomplete_probe_warnings(): void
    {
        $monitor = $this->createMonitor('Large campaign page');
        Incident::factory()->for($monitor)->create([
            'rule_key' => 'probe_incomplete',
            'severity' => 'warning',
            'state' => Incident::STATE_OPEN,
        ]);

        $this->actingAs(User::factory()->create())
            ->get(route('incidents.index', ['rule_key' => 'probe_incomplete']))
            ->assertOk()
            ->assertSee('回應過大，驗證未完成')
            ->assertSee('Large campaign page');
    }

    public function test_user_can_search_incident_history_by_incident_id(): void
    {
        $user = User::factory()->create();
        $monitor = $this->createMonitor('Searchable Campaign');
        $matchingIncident = Incident::factory()->for($monitor)->create();
        $otherIncident = Incident::factory()->for($monitor)->create();

        $this->actingAs($user)
            ->get(route('incidents.index', ['search' => (string) $matchingIncident->id]))
            ->assertOk()
            ->assertSee('1 筆符合條件')
            ->assertSee('#'.$matchingIncident->id)
            ->assertDontSee('#'.$otherIncident->id);
    }

    public function test_filtered_incident_csv_contains_only_matching_incidents(): void
    {
        $user = User::factory()->create();
        $monitor = $this->createMonitor('Launch Campaign');
        $otherMonitor = $this->createMonitor('Other Campaign');
        $matchingIncident = Incident::factory()->for($monitor)->create([
            'rule_key' => 'content',
            'state' => Incident::STATE_RESOLVED,
        ]);
        Incident::factory()->for($monitor)->create([
            'rule_key' => 'http',
            'state' => Incident::STATE_RESOLVED,
        ]);
        Incident::factory()->for($otherMonitor)->create([
            'rule_key' => 'content',
            'state' => Incident::STATE_RESOLVED,
        ]);

        $response = $this->actingAs($user)
            ->get(route('incidents.export', [
                'search' => 'Launch',
                'state' => Incident::STATE_RESOLVED,
                'monitor_id' => $monitor->id,
                'rule_key' => 'content',
            ]))
            ->assertOk();

        $lines = preg_split('/\r\n|\n|\r/', trim($response->streamedContent()));
        $rows = array_map(static fn (string $line): array => str_getcsv($line), array_slice($lines, 1));

        $this->assertCount(1, $rows);
        $this->assertSame((string) $matchingIncident->id, $rows[0][0]);
    }

    private function createMonitor(string $name): Monitor
    {
        $url = 'https://example.test/'.str()->slug($name);

        return Monitor::factory()->create([
            'name' => $name,
            'url' => $url,
            'url_hash' => Monitor::fingerprintUrl($url),
        ]);
    }
}
