<?php

namespace Tests\Feature;

use App\Models\MetaMarketingConnection;
use App\Models\SocialEngagementTarget;
use App\Models\SocialEngagementTargetMetaMarketingReport;
use App\Models\User;
use App\Services\MetaMarketingAdSetPerformanceReport;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MetaMarketingAdSetReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_stores_encrypted_ad_set_insights_and_page_exports_formula_safe_csv(): void
    {
        [$user, $target] = $this->syncReport([
            'data' => [
                $this->adSetRow('123450001', 'Retargeting', '125.50', '1200', '900', '48', '2', '250.50'),
                $this->adSetRow('123450002', '=HYPERLINK("https://evil.example","click")', '50', '1000', '800', '20', '1', '65'),
            ],
        ]);

        $report = SocialEngagementTargetMetaMarketingReport::query()->sole();
        $this->assertSame('complete', $report->ad_set_insights['status']);
        $this->assertSame('125.5000', $report->ad_set_insights['rows'][0]['spend_amount']);
        $this->assertSame(900, $report->ad_set_insights['rows'][0]['reach']);
        $this->assertTrue($report->ad_set_insights_expires_at->greaterThan(now()->addDays(29)));
        $this->assertTrue($report->ad_set_insights_expires_at->lessThan(now()->addDays(31)));
        $this->assertArrayNotHasKey('ad_set_insights', $report->toArray());

        $encryptedReport = DB::table('social_engagement_target_meta_marketing_reports')
            ->where('id', $report->getKey())
            ->value('ad_set_insights');
        $this->assertIsString($encryptedReport);
        $this->assertStringNotContainsString('Retargeting', $encryptedReport);

        Http::assertSent(fn (ClientRequest $request): bool => str_starts_with($request->url(), 'https://graph.facebook.com/v27.0/act_123456789/insights')
            && str_contains($request->url(), 'level=adset')
            && $request->hasHeader('Authorization', 'Bearer meta-access-token'));

        $this->actingAs($user)
            ->get(route('social-engagement-targets.report', $target))
            ->assertOk()
            ->assertSee('Meta Ads 廣告組合成效')
            ->assertSee('Retargeting')
            ->assertSee('250.50')
            ->assertSee('125.50')
            ->assertSee('觸及取自 Meta 廣告組合層級')
            ->assertSee(route('social-engagement-targets.meta-marketing.adsets', $target));

        $csvResponse = $this->get(route('social-engagement-targets.meta-marketing.adsets', $target))
            ->assertDownload('meta-ads-ad-set-performance-'.$target->getKey().'.csv');
        $csv = $csvResponse->streamedContent();
        $this->assertStringContainsString('adset_id,adset_name,impressions,reach,clicks,ctr_percent', $csv);
        $this->assertStringContainsString("'=HYPERLINK", $csv);
    }

    public function test_ad_set_insights_failure_does_not_block_campaign_summary(): void
    {
        [$user, $target] = $this->syncReport([], adSetStatus: 403);

        $report = SocialEngagementTargetMetaMarketingReport::query()->sole();
        $this->assertSame('125.5000', $report->spend_amount);
        $this->assertSame('unavailable', $report->ad_set_insights['status']);
        $this->assertSame([], $report->ad_set_insights['rows']);

        $this->actingAs($user)
            ->get(route('social-engagement-targets.report', $target))
            ->assertOk()
            ->assertSee('廣告組合明細暫時無法讀取，其他 Meta Ads 報表仍可使用');
    }

    public function test_ad_set_report_calculates_metrics_sorts_and_hides_expired_data(): void
    {
        $snapshot = [
            'status' => 'complete',
            'window_start_on' => '2026-11-01',
            'window_end_on' => '2026-11-30',
            'currency_code' => 'TWD',
            'truncated' => true,
            'rows' => [
                $this->adSetRow('123450001', 'Retargeting', '100', '1000', '700', '50', '2', '200'),
                $this->adSetRow('123450002', 'Prospecting', '50', '500', '300', '10', '1', '25'),
                [
                    ...$this->adSetRow('123450003', 'No reach', '0', '0', '0', '0', '0', '0'),
                    'reach' => null,
                ],
            ],
        ];
        $builder = new MetaMarketingAdSetPerformanceReport;

        $sortedReport = $builder->build($snapshot, CarbonImmutable::now()->addDay(), 'purchase_roas');
        $this->assertSame('Retargeting', $sortedReport['rows'][0]['adset_name']);
        $this->assertSame(5.0, $sortedReport['rows'][0]['ctr_percent']);
        $this->assertSame(2.0, $sortedReport['rows'][0]['purchase_roas']);
        $this->assertTrue($sortedReport['truncated']);

        $reachSortedReport = $builder->build($snapshot, CarbonImmutable::now()->addDay(), 'reach');
        $this->assertSame('Retargeting', $reachSortedReport['rows'][0]['adset_name']);
        $this->assertSame(null, $reachSortedReport['rows'][2]['reach']);

        $expiredReport = $builder->build($snapshot, CarbonImmutable::now()->subSecond());
        $this->assertSame('expired', $expiredReport['status']);
        $this->assertSame([], $expiredReport['rows']);
    }

    public function test_expired_ad_set_insights_are_removed_by_scheduled_sync(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-23 10:00:00', 'Asia/Taipei'));
        $user = User::factory()->create();
        $target = $this->createTarget([
            'starts_on' => '2026-09-01',
            'ends_on' => '2026-09-30',
        ]);
        $connection = $this->createConnection($user);
        SocialEngagementTargetMetaMarketingReport::query()->create([
            'meta_marketing_connection_id' => $connection->getKey(),
            'social_engagement_target_id' => $target->getKey(),
            'ad_account_id' => $connection->ad_account_id,
            'campaign_id' => $target->meta_campaign_id,
            'campaign_name' => $target->tag,
            'starts_on' => '2026-09-01',
            'ends_on' => '2026-09-30',
            'spend_amount' => '20',
            'impressions' => 100,
            'reach' => 80,
            'clicks' => 10,
            'currency_code' => 'TWD',
            'daily_metrics' => ['status' => 'complete', 'rows' => []],
            'ad_set_insights' => ['status' => 'complete', 'rows' => [['adset_name' => 'private']]],
            'ad_set_insights_expires_at' => now()->subSecond(),
            'synced_at' => now()->subDay(),
        ]);
        Http::preventStrayRequests();

        $this->artisan('meta-marketing:sync-due')->assertExitCode(0);

        $report = SocialEngagementTargetMetaMarketingReport::query()->sole();
        $this->assertNull($report->ad_set_insights);
        $this->assertNull($report->ad_set_insights_expires_at);
        Http::assertNothingSent();
    }

    public function test_ad_set_csv_requires_authentication_and_owned_meta_connection(): void
    {
        $target = $this->createTarget();
        $user = User::factory()->create();
        $connection = $this->createConnection($user);
        $report = SocialEngagementTargetMetaMarketingReport::query()->create([
            'meta_marketing_connection_id' => $connection->getKey(),
            'social_engagement_target_id' => $target->getKey(),
            'ad_account_id' => $connection->ad_account_id,
            'campaign_id' => $target->meta_campaign_id,
            'campaign_name' => $target->tag,
            'starts_on' => $target->starts_on,
            'ends_on' => $target->ends_on,
            'spend_amount' => '125.5000',
            'impressions' => 1200,
            'reach' => 900,
            'clicks' => 48,
            'currency_code' => 'TWD',
            'ad_set_insights' => [
                'status' => 'complete',
                'window_start_on' => '2026-11-01',
                'window_end_on' => '2026-11-30',
                'currency_code' => 'TWD',
                'truncated' => false,
                'rows' => [$this->adSetRow('123450001', 'Retargeting', '125.50', '1200', '900', '48', '2', '250.50')],
            ],
            'ad_set_insights_expires_at' => now()->addDays(30),
            'synced_at' => now(),
        ]);
        Http::preventStrayRequests();

        $this->get(route('social-engagement-targets.meta-marketing.adsets', $target))
            ->assertRedirect(route('login'));

        $otherUser = User::factory()->create();
        $this->createConnection($otherUser);
        $this->actingAs($otherUser)
            ->get(route('social-engagement-targets.meta-marketing.adsets', $target))
            ->assertNotFound();

        $this->actingAs($user)
            ->get(route('social-engagement-targets.meta-marketing.adsets', $target))
            ->assertDownload('meta-ads-ad-set-performance-'.$target->getKey().'.csv');

        $report->update(['ad_set_insights_expires_at' => now()->subSecond()]);
        $this->actingAs($user)
            ->get(route('social-engagement-targets.meta-marketing.adsets', $target))
            ->assertNotFound();

        Http::assertNothingSent();
    }

    /** @param array<string, mixed> $adSetPayload @return array{User, SocialEngagementTarget} */
    private function syncReport(array $adSetPayload, int $adSetStatus = 200): array
    {
        Http::fake(function (ClientRequest $request) use ($adSetPayload, $adSetStatus) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            if (($query['level'] ?? null) === 'adset') {
                return Http::response($adSetPayload, $adSetStatus);
            }

            if (array_key_exists('time_increment', $query)) {
                return Http::response(['data' => []]);
            }

            if (($query['breakdowns'] ?? null) === 'age,gender'
                || ($query['breakdowns'] ?? null) === 'publisher_platform,platform_position') {
                return Http::response(['data' => []]);
            }

            if (($query['level'] ?? null) === 'ad') {
                return Http::response(['data' => []]);
            }

            return Http::response(['data' => [[
                'account_id' => '123456789',
                'campaign_id' => '987654321',
                'campaign_name' => 'Spring launch',
                'spend' => '125.50',
                'impressions' => '1200',
                'reach' => '900',
                'clicks' => '48',
                'actions' => [['action_type' => 'omni_purchase', 'value' => '2']],
                'action_values' => [['action_type' => 'omni_purchase', 'value' => '250.50']],
                'account_currency' => 'TWD',
            ]]]);
        });

        $user = User::factory()->create();
        $target = $this->createTarget();
        $connection = $this->createConnection($user);

        $this->actingAs($user)
            ->post(route('social-engagement-targets.meta-marketing.sync', $target))
            ->assertRedirect(route('social-engagement-targets.report', $target))
            ->assertSessionHas('status', 'Meta Ads 活動成效已同步。');

        return [$user, $target];
    }

    /** @param array<string, mixed> $attributes */
    private function createTarget(array $attributes = []): SocialEngagementTarget
    {
        return SocialEngagementTarget::factory()->create($attributes + [
            'tag' => 'Meta ad set campaign',
            'social_platform' => 'facebook',
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-30',
            'meta_campaign_id' => '987654321',
        ]);
    }

    private function createConnection(User $user): MetaMarketingConnection
    {
        return MetaMarketingConnection::query()->create([
            'user_id' => $user->getKey(),
            'access_token' => 'meta-access-token',
            'ad_account_id' => '123456789',
            'ad_account_name' => 'Meta Test Account',
            'currency_code' => 'TWD',
        ]);
    }

    /** @return array<string, mixed> */
    private function adSetRow(
        string $id,
        string $name,
        string $spend,
        string $impressions,
        ?string $reach,
        string $clicks,
        string $purchases,
        string $purchaseValue,
    ): array {
        return [
            'account_id' => '123456789',
            'campaign_id' => '987654321',
            'adset_id' => $id,
            'adset_name' => $name,
            'spend' => $spend,
            'spend_amount' => $spend,
            'impressions' => $impressions,
            'reach' => $reach,
            'clicks' => $clicks,
            'actions' => [['action_type' => 'omni_purchase', 'value' => $purchases]],
            'action_values' => [['action_type' => 'omni_purchase', 'value' => $purchaseValue]],
            'account_currency' => 'TWD',
        ];
    }
}
