<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientMarketingAnnotation;
use App\Models\Incident;
use App\Models\MarketingAlertEvent;
use App\Models\MarketingAlertRule;
use App\Models\Monitor;
use App\Models\SocialEngagementTarget;
use App\Models\User;
use App\Models\WorkspaceMembership;
use App\Services\ClientMarketingTimeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientMarketingAnnotationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-10 12:00:00');
    }

    public function test_owner_can_add_list_and_delete_annotations(): void
    {
        $owner = User::factory()->create();
        $client = $owner->clients()->create(['name' => '日曆客戶']);

        $this->actingAs($owner)
            ->post(route('clients.annotations.store', $client), [
                'occurred_on' => '2026-11-05',
                'category' => ClientMarketingAnnotation::CATEGORY_BUDGET,
                'title' => '  Meta 日預算調高至 5,000  ',
                'note' => '   ',
            ])
            ->assertRedirect(route('clients.annotations.index', $client));

        $annotation = $client->marketingAnnotations()->sole();
        $this->assertSame('Meta 日預算調高至 5,000', $annotation->title);
        $this->assertNull($annotation->note);
        $this->assertSame($owner->id, $annotation->created_by_user_id);

        $this->get(route('clients.annotations.index', $client))
            ->assertOk()
            ->assertSee('Meta 日預算調高至 5,000')
            ->assertSee('預算調整')
            ->assertSee('新增註記');

        $this->delete(route('clients.annotations.destroy', [$client, $annotation]))
            ->assertRedirect(route('clients.annotations.index', $client));
        $this->assertDatabaseCount('client_marketing_annotations', 0);
    }

    public function test_invalid_annotations_are_rejected(): void
    {
        $owner = User::factory()->create();
        $client = $owner->clients()->create(['name' => '日曆客戶']);

        $this->actingAs($owner)
            ->post(route('clients.annotations.store', $client), [
                'occurred_on' => '2026/11/05',
                'category' => 'unknown',
                'title' => str_repeat('長', 121),
            ])
            ->assertSessionHasErrors(['occurred_on', 'category', 'title']);

        $this->assertDatabaseCount('client_marketing_annotations', 0);
    }

    public function test_read_only_members_can_view_but_not_manage_and_other_workspaces_cannot_reach_annotations(): void
    {
        $owner = User::factory()->create();
        $client = $owner->clients()->create(['name' => '日曆客戶']);
        $otherClient = $owner->clients()->create(['name' => '另一客戶']);
        $annotation = ClientMarketingAnnotation::factory()->create(['client_id' => $client->id, 'title' => '雙十一開跑']);
        $member = User::factory()->create();
        WorkspaceMembership::factory()->create([
            'owner_user_id' => $owner->id,
            'member_user_id' => $member->id,
            'role' => WorkspaceMembership::ROLE_READ_ONLY,
            'client_scope' => WorkspaceMembership::CLIENT_SCOPE_ALL,
        ]);

        $this->actingAs($owner)
            ->delete(route('clients.annotations.destroy', [$otherClient, $annotation]))
            ->assertNotFound();

        $this->actingAs($member)
            ->get(route('clients.annotations.index', $client))
            ->assertOk()
            ->assertSee('雙十一開跑')
            ->assertDontSee('新增註記</button>', false);
        $this->post(route('clients.annotations.store', $client), [
            'occurred_on' => '2026-11-05',
            'category' => ClientMarketingAnnotation::CATEGORY_OTHER,
            'title' => '不應建立',
        ])->assertForbidden();
        $this->delete(route('clients.annotations.destroy', [$client, $annotation]))->assertForbidden();

        $this->actingAs(User::factory()->create())
            ->get(route('clients.annotations.index', $client))
            ->assertNotFound();

        $this->assertDatabaseCount('client_marketing_annotations', 1);
    }

    public function test_cross_platform_overview_lists_annotations_incidents_and_alerts_in_period(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);
        $client = $owner->clients()->create(['name' => '日曆客戶']);
        $otherClient = $owner->clients()->create(['name' => '另一客戶']);
        ClientMarketingAnnotation::factory()->create([
            'client_id' => $client->id,
            'occurred_on' => '2026-11-05',
            'category' => ClientMarketingAnnotation::CATEGORY_PROMOTION,
            'title' => '雙十一預熱',
        ]);
        ClientMarketingAnnotation::factory()->create(['client_id' => $client->id, 'occurred_on' => '2026-10-01', 'title' => '期間外註記']);
        ClientMarketingAnnotation::factory()->create(['client_id' => $otherClient->id, 'occurred_on' => '2026-11-05', 'title' => '別的客戶註記']);

        $monitor = Monitor::factory()->create(['user_id' => $owner->id, 'client_id' => $client->id, 'name' => '官網首頁']);
        Incident::factory()->create([
            'monitor_id' => $monitor->id,
            'rule_key' => 'tracking_tags',
            'state' => Incident::STATE_RESOLVED,
            'opened_at' => '2026-11-05 20:00:00',
        ]);

        $target = SocialEngagementTarget::factory()->create(['client_id' => $client->id, 'tag' => '雙十一主檔']);
        $rule = MarketingAlertRule::factory()->create(['client_id' => $client->id, 'metric' => MarketingAlertRule::METRIC_ROAS_BELOW]);
        MarketingAlertEvent::query()->forceCreate([
            'marketing_alert_rule_id' => $rule->id,
            'social_engagement_target_id' => $target->id,
            'source' => 'google_ads',
            'observed_on' => '2026-11-04',
            'message' => '雙十一主檔 ROAS 0.5 倍低於 1 倍',
        ]);

        $response = $this->get(route('clients.cross-platform', ['client' => $client, 'from' => '2026-11-01', 'to' => '2026-11-09']))
            ->assertOk()
            ->assertSee('期間事件')
            ->assertSee('雙十一預熱')
            ->assertSee('官網首頁')
            ->assertDontSee('期間外註記')
            ->assertDontSee('別的客戶註記');

        $timeline = $response->viewData('timeline');
        $this->assertSame(
            [['2026-11-04', 'alert'], ['2026-11-05', 'annotation'], ['2026-11-06', 'incident']],
            array_map(static fn (array $entry): array => [$entry['date'], $entry['source']], $timeline),
            'Incidents are dated in Taipei time, so 20:00 UTC falls on the next day.',
        );
        $this->assertSame('頁面缺少追蹤碼', $timeline[2]['category_label']);
    }

    public function test_chart_markers_group_events_by_bar_date(): void
    {
        $timeline = app(ClientMarketingTimeline::class);
        $entries = [
            ['date' => '2026-11-05', 'source' => 'annotation', 'category_label' => '預算調整', 'title' => '調高預算', 'note' => null],
            ['date' => '2026-11-05', 'source' => 'alert', 'category_label' => 'ROAS 低於門檻', 'title' => 'ROAS 偏低', 'note' => null],
            ['date' => '2026-11-20', 'source' => 'incident', 'category_label' => 'HTTP 回應異常', 'title' => '官網', 'note' => null],
        ];
        $bars = [
            ['date' => '2026-11-05', 'x' => 100.0, 'width' => 10.0],
            ['date' => '2026-11-06', 'x' => 120.0, 'width' => 10.0],
        ];

        $markers = $timeline->chartMarkers($entries, $bars);

        $this->assertCount(1, $markers);
        $this->assertSame(105.0, $markers[0]['x']);
        $this->assertSame(['annotation', 'alert'], $markers[0]['sources']);
        $this->assertSame('2026-11-05 預算調整：調高預算；ROAS 低於門檻：ROAS 偏低', $markers[0]['label']);
    }

    public function test_deleting_a_client_removes_its_annotations(): void
    {
        $owner = User::factory()->create();
        $client = Client::factory()->for($owner)->create();
        ClientMarketingAnnotation::factory()->count(2)->create(['client_id' => $client->id]);

        $client->delete();

        $this->assertDatabaseCount('client_marketing_annotations', 0);
    }
}
