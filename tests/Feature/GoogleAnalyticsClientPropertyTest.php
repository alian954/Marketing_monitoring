<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\GoogleAnalyticsCampaignSnapshot;
use App\Models\GoogleAnalyticsClientProperty;
use App\Models\GoogleAnalyticsConnection;
use App\Models\SocialEngagementTarget;
use App\Models\SocialEngagementTargetGoogleAnalyticsReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleAnalyticsClientPropertyTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_sync_and_report_reads_use_the_targets_client_property(): void
    {
        config([
            'services.google_analytics.client_id' => 'client-id',
            'services.google_analytics.client_secret' => 'client-secret',
        ]);
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'access-token']),
            'https://analyticsdata.googleapis.com/v1beta/properties/*:runReport' => Http::response([
                'rows' => [],
                'metadata' => ['currencyCode' => 'TWD'],
            ]),
        ]);

        $user = User::factory()->create();
        $clientA = $user->clients()->create(['name' => '客戶 A']);
        $clientB = $user->clients()->create(['name' => '客戶 B']);
        $connection = GoogleAnalyticsConnection::query()->create([
            'user_id' => $user->id,
            'refresh_token' => 'refresh-token',
        ]);
        GoogleAnalyticsClientProperty::query()->create([
            'google_analytics_connection_id' => $connection->id,
            'client_id' => $clientA->id,
            'property_id' => '111111111',
        ]);
        GoogleAnalyticsClientProperty::query()->create([
            'google_analytics_connection_id' => $connection->id,
            'client_id' => $clientB->id,
            'property_id' => '222222222',
        ]);
        $targetA = $this->createTarget($user, $clientA, 'Client A campaign');
        $targetB = $this->createTarget($user, $clientB, 'Client B campaign');

        $this->actingAs($user)
            ->post(route('social-engagement-targets.google-analytics.sync', $targetB))
            ->assertRedirect(route('social-engagement-targets.report', $targetB))
            ->assertSessionHas('status', 'GA4 活動成效已同步。');

        $report = SocialEngagementTargetGoogleAnalyticsReport::query()->where('social_engagement_target_id', $targetB->id)->sole();
        $snapshot = GoogleAnalyticsCampaignSnapshot::query()->where('social_engagement_target_id', $targetB->id)->sole();
        $this->assertSame('222222222', $report->property_id);
        $this->assertSame('222222222', $snapshot->property_id);
        Http::assertSent(fn (ClientRequest $request): bool => str_contains($request->url(), '/properties/222222222:runReport'));
        Http::assertNotSent(fn (ClientRequest $request): bool => str_contains($request->url(), '/properties/111111111:runReport'));

        SocialEngagementTargetGoogleAnalyticsReport::query()->create([
            'google_analytics_connection_id' => $connection->id,
            'property_id' => '111111111',
            'social_engagement_target_id' => $targetA->id,
            'campaign_name' => $targetA->googleAnalyticsCampaignName(),
            'attribution_mode' => 'manual_utm',
            'starts_on' => $targetA->starts_on,
            'ends_on' => $targetA->ends_on,
            'sessions' => 111,
            'engaged_sessions' => 80,
            'key_events' => '1.0000',
            'total_revenue' => '100.0000',
            'synced_at' => now(),
        ]);

        $reportResponse = $this->get(route('social-engagement-targets.report', $targetB))->assertOk();
        $this->assertSame('222222222', $reportResponse->viewData('googleAnalyticsReport')->property_id);
        $this->assertSame($targetB->id, $reportResponse->viewData('googleAnalyticsReport')->social_engagement_target_id);

        $clientBProperty = GoogleAnalyticsClientProperty::query()
            ->where('google_analytics_connection_id', $connection->id)
            ->where('client_id', $clientB->id)
            ->firstOrFail();
        $clientBProperty->property_id = '333333333';
        $clientBProperty->save();

        $changedPropertyResponse = $this->get(route('social-engagement-targets.report', $targetB))->assertOk();
        $this->assertNull($changedPropertyResponse->viewData('googleAnalyticsReport'));
    }

    public function test_manual_sync_skips_an_unconfigured_client_without_calling_google(): void
    {
        Http::preventStrayRequests();
        $user = User::factory()->create();
        $clientA = $user->clients()->create(['name' => '已設定客戶']);
        $clientB = $user->clients()->create(['name' => '待設定客戶']);
        $connection = GoogleAnalyticsConnection::query()->create([
            'user_id' => $user->id,
            'refresh_token' => 'refresh-token',
        ]);
        GoogleAnalyticsClientProperty::query()->create([
            'google_analytics_connection_id' => $connection->id,
            'client_id' => $clientA->id,
            'property_id' => '111111111',
        ]);
        $target = $this->createTarget($user, $clientB, 'Unconfigured campaign');

        $this->actingAs($user)
            ->post(route('social-engagement-targets.google-analytics.sync', $target))
            ->assertRedirect(route('social-engagement-targets.report', $target))
            ->assertSessionHasErrors(['google_analytics' => '請先在 GA4 整合設定中為此客戶填入資源 ID。']);

        Http::assertNothingSent();
        $this->assertDatabaseMissing('social_engagement_target_google_analytics_reports', [
            'social_engagement_target_id' => $target->id,
        ]);
    }

    public function test_legacy_property_migrates_only_when_one_client_owns_it(): void
    {
        [$user, $client, $connection, $target] = $this->createLegacyReportScenario();
        $migration = require database_path('migrations/2026_11_24_143203_add_property_identity_to_google_analytics_campaign_tables.php');
        $migration->down();
        $migration->up();

        $this->assertDatabaseHas('google_analytics_client_properties', [
            'google_analytics_connection_id' => $connection->id,
            'client_id' => $client->id,
            'property_id' => '123456789',
        ]);
        $this->assertDatabaseHas('social_engagement_target_google_analytics_reports', [
            'social_engagement_target_id' => $target->id,
            'property_id' => '123456789',
        ]);
        $this->assertDatabaseHas('google_analytics_campaign_snapshots', [
            'social_engagement_target_id' => $target->id,
            'property_id' => '123456789',
        ]);
    }

    public function test_legacy_property_and_reports_remain_unassigned_when_client_is_ambiguous(): void
    {
        [$user, , $connection, $target] = $this->createLegacyReportScenario();
        $user->clients()->create(['name' => '第二個客戶']);
        $migration = require database_path('migrations/2026_11_24_143203_add_property_identity_to_google_analytics_campaign_tables.php');
        $migration->down();
        $migration->up();

        $this->assertDatabaseMissing('google_analytics_client_properties', [
            'google_analytics_connection_id' => $connection->id,
        ]);
        $this->assertDatabaseHas('social_engagement_target_google_analytics_reports', [
            'social_engagement_target_id' => $target->id,
            'property_id' => null,
        ]);
        $this->assertDatabaseHas('google_analytics_campaign_snapshots', [
            'social_engagement_target_id' => $target->id,
            'property_id' => null,
        ]);
    }

    /** @return array{User, Client, GoogleAnalyticsConnection, SocialEngagementTarget} */
    private function createLegacyReportScenario(): array
    {
        $user = User::factory()->create();
        $client = $user->clients()->create(['name' => '唯一客戶']);
        $connection = GoogleAnalyticsConnection::query()->create([
            'user_id' => $user->id,
            'property_id' => '123456789',
            'refresh_token' => 'refresh-token',
        ]);
        $target = SocialEngagementTarget::factory()->create([
            'user_id' => $user->id,
            'client_id' => $client->id,
            'tag' => 'Legacy campaign',
            'social_platform' => 'facebook',
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-30',
        ]);
        $timestamp = now();
        DB::table('social_engagement_target_google_analytics_reports')->insert([
            'google_analytics_connection_id' => $connection->id,
            'social_engagement_target_id' => $target->id,
            'campaign_name' => $target->tag,
            'starts_on' => $target->starts_on->toDateString(),
            'ends_on' => $target->ends_on->toDateString(),
            'sessions' => 10,
            'engaged_sessions' => 8,
            'key_events' => '1.0000',
            'total_revenue' => '100.0000',
            'synced_at' => $timestamp,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
        DB::table('google_analytics_campaign_snapshots')->insert([
            'google_analytics_connection_id' => $connection->id,
            'social_engagement_target_id' => $target->id,
            'snapshot_date' => $timestamp->toDateString(),
            'campaign_name' => $target->tag,
            'starts_on' => $target->starts_on->toDateString(),
            'ends_on' => $target->ends_on->toDateString(),
            'sessions' => 10,
            'engaged_sessions' => 8,
            'key_events' => '1.0000',
            'total_revenue' => '100.0000',
            'synced_at' => $timestamp,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return [$user, $client, $connection, $target];
    }

    private function createTarget(User $user, Client $client, string $tag): SocialEngagementTarget
    {
        return SocialEngagementTarget::factory()->create([
            'user_id' => $user->id,
            'client_id' => $client->id,
            'tag' => $tag,
            'social_platform' => 'facebook',
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-30',
        ]);
    }
}
