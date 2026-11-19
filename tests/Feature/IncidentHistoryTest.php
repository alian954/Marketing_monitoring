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
            'date_from' => now('Asia/Taipei')->toDateString(),
            'date_to' => now('Asia/Taipei')->toDateString(),
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

    public function test_incident_history_and_csv_filter_inclusive_taiwan_first_seen_dates(): void
    {
        $this->travelTo('2026-11-19 12:00:00');
        $user = User::factory()->create();
        $monitor = $this->createMonitor('Date filter campaign');
        $incidents = [
            'before' => Incident::factory()->for($monitor)->create(['created_at' => '2026-11-18 15:59:59']),
            'start' => Incident::factory()->for($monitor)->create(['created_at' => '2026-11-18 16:00:00']),
            'end' => Incident::factory()->for($monitor)->create(['created_at' => '2026-11-19 15:59:59']),
            'after' => Incident::factory()->for($monitor)->create(['created_at' => '2026-11-19 16:00:00']),
        ];
        $filters = ['date_from' => '2026-11-19', 'date_to' => '2026-11-19'];

        $detailResponse = $this->actingAs($user)
            ->get(route('incidents.index', $filters))
            ->assertOk()
            ->assertSee('日期依首次發現時間的台灣日期篩選，起訖日都包含當日。')
            ->assertSee(route('incidents.export', $filters));

        $this->assertEqualsCanonicalizing(
            [$incidents['start']->id, $incidents['end']->id],
            $detailResponse->viewData('incidents')->getCollection()->modelKeys(),
        );

        $exportResponse = $this->actingAs($user)
            ->get(route('incidents.export', $filters))
            ->assertOk();
        $rows = $this->csvRows($exportResponse->streamedContent());

        $this->assertEqualsCanonicalizing(
            [(string) $incidents['start']->id, (string) $incidents['end']->id],
            array_column($rows, 'incident_id'),
        );
    }

    public function test_incident_history_and_csv_can_filter_acknowledged_and_unacknowledged_incidents(): void
    {
        $user = User::factory()->create();
        $monitor = $this->createMonitor('Acknowledgement filter campaign');
        $acknowledgedIncident = Incident::factory()->for($monitor)->create([
            'acknowledged_at' => now(),
            'acknowledged_by_id' => $user->id,
        ]);
        $unacknowledgedIncident = Incident::factory()->for($monitor)->create([
            'acknowledged_at' => null,
            'acknowledged_by_id' => null,
        ]);

        $acknowledgedFilters = ['acknowledgement' => 'acknowledged'];
        $acknowledgedResponse = $this->actingAs($user)
            ->get(route('incidents.index', $acknowledgedFilters))
            ->assertOk()
            ->assertSee('<option value="acknowledged" selected>已認領</option>', false)
            ->assertSee(route('incidents.export', $acknowledgedFilters));

        $this->assertSame(
            [$acknowledgedIncident->id],
            $acknowledgedResponse->viewData('incidents')->getCollection()->modelKeys(),
        );

        $acknowledgedExport = $this->get(route('incidents.export', $acknowledgedFilters))->assertOk();
        $this->assertSame(
            [(string) $acknowledgedIncident->id],
            array_column($this->csvRows($acknowledgedExport->streamedContent()), 'incident_id'),
        );

        $unacknowledgedFilters = ['acknowledgement' => 'unacknowledged'];
        $unacknowledgedResponse = $this->get(route('incidents.index', $unacknowledgedFilters))
            ->assertOk()
            ->assertSee('<option value="unacknowledged" selected>尚未認領</option>', false);

        $this->assertSame(
            [$unacknowledgedIncident->id],
            $unacknowledgedResponse->viewData('incidents')->getCollection()->modelKeys(),
        );

        $unacknowledgedExport = $this->get(route('incidents.export', $unacknowledgedFilters))->assertOk();
        $this->assertSame(
            [(string) $unacknowledgedIncident->id],
            array_column($this->csvRows($unacknowledgedExport->streamedContent()), 'incident_id'),
        );
    }

    public function test_incident_history_rejects_unknown_acknowledgement_filter(): void
    {
        $this->actingAs(User::factory()->create())
            ->from(route('incidents.index'))
            ->get(route('incidents.index', ['acknowledgement' => 'unknown']))
            ->assertRedirect(route('incidents.index'))
            ->assertSessionHasErrors(['acknowledgement' => '請選擇有效的事件認領篩選條件。']);
    }

    public function test_incident_history_rejects_invalid_or_reversed_date_filters(): void
    {
        $indexUrl = route('incidents.index');
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from($indexUrl)
            ->get(route('incidents.index', ['date_from' => '2026-02-30']))
            ->assertRedirect($indexUrl)
            ->assertSessionHasErrors(['date_from' => '開始日期格式無效。']);

        $this->actingAs($user)
            ->from($indexUrl)
            ->get(route('incidents.index', ['date_from' => '2026-11-20', 'date_to' => '2026-11-19']))
            ->assertRedirect($indexUrl)
            ->assertSessionHasErrors(['date_to' => '結束日期不可早於開始日期。']);
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
