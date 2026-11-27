<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientMonthlyBudget;
use App\Models\GoogleAdsConnection;
use App\Models\MetaMarketingClientAccount;
use App\Models\MetaMarketingConnection;
use App\Models\SocialEngagementTarget;
use App\Models\SocialEngagementTargetGoogleAdsReport;
use App\Models\SocialEngagementTargetMetaMarketingReport;
use App\Models\User;
use App\Models\WorkspaceMembership;
use App\Services\ClientBudgetPacing;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientBudgetPacingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-11 04:00:00');
    }

    public function test_budget_page_shows_pacing_projection_and_platform_split(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);
        $client = $owner->clients()->create(['name' => '預算客戶']);
        $campaign = $this->createCampaign($client);
        $this->createGoogleAdsReport($campaign, $owner, ['2026-11-02' => '3000', '2026-11-09' => '3000', '2026-10-31' => '9999']);
        $this->createMetaReport($campaign, $owner, $client, ['2026-11-05' => '6000']);
        ClientMonthlyBudget::factory()->create([
            'client_id' => $client->id,
            'month' => '2026-11',
            'currency_code' => 'TWD',
            'total_budget' => '30000',
            'google_ads_budget' => '10000',
            'meta_marketing_budget' => '20000',
        ]);

        $response = $this->get(route('clients.budgets.index', $client))
            ->assertOk()
            ->assertSee('預算客戶｜月預算')
            ->assertSee('消耗過快');

        $pacing = $response->viewData('pacing');
        $this->assertSame(10, $pacing['elapsed_days']);
        $this->assertSame(20, $pacing['remaining_days']);
        $this->assertSame('2026-11-10', $pacing['data_through']);
        $this->assertSame(12000.0, $pacing['total']['spend'], 'October spend is excluded.');
        $this->assertSame(10000.0, $pacing['total']['expected_spend']);
        $this->assertSame(120.0, $pacing['total']['pace_percent']);
        $this->assertSame(36000.0, $pacing['total']['projected_spend']);
        $this->assertSame(900.0, $pacing['total']['suggested_daily_spend']);
        $this->assertSame(ClientBudgetPacing::STATUS_OVER_PACE, $pacing['total']['status']);
        $this->assertSame(ClientBudgetPacing::STATUS_OVER_PACE, $pacing['platforms']['google_ads']['status']);
        $this->assertSame(90.0, $pacing['platforms']['meta_marketing']['pace_percent']);
        $this->assertSame(ClientBudgetPacing::STATUS_ON_TRACK, $pacing['platforms']['meta_marketing']['status']);
    }

    public function test_owner_can_save_update_and_delete_a_month_budget(): void
    {
        $owner = User::factory()->create();
        $client = $owner->clients()->create(['name' => '預算客戶']);

        $this->actingAs($owner)
            ->post(route('clients.budgets.store', $client), [
                'month' => '2026-12',
                'currency_code' => 'twd',
                'total_budget' => '50000',
                'google_ads_budget' => '',
                'meta_marketing_budget' => '20000',
                'note' => '年終檔期',
            ])
            ->assertRedirect(route('clients.budgets.index', ['client' => $client, 'month' => '2026-12']));

        $this->post(route('clients.budgets.store', $client), [
            'month' => '2026-12',
            'currency_code' => 'TWD',
            'total_budget' => '60000',
        ]);

        $budget = $client->monthlyBudgets()->sole();
        $this->assertSame('TWD', $budget->currency_code);
        $this->assertSame('60000.00', $budget->total_budget);
        $this->assertNull($budget->meta_marketing_budget);

        $this->get(route('clients.budgets.index', ['client' => $client, 'month' => '2026-12']))
            ->assertOk()
            ->assertSee('尚未開始', false)
            ->assertViewHas('pacing', fn (array $pacing): bool => $pacing['elapsed_days'] === 0 && $pacing['total']['status'] === ClientBudgetPacing::STATUS_NOT_STARTED);

        $this->delete(route('clients.budgets.destroy', [$client, $budget]))
            ->assertRedirect(route('clients.budgets.index', ['client' => $client, 'month' => '2026-12']));
        $this->assertDatabaseCount('client_monthly_budgets', 0);
    }

    public function test_invalid_budgets_are_rejected(): void
    {
        $owner = User::factory()->create();
        $client = $owner->clients()->create(['name' => '預算客戶']);

        $this->actingAs($owner)
            ->post(route('clients.budgets.store', $client), [
                'month' => '2026-11',
                'currency_code' => 'TWD',
                'total_budget' => '1000',
                'google_ads_budget' => '800',
                'meta_marketing_budget' => '800',
            ])
            ->assertSessionHasErrors('total_budget');

        $this->post(route('clients.budgets.store', $client), [
            'month' => '1999-01',
            'currency_code' => 'NT',
            'total_budget' => '-5',
        ])->assertSessionHasErrors(['currency_code', 'total_budget']);

        $this->assertDatabaseCount('client_monthly_budgets', 0);
        $this->get(route('clients.budgets.index', ['client' => $client, 'month' => 'bad']))
            ->assertRedirect(route('clients.budgets.index', $client));
    }

    public function test_read_only_members_cannot_change_budgets_and_other_workspaces_cannot_see_them(): void
    {
        $owner = User::factory()->create();
        $client = $owner->clients()->create(['name' => '預算客戶']);
        $budget = ClientMonthlyBudget::factory()->create(['client_id' => $client->id, 'month' => '2026-11']);
        $member = User::factory()->create();
        WorkspaceMembership::factory()->create([
            'owner_user_id' => $owner->id,
            'member_user_id' => $member->id,
            'role' => WorkspaceMembership::ROLE_READ_ONLY,
            'client_scope' => WorkspaceMembership::CLIENT_SCOPE_ALL,
        ]);

        $this->actingAs($member)
            ->get(route('clients.budgets.index', $client))
            ->assertOk()
            ->assertDontSee('儲存預算');
        $this->post(route('clients.budgets.store', $client), ['month' => '2026-11', 'currency_code' => 'TWD', 'total_budget' => '1'])->assertForbidden();
        $this->delete(route('clients.budgets.destroy', [$client, $budget]))->assertForbidden();

        $this->actingAs(User::factory()->create())
            ->get(route('clients.budgets.index', $client))
            ->assertNotFound();
        $this->assertSame('30000.00', $budget->refresh()->total_budget);
    }

    public function test_spend_in_another_currency_is_reported_separately_and_month_end_completes(): void
    {
        $pacing = app(ClientBudgetPacing::class);
        $budget = ClientMonthlyBudget::factory()->make(['month' => '2026-10', 'currency_code' => 'TWD', 'total_budget' => '1000']);
        $overview = ['currency_groups' => [
            ['currency_code' => 'USD', 'platforms' => [], 'total' => ['spend' => 20.0]],
            ['currency_code' => 'TWD', 'platforms' => ['google_ads' => ['spend' => 1200.0], 'meta_marketing' => ['spend' => 0.0]], 'total' => ['spend' => 1200.0]],
        ]];

        $result = $pacing->pace($budget, $overview, CarbonImmutable::parse('2026-11-11 04:00:00'));

        $this->assertSame(31, $result['elapsed_days']);
        $this->assertSame(0, $result['remaining_days']);
        $this->assertSame(['USD' => 20.0], $result['unmatched_currency_spend']);
        $this->assertSame(ClientBudgetPacing::STATUS_EXHAUSTED, $result['total']['status']);
        $this->assertNull($result['total']['suggested_daily_spend']);
        $this->assertNull($result['platforms']['google_ads']['status'], 'No platform budget was set.');
    }

    private function createCampaign(Client $client): SocialEngagementTarget
    {
        $campaign = SocialEngagementTarget::factory()->create([
            'client_id' => $client->getKey(),
            'tag' => '十一月常態',
            'social_platform' => 'instagram',
            'starts_on' => '2026-10-01',
            'ends_on' => '2026-11-30',
            'google_ads_campaign_id' => 'ads-main',
            'meta_campaign_id' => 'meta-main',
        ]);
        $campaign->forceFill(['user_id' => $client->user_id])->save();

        return $campaign;
    }

    /** @param array<string, string> $spendByDate */
    private function createGoogleAdsReport(SocialEngagementTarget $campaign, User $user, array $spendByDate): void
    {
        $connection = GoogleAdsConnection::factory()->create(['user_id' => $user->getKey(), 'customer_id' => '1234567890', 'currency_code' => 'TWD']);
        $this->mapGoogleAdsConnectionToTargetClient($connection, $campaign);
        $rows = [];

        foreach ($spendByDate as $date => $spend) {
            $rows[] = ['date' => $date, 'spend_amount' => $spend, 'impressions' => 1000, 'clicks' => 10, 'conversions' => '1', 'conversions_value' => '100'];
        }

        SocialEngagementTargetGoogleAdsReport::query()->create([
            'google_ads_connection_id' => $connection->getKey(),
            'social_engagement_target_id' => $campaign->getKey(),
            'customer_id' => $connection->customer_id,
            'campaign_id' => $campaign->google_ads_campaign_id,
            'campaign_name' => $campaign->tag,
            'starts_on' => '2026-10-01',
            'ends_on' => '2026-11-30',
            'spend_amount' => '0',
            'impressions' => 0,
            'clicks' => 0,
            'conversions' => '0',
            'conversions_value' => '0',
            'currency_code' => 'TWD',
            'daily_metrics' => $this->dailyMetrics($rows),
            'synced_at' => now(),
        ]);
    }

    /** @param array<string, string> $spendByDate */
    private function createMetaReport(SocialEngagementTarget $campaign, User $user, Client $client, array $spendByDate): void
    {
        $connection = MetaMarketingConnection::factory()->create([
            'user_id' => $user->getKey(),
            'ad_account_id' => '12345',
            'ad_account_name' => 'Budget Meta account',
            'currency_code' => 'TWD',
        ]);
        MetaMarketingClientAccount::withoutGlobalScopes()->create([
            'meta_marketing_connection_id' => $connection->getKey(),
            'client_id' => $client->getKey(),
            'ad_account_id' => '12345',
            'ad_account_name' => 'Budget Meta account',
            'currency_code' => 'TWD',
        ]);
        $rows = [];

        foreach ($spendByDate as $date => $spend) {
            $rows[] = ['date' => $date, 'spend_amount' => $spend, 'impressions' => 1000, 'reach' => 1000, 'clicks' => 10, 'actions' => [], 'action_values' => []];
        }

        SocialEngagementTargetMetaMarketingReport::query()->create([
            'meta_marketing_connection_id' => $connection->getKey(),
            'social_engagement_target_id' => $campaign->getKey(),
            'ad_account_id' => $connection->ad_account_id,
            'campaign_id' => $campaign->meta_campaign_id,
            'campaign_name' => $campaign->tag,
            'starts_on' => '2026-10-01',
            'ends_on' => '2026-11-30',
            'spend_amount' => '0',
            'impressions' => 0,
            'reach' => 0,
            'clicks' => 0,
            'actions' => [],
            'action_values' => [],
            'currency_code' => 'TWD',
            'daily_metrics' => $this->dailyMetrics($rows),
            'synced_at' => now(),
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function dailyMetrics(array $rows): array
    {
        return [
            'status' => 'complete',
            'requested_start_on' => '2026-10-01',
            'requested_end_on' => '2026-11-30',
            'coverage_start_on' => '2026-10-01',
            'coverage_end_on' => '2026-11-10',
            'rows' => $rows,
        ];
    }
}
