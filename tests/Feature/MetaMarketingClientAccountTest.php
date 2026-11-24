<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\MetaMarketingClientAccount;
use App\Models\MetaMarketingConnection;
use App\Models\SocialEngagementTarget;
use App\Models\SocialEngagementTargetMetaMarketingReport;
use App\Models\SocialEngagementTargetPaidAdSnapshot;
use App\Models\User;
use App\Services\MetaMarketingClientAccountResolver;
use App\Services\PaidMediaCampaignTrendReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MetaMarketingClientAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_meta_accounts_and_paid_media_trends_stay_with_the_target_client(): void
    {
        $user = User::factory()->create();
        $clientA = $user->clients()->create(['name' => '客戶 A']);
        $clientB = $user->clients()->create(['name' => '客戶 B']);
        $connection = MetaMarketingConnection::query()->create([
            'user_id' => $user->id,
            'access_token' => 'authorized-token',
            'ad_account_id' => '111111111',
            'ad_account_name' => 'Legacy account',
            'currency_code' => 'TWD',
        ]);
        $accountA = MetaMarketingClientAccount::query()->create([
            'meta_marketing_connection_id' => $connection->id,
            'client_id' => $clientA->id,
            'ad_account_id' => '111111111',
            'ad_account_name' => '客戶 A 廣告帳戶',
            'currency_code' => 'TWD',
        ]);
        $accountB = MetaMarketingClientAccount::query()->create([
            'meta_marketing_connection_id' => $connection->id,
            'client_id' => $clientB->id,
            'ad_account_id' => '222222222',
            'ad_account_name' => '客戶 B 廣告帳戶',
            'currency_code' => 'USD',
        ]);
        $target = $this->createTarget($user, $clientB);
        $syncedAt = now()->startOfSecond();

        $this->createSnapshot($target, $connection, '111111111', 111, $syncedAt);
        $this->createSnapshot($target, $connection, '222222222', 222, $syncedAt->copy()->addSecond());

        $resolver = app(MetaMarketingClientAccountResolver::class);
        $this->assertSame($accountA->id, $resolver->forClient($connection, $clientA)?->id);
        $this->assertSame($accountB->id, $resolver->forClient($connection, $clientB)?->id);
        $changes = app(PaidMediaCampaignTrendReport::class)->metaMarketingChanges($connection, $target);

        $this->assertCount(1, $changes);
        $this->assertSame(222, $changes[0]['impressions']);
    }

    public function test_manual_sync_uses_the_target_clients_meta_account_and_saves_that_identity(): void
    {
        Http::fake([
            'https://graph.facebook.com/v27.0/act_222222222/insights*' => Http::sequence()
                ->push(['data' => [[
                    'account_id' => '222222222',
                    'campaign_id' => '333333333',
                    'campaign_name' => 'Client B campaign',
                    'spend' => '25.50',
                    'impressions' => '1200',
                    'reach' => '900',
                    'clicks' => '48',
                    'account_currency' => 'USD',
                ]]])
                ->push(['data' => []])
                ->push(['data' => []])
                ->push(['data' => []])
                ->push(['data' => []])
                ->push(['data' => []]),
        ]);

        $user = User::factory()->create();
        $clientA = $user->clients()->create(['name' => '客戶 A']);
        $clientB = $user->clients()->create(['name' => '客戶 B']);
        $connection = MetaMarketingConnection::query()->create([
            'user_id' => $user->id,
            'access_token' => 'authorized-token',
        ]);
        MetaMarketingClientAccount::query()->create([
            'meta_marketing_connection_id' => $connection->id,
            'client_id' => $clientA->id,
            'ad_account_id' => '111111111',
            'ad_account_name' => '客戶 A 廣告帳戶',
            'currency_code' => 'TWD',
        ]);
        MetaMarketingClientAccount::query()->create([
            'meta_marketing_connection_id' => $connection->id,
            'client_id' => $clientB->id,
            'ad_account_id' => '222222222',
            'ad_account_name' => '客戶 B 廣告帳戶',
            'currency_code' => 'USD',
        ]);
        $target = $this->createTarget($user, $clientB);

        $this->actingAs($user)
            ->post(route('social-engagement-targets.meta-marketing.sync', $target))
            ->assertRedirect(route('social-engagement-targets.report', $target))
            ->assertSessionHas('status', 'Meta Ads 活動成效已同步。');

        $report = SocialEngagementTargetMetaMarketingReport::query()
            ->where('social_engagement_target_id', $target->id)
            ->sole();
        $snapshot = SocialEngagementTargetPaidAdSnapshot::query()
            ->where('social_engagement_target_id', $target->id)
            ->sole();

        $this->assertSame('222222222', $report->ad_account_id);
        $this->assertSame('USD', $report->currency_code);
        $this->assertSame('222222222', $snapshot->account_id);
        $this->assertSame('USD', $snapshot->currency_code);
        Http::assertSentCount(6);
        Http::assertSent(fn (ClientRequest $request): bool => str_contains($request->url(), '/act_222222222/insights'));
        Http::assertNotSent(fn (ClientRequest $request): bool => str_contains($request->url(), '/act_111111111/insights'));
    }

    public function test_legacy_meta_account_is_used_only_for_a_single_client(): void
    {
        $user = User::factory()->create();
        $client = $user->clients()->create(['name' => '唯一客戶']);
        $connection = MetaMarketingConnection::query()->create([
            'user_id' => $user->id,
            'access_token' => 'authorized-token',
            'ad_account_id' => '123456789',
            'ad_account_name' => 'Legacy account',
            'currency_code' => 'TWD',
        ]);
        $resolver = app(MetaMarketingClientAccountResolver::class);

        $this->assertSame('123456789', $resolver->forClient($connection, $client)?->ad_account_id);

        $user->clients()->create(['name' => '第二個客戶']);

        $this->assertNull($resolver->forClient($connection, $client));
    }

    public function test_migration_assigns_legacy_meta_account_only_to_unambiguous_clients(): void
    {
        $singleClientUser = User::factory()->create();
        $singleClient = $singleClientUser->clients()->create(['name' => '唯一客戶']);
        $singleClientConnection = MetaMarketingConnection::query()->create([
            'user_id' => $singleClientUser->id,
            'access_token' => 'single-client-token',
            'ad_account_id' => '123456789',
            'ad_account_name' => 'Single account',
            'currency_code' => 'TWD',
        ]);

        $ambiguousUser = User::factory()->create();
        $ambiguousUser->clients()->create(['name' => '客戶 A']);
        $ambiguousUser->clients()->create(['name' => '客戶 B']);
        $ambiguousConnection = MetaMarketingConnection::query()->create([
            'user_id' => $ambiguousUser->id,
            'access_token' => 'ambiguous-token',
            'ad_account_id' => '987654321',
            'ad_account_name' => 'Ambiguous account',
            'currency_code' => 'USD',
        ]);

        $migration = require database_path('migrations/2026_11_24_151000_create_meta_marketing_client_accounts_table.php');
        $migration->down();
        $migration->up();

        $this->assertDatabaseHas('meta_marketing_client_accounts', [
            'meta_marketing_connection_id' => $singleClientConnection->id,
            'client_id' => $singleClient->id,
            'ad_account_id' => '123456789',
        ]);
        $this->assertDatabaseMissing('meta_marketing_client_accounts', [
            'meta_marketing_connection_id' => $ambiguousConnection->id,
        ]);
    }

    private function createTarget(User $user, Client $client): SocialEngagementTarget
    {
        return SocialEngagementTarget::factory()->create([
            'user_id' => $user->id,
            'client_id' => $client->id,
            'meta_campaign_id' => '333333333',
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-30',
        ]);
    }

    private function createSnapshot(
        SocialEngagementTarget $target,
        MetaMarketingConnection $connection,
        string $accountId,
        int $impressions,
        Carbon $syncedAt,
    ): void {
        DB::table('social_engagement_target_paid_ad_snapshots')->insert([
            'social_engagement_target_id' => $target->id,
            'meta_marketing_connection_id' => $connection->id,
            'google_ads_connection_id' => null,
            'source' => SocialEngagementTargetPaidAdSnapshot::SOURCE_META_MARKETING,
            'account_id' => $accountId,
            'campaign_id' => '333333333',
            'campaign_name' => 'Test campaign',
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-30',
            'spend_amount' => '100.0000',
            'impressions' => $impressions,
            'reach' => $impressions,
            'clicks' => 10,
            'conversions' => null,
            'conversions_value' => null,
            'actions' => json_encode([]),
            'action_values' => json_encode([]),
            'conversion_actions' => json_encode([]),
            'currency_code' => 'TWD',
            'synced_at' => $syncedAt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
