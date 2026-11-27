<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\ClientAdPlatformDailyMetric;
use App\Models\ClientStoreOrder;
use App\Models\User;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientDataApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-10 12:00:00');
    }

    public function test_owner_creates_a_token_that_is_shown_once_and_stored_hashed(): void
    {
        $owner = User::factory()->create();

        $this->actingAs($owner)
            ->post(route('api-tokens.store'), ['name' => 'Looker Studio', 'expires_in' => '90'])
            ->assertRedirect(route('api-tokens.index'))
            ->assertSessionHas('plainTextToken');

        $plainText = session('plainTextToken');
        $token = ApiToken::query()->sole();
        $this->assertStringStartsWith(ApiToken::PREFIX, $plainText);
        $this->assertSame(ApiToken::hash($plainText), $token->token_hash);
        $this->assertSame('2027-02-08', $token->expires_at->toDateString());

        $this->get(route('api-tokens.index'))->assertOk()->assertSee($plainText);
        $this->get(route('api-tokens.index'))->assertOk()->assertSee('Looker Studio')->assertDontSee($plainText);

        $this->delete(route('api-tokens.destroy', $token))->assertRedirect(route('api-tokens.index'));
        $this->assertDatabaseCount('api_tokens', 0);
    }

    public function test_members_cannot_manage_tokens_and_owners_cannot_revoke_other_workspaces_tokens(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        WorkspaceMembership::factory()->create([
            'owner_user_id' => $owner->id,
            'member_user_id' => $member->id,
            'role' => WorkspaceMembership::ROLE_EDITOR,
            'client_scope' => WorkspaceMembership::CLIENT_SCOPE_ALL,
        ]);
        $otherToken = ApiToken::factory()->create();

        $this->actingAs($member)->get(route('api-tokens.index'))->assertForbidden();
        $this->actingAs($owner)->delete(route('api-tokens.destroy', $otherToken))->assertNotFound();
        $this->assertDatabaseCount('api_tokens', 1);
    }

    public function test_api_returns_only_the_token_workspaces_clients(): void
    {
        $owner = User::factory()->create();
        $client = $owner->clients()->create(['name' => 'API 客戶']);
        $otherClient = User::factory()->create()->clients()->create(['name' => '他人客戶']);
        ['plain_text' => $plainText] = ApiToken::issue($owner, 'BI', null);

        $this->getJson(route('api.v1.clients.index'), ['Authorization' => 'Bearer '.$plainText])
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'API 客戶');

        $this->getJson(route('api.v1.clients.summary', $otherClient), ['Authorization' => 'Bearer '.$plainText])
            ->assertNotFound();
        $this->assertNotNull(ApiToken::query()->sole()->last_used_at);
    }

    public function test_summary_and_daily_performance_include_imported_spend_and_store_revenue(): void
    {
        $owner = User::factory()->create();
        $client = $owner->clients()->create(['name' => 'API 客戶']);
        ClientAdPlatformDailyMetric::factory()->create([
            'client_id' => $client->id, 'platform' => 'tiktok_ads', 'metric_on' => '2026-11-05', 'campaign_name' => 'A',
            'spend' => '1000', 'conversions' => '4', 'conversion_value' => '4000',
        ]);
        ClientStoreOrder::factory()->create(['client_id' => $client->id, 'ordered_on' => '2026-11-05', 'revenue' => '3000']);
        ['plain_text' => $plainText] = ApiToken::issue($owner, 'BI', null);
        $headers = ['Authorization' => 'Bearer '.$plainText];

        $this->getJson(route('api.v1.clients.summary', ['client' => $client, 'from' => '2026-11-05', 'to' => '2026-11-06']), $headers)
            ->assertOk()
            ->assertJsonPath('data.paid_media.0.platforms.0.platform', 'tiktok_ads')
            ->assertJsonPath('data.paid_media.0.total.spend', 1000)
            ->assertJsonPath('data.store_revenue.mer', 3)
            ->assertJsonPath('data.previous_period.from', '2026-11-03');

        $this->getJson(route('api.v1.clients.daily-performance', ['client' => $client, 'from' => '2026-11-05', 'to' => '2026-11-06']), $headers)
            ->assertOk()
            ->assertJsonPath('meta.currency_code', 'TWD')
            ->assertJsonPath('data.0.tiktok_ads_spend', 1000)
            ->assertJsonPath('data.0.store_revenue', 3000)
            ->assertJsonPath('data.1.date', '2026-11-06');

        $csv = $this->get(route('api.v1.clients.daily-performance', ['client' => $client, 'from' => '2026-11-05', 'to' => '2026-11-05', 'format' => 'csv']), $headers)
            ->assertOk()
            ->streamedContent();
        $this->assertStringContainsString('date,google_ads_spend,meta_marketing_spend,tiktok_ads_spend,line_ads_spend,conversions,store_revenue,currency_code', $csv);
        $this->assertStringContainsString('2026-11-05,0,0,1000,0,4,3000,TWD', $csv);

        $this->getJson(route('api.v1.clients.summary', ['client' => $client, 'from' => '2026-11-05', 'to' => '2026-11-10']), $headers)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('to');
    }

    public function test_missing_expired_and_revoked_tokens_are_rejected(): void
    {
        $owner = User::factory()->create();
        ['plain_text' => $expired] = ApiToken::issue($owner, 'Old', now()->subDay());

        $this->getJson(route('api.v1.clients.index'))->assertUnauthorized();
        $this->getJson(route('api.v1.clients.index'), ['Authorization' => 'Bearer '.$expired])->assertUnauthorized();
        $this->getJson(route('api.v1.clients.index'), ['Authorization' => 'Bearer mm_not-a-real-token'])->assertUnauthorized();
    }
}
