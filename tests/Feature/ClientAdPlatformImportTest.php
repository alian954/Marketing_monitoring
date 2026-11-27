<?php

namespace Tests\Feature;

use App\Models\ClientAdPlatformDailyMetric;
use App\Models\ClientMonthlyBudget;
use App\Models\User;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class ClientAdPlatformImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-10 12:00:00');
    }

    public function test_tiktok_export_is_imported_and_reimport_replaces_the_day(): void
    {
        $owner = User::factory()->create();
        $client = $owner->clients()->create(['name' => '短影音客戶']);
        $csv = "By Day,Campaign name,Cost (TWD),Impressions,Clicks (Destination),Conversions,Total complete payment value\n"
            ."2026-11-05,雙十一 TopView,\"3,000\",120000,1500,30,45000\n"
            ."2026-11-06,雙十一 TopView,2000,80000,900,20,30000\n"
            ."2026-11-06,常態導購,500,10000,200,5,6000\n"
            ."bad-date,常態導購,500,0,0,0,0\n";

        $this->actingAs($owner)
            ->post(route('clients.ad-imports.store', $client), [
                'platform' => 'tiktok_ads',
                'csv_file' => UploadedFile::fake()->createWithContent('tiktok.csv', $csv),
                'currency_code' => 'TWD',
            ])
            ->assertRedirect(route('clients.ad-imports.index', $client))
            ->assertSessionHas('importResult', fn (array $result): bool => $result['imported'] === 3
                && $result['campaigns'] === 2
                && $result['error_count'] === 1
                && $result['spend_by_currency'] == ['TWD' => 5500]);

        $this->post(route('clients.ad-imports.store', $client), [
            'platform' => 'tiktok_ads',
            'csv_file' => UploadedFile::fake()->createWithContent('tiktok.csv', "日期,廣告活動名稱,花費\n2026-11-06,常態導購,800\n"),
            'currency_code' => 'TWD',
        ]);

        $this->assertSame(3, $client->adPlatformDailyMetrics()->count());
        $this->assertSame('800.00', $client->adPlatformDailyMetrics()->where('campaign_name', '常態導購')->value('spend'));

        $this->get(route('clients.ad-imports.index', $client))
            ->assertOk()
            ->assertSee('雙十一 TopView')
            ->assertSee('15.00 倍');
    }

    public function test_imported_platforms_join_the_cross_platform_overview_and_budget(): void
    {
        $owner = User::factory()->create();
        $client = $owner->clients()->create(['name' => '短影音客戶']);
        ClientAdPlatformDailyMetric::factory()->create([
            'client_id' => $client->id, 'platform' => 'tiktok_ads', 'metric_on' => '2026-11-05', 'campaign_name' => 'A',
            'spend' => '1000', 'impressions' => 1000, 'clicks' => 50, 'conversions' => '4', 'conversion_value' => '4000',
        ]);
        ClientAdPlatformDailyMetric::factory()->create([
            'client_id' => $client->id, 'platform' => 'line_ads', 'metric_on' => '2026-11-06', 'campaign_name' => 'B',
            'spend' => '500', 'impressions' => 2000, 'clicks' => 20, 'conversions' => '1', 'conversion_value' => '1000',
        ]);
        ClientAdPlatformDailyMetric::factory()->create([
            'client_id' => $client->id, 'platform' => 'line_ads', 'metric_on' => '2026-11-04', 'campaign_name' => 'B',
            'spend' => '300', 'impressions' => 0, 'clicks' => 0, 'conversions' => '0', 'conversion_value' => '0',
        ]);
        ClientMonthlyBudget::factory()->create(['client_id' => $client->id, 'month' => '2026-11', 'total_budget' => '9000']);

        $response = $this->actingAs($owner)
            ->get(route('clients.cross-platform', ['client' => $client, 'from' => '2026-11-05', 'to' => '2026-11-06']))
            ->assertOk()
            ->assertSee('TikTok Ads')
            ->assertSee('LINE 廣告');

        $group = $response->viewData('overview')['currency_groups'][0];
        $this->assertEqualsWithDelta(1000.0, $group['platforms']['tiktok_ads']['spend'], 0.001);
        $this->assertEqualsWithDelta(500.0, $group['platforms']['line_ads']['spend'], 0.001);
        $this->assertEqualsWithDelta(1500.0, $group['total']['spend'], 0.001);
        $this->assertSame(3.33, $group['total']['roas']);
        $this->assertSame(['tiktok_ads', 'line_ads'], $response->viewData('chart')['sources']);
        $this->assertSame(['delta' => 1200.0, 'percent_change' => 400.0], $response->viewData('comparison')['currency_groups']['TWD']['spend']);
        $this->assertSame(1800.0, $response->viewData('budgetPacing')['total']['spend'], 'Month-to-date pacing includes imported platforms.');
    }

    public function test_invalid_files_platforms_and_scoped_access(): void
    {
        $owner = User::factory()->create();
        $client = $owner->clients()->create(['name' => '短影音客戶']);
        ClientAdPlatformDailyMetric::factory()->create(['client_id' => $client->id, 'platform' => 'line_ads', 'metric_on' => '2026-11-03']);
        $member = User::factory()->create();
        WorkspaceMembership::factory()->create([
            'owner_user_id' => $owner->id,
            'member_user_id' => $member->id,
            'role' => WorkspaceMembership::ROLE_READ_ONLY,
            'client_scope' => WorkspaceMembership::CLIENT_SCOPE_ALL,
        ]);

        $this->actingAs($owner)
            ->post(route('clients.ad-imports.store', $client), [
                'platform' => 'tiktok_ads',
                'csv_file' => UploadedFile::fake()->createWithContent('x.csv', "Campaign,Impressions\nA,100\n"),
                'currency_code' => 'TWD',
            ])
            ->assertSessionHasErrors('csv_file');
        $this->post(route('clients.ad-imports.store', $client), [
            'platform' => 'snapchat',
            'csv_file' => UploadedFile::fake()->createWithContent('x.csv', "Date,Cost\n2026-11-01,1\n"),
            'currency_code' => 'TWD',
        ])->assertSessionHasErrors('platform');

        $this->actingAs($member)
            ->delete(route('clients.ad-imports.destroy', $client), ['platform' => 'line_ads', 'from' => '2026-11-01', 'to' => '2026-11-30'])
            ->assertForbidden();
        $this->actingAs(User::factory()->create())
            ->get(route('clients.ad-imports.index', $client))
            ->assertNotFound();

        $this->actingAs($owner)
            ->delete(route('clients.ad-imports.destroy', $client), ['platform' => 'line_ads', 'from' => '2026-11-01', 'to' => '2026-11-30'])
            ->assertSessionHas('status', '已刪除 1 筆LINE 廣告數據。');
        $this->assertDatabaseCount('client_ad_platform_daily_metrics', 0);
    }
}
