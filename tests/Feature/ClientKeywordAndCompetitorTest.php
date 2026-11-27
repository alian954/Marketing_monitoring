<?php

namespace Tests\Feature;

use App\Models\ClientTrackedKeyword;
use App\Models\GoogleSearchConsoleClientSite;
use App\Models\GoogleSearchConsoleConnection;
use App\Models\Monitor;
use App\Models\User;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ClientKeywordAndCompetitorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-23 02:00:00');
        config([
            'services.google_search_console.client_id' => 'google-search-console-client',
            'services.google_search_console.client_secret' => 'google-search-console-secret',
        ]);
    }

    public function test_keywords_are_added_normalized_and_deduplicated(): void
    {
        $owner = User::factory()->create();
        $client = $owner->clients()->create(['name' => 'SEO 客戶']);
        ClientTrackedKeyword::factory()->create(['client_id' => $client->id, 'keyword' => '台北 牙醫']);

        $this->actingAs($owner)
            ->post(route('clients.keywords.store', $client), [
                'keywords' => "台北  牙醫\nInvisalign 價格, 隱形矯正\n隱形矯正",
                'target_position' => '3',
            ])
            ->assertSessionHas('status', '已新增 2 個追蹤關鍵字（已略過重複的關鍵字）。');

        $this->assertSame(['invisalign 價格', '台北 牙醫', '隱形矯正'], $client->trackedKeywords()->orderBy('keyword')->pluck('keyword')->all());
        $this->assertSame(3, $client->trackedKeywords()->where('keyword', '隱形矯正')->value('target_position'));

        $this->post(route('clients.keywords.store', $client), ['keywords' => '', 'target_position' => '0'])
            ->assertSessionHasErrors(['keywords', 'target_position']);
    }

    public function test_weekly_sync_stores_positions_from_search_console(): void
    {
        $owner = User::factory()->create();
        $client = $owner->clients()->create(['name' => 'SEO 客戶']);
        $connection = GoogleSearchConsoleConnection::factory()->for($owner)->create(['refresh_token' => 'refresh-token']);
        GoogleSearchConsoleClientSite::withoutGlobalScopes()->create([
            'google_search_console_connection_id' => $connection->id,
            'client_id' => $client->id,
            'site_url' => 'https://dental.example/',
        ]);
        $ranked = ClientTrackedKeyword::factory()->create(['client_id' => $client->id, 'keyword' => '隱形矯正', 'target_position' => 3]);
        $missing = ClientTrackedKeyword::factory()->create(['client_id' => $client->id, 'keyword' => 'c++ 補習']);
        Http::preventStrayRequests();
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'access-token', 'expires_in' => 3600]),
            'https://www.googleapis.com/webmasters/v3/sites/*/searchAnalytics/query' => Http::response(['rows' => [
                ['keys' => ['隱形矯正'], 'clicks' => 12, 'impressions' => 400, 'ctr' => 0.03, 'position' => 2.46],
            ]]),
        ]);

        $this->artisan('keywords:sync-rankings')->assertExitCode(0);

        Http::assertSent(function (ClientRequest $request): bool {
            if (! str_contains($request->url(), 'searchAnalytics')) {
                return false;
            }

            $filter = $request->data()['dimensionFilterGroups'][0]['filters'][0];

            return $request->data()['startDate'] === '2026-11-13'
                && $request->data()['endDate'] === '2026-11-19'
                && $filter['operator'] === 'includingRegex'
                && $filter['expression'] === '^(c\+\+ 補習|隱形矯正)$';
        });
        $ranking = $ranked->rankings()->sole();
        $this->assertSame('2026-11-19', $ranking->measured_on->toDateString());
        $this->assertSame('2.46', $ranking->position);
        $this->assertSame(12, $ranking->clicks);
        $this->assertSame('3.00', $ranking->ctr_percent);
        $this->assertNull($missing->rankings()->sole()->position);

        $this->actingAs($owner)
            ->get(route('clients.keywords.index', $client))
            ->assertOk()
            ->assertSee('隱形矯正')
            ->assertSee('2.5')
            ->assertSee('已達成');
    }

    public function test_refresh_without_a_search_console_site_reports_an_error(): void
    {
        $owner = User::factory()->create();
        $client = $owner->clients()->create(['name' => 'SEO 客戶']);
        ClientTrackedKeyword::factory()->create(['client_id' => $client->id]);

        $this->actingAs($owner)
            ->post(route('clients.keywords.refresh', $client))
            ->assertSessionHasErrors('refresh');
        $this->get(route('clients.keywords.index', $client))->assertSee('尚未設定 Search Console 網站');
    }

    public function test_competitor_page_lists_tagged_monitors_and_prefills_the_monitor_form(): void
    {
        $owner = User::factory()->create();
        $client = $owner->clients()->create(['name' => 'SEO 客戶']);
        Monitor::factory()->create(['user_id' => $owner->id, 'client_id' => $client->id, 'name' => '對手 A 價格頁', 'label' => '競品, 價格']);
        Monitor::factory()->create(['user_id' => $owner->id, 'client_id' => $client->id, 'name' => '自家官網', 'label' => '正式環境', 'url' => 'https://own-site.example/', 'url_hash' => Monitor::fingerprintUrl('https://own-site.example/')]);

        $this->actingAs($owner)
            ->get(route('clients.competitors.index', $client))
            ->assertOk()
            ->assertSee('對手 A 價格頁')
            ->assertDontSee('自家官網');

        $this->get(route('monitors.create', ['client_id' => $client->id, 'preset' => 'competitor']))
            ->assertOk()
            ->assertSee('value="競品"', false);
    }

    public function test_read_only_members_cannot_change_keywords_and_other_workspaces_are_blocked(): void
    {
        $owner = User::factory()->create();
        $client = $owner->clients()->create(['name' => 'SEO 客戶']);
        $keyword = ClientTrackedKeyword::factory()->create(['client_id' => $client->id]);
        $otherClient = $owner->clients()->create(['name' => '另一客戶']);
        $member = User::factory()->create();
        WorkspaceMembership::factory()->create([
            'owner_user_id' => $owner->id,
            'member_user_id' => $member->id,
            'role' => WorkspaceMembership::ROLE_READ_ONLY,
            'client_scope' => WorkspaceMembership::CLIENT_SCOPE_ALL,
        ]);

        $this->actingAs($owner)->delete(route('clients.keywords.destroy', [$otherClient, $keyword]))->assertNotFound();
        $this->actingAs($member)->delete(route('clients.keywords.destroy', [$client, $keyword]))->assertForbidden();
        $this->actingAs(User::factory()->create())->get(route('clients.keywords.index', $client))->assertNotFound();
        $this->actingAs(User::factory()->create())->get(route('clients.competitors.index', $client))->assertNotFound();
        $this->assertDatabaseCount('client_tracked_keywords', 1);
    }
}
