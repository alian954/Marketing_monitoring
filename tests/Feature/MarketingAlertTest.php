<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\MarketingAlertEvent;
use App\Models\MarketingAlertRule;
use App\Models\MetaMarketingConnection;
use App\Models\NotificationOutbox;
use App\Models\Organization;
use App\Models\SocialEngagementTarget;
use App\Models\SocialEngagementTargetGoogleAdsReport;
use App\Models\SocialEngagementTargetMetaMarketingReport;
use App\Models\User;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MarketingAlertTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->travelTo(Carbon::parse('2026-11-27 07:23:00', 'Asia/Taipei'));
    }

    public function test_owner_can_create_rules_with_default_thresholds_and_encrypted_destinations(): void
    {
        ['owner' => $owner, 'client' => $client] = $this->createClientWithTarget();
        $webhookUrl = 'https://hooks.slack.com/services/T000/B000/secret';

        $this->actingAs($owner)
            ->post(route('clients.marketing-alerts.store', $client), [
                'metric' => MarketingAlertRule::METRIC_SPEND_SPIKE,
                'threshold' => '',
                'recipients' => 'ads@example.com, ads@example.com',
                'webhook_url' => $webhookUrl,
            ])
            ->assertRedirect(route('clients.marketing-alerts.index', $client));

        $this->post(route('clients.marketing-alerts.store', $client), [
            'metric' => MarketingAlertRule::METRIC_SYNC_STALE,
            'threshold' => '999',
            'recipients' => 'ads@example.com',
        ]);

        [$spikeRule, $staleRule] = $client->marketingAlertRules()->orderBy('id')->get()->all();
        $this->assertSame('50.0000', $spikeRule->threshold);
        $this->assertSame(['ads@example.com'], $spikeRule->recipientList());
        $this->assertSame(NotificationOutbox::CHANNEL_SLACK, $spikeRule->webhook_channel);
        $this->assertNull($staleRule->threshold);
        $this->assertStringNotContainsString('hooks.slack.com', (string) DB::table('marketing_alert_rules')->where('id', $spikeRule->id)->value('webhook_url'));

        $this->get(route('clients.marketing-alerts.index', $client))
            ->assertOk()
            ->assertSee('單日花費暴增')
            ->assertSee('Slack')
            ->assertDontSee($webhookUrl);
    }

    public function test_rule_validation_rejects_missing_destinations_untrusted_webhooks_and_missing_cpa_threshold(): void
    {
        ['owner' => $owner, 'client' => $client] = $this->createClientWithTarget();

        $this->actingAs($owner)
            ->post(route('clients.marketing-alerts.store', $client), [
                'metric' => MarketingAlertRule::METRIC_ROAS_BELOW,
                'threshold' => '1.5',
            ])
            ->assertSessionHasErrors('recipients');

        $this->post(route('clients.marketing-alerts.store', $client), [
            'metric' => MarketingAlertRule::METRIC_ROAS_BELOW,
            'threshold' => '1.5',
            'webhook_url' => 'https://attacker.example.com/hook',
        ])->assertSessionHasErrors('webhook_url');

        $this->post(route('clients.marketing-alerts.store', $client), [
            'metric' => MarketingAlertRule::METRIC_CPA_ABOVE,
            'recipients' => 'ads@example.com',
        ])->assertSessionHasErrors('threshold');

        $this->assertDatabaseCount('marketing_alert_rules', 0);
    }

    public function test_read_only_members_can_view_but_not_manage_rules_and_other_clients_cannot_be_targeted(): void
    {
        ['owner' => $owner, 'client' => $client] = $this->createClientWithTarget();
        $rule = MarketingAlertRule::factory()->create(['client_id' => $client->id]);
        $otherClient = Client::factory()->for($owner)->create();
        $member = User::factory()->create();
        WorkspaceMembership::factory()->create([
            'owner_user_id' => $owner->id,
            'member_user_id' => $member->id,
            'role' => WorkspaceMembership::ROLE_READ_ONLY,
            'client_scope' => WorkspaceMembership::CLIENT_SCOPE_ALL,
        ]);

        $this->actingAs($owner)
            ->delete(route('clients.marketing-alerts.destroy', [$otherClient, $rule]))
            ->assertNotFound();

        $this->actingAs($member)
            ->get(route('clients.marketing-alerts.index', $client))
            ->assertOk()
            ->assertSee('ROAS 低於門檻')
            ->assertDontSee('建立警示規則');
        $this->patch(route('clients.marketing-alerts.update', [$client, $rule]), ['paused' => '1'])->assertForbidden();
        $this->delete(route('clients.marketing-alerts.destroy', [$client, $rule]))->assertForbidden();

        $this->assertNull($rule->refresh()->paused_at);
    }

    public function test_spend_spike_and_low_roas_alerts_are_sent_once_through_the_outbox(): void
    {
        ['client' => $client, 'target' => $target] = $this->createClientWithTarget();
        $this->createGoogleAdsReport($target, [
            '2026-11-19' => ['spend' => 1000, 'conversions' => 2, 'value' => 3000],
            '2026-11-20' => ['spend' => 1000, 'conversions' => 2, 'value' => 3000],
            '2026-11-21' => ['spend' => 1000, 'conversions' => 2, 'value' => 3000],
            '2026-11-26' => ['spend' => 3000, 'conversions' => 1, 'value' => 1500],
        ]);
        $spikeRule = MarketingAlertRule::factory()->metric(MarketingAlertRule::METRIC_SPEND_SPIKE, '50')->create([
            'client_id' => $client->id,
            'recipients' => "ads@example.com\nlead@example.com",
        ]);
        $roasRule = MarketingAlertRule::factory()->metric(MarketingAlertRule::METRIC_ROAS_BELOW, '1')->create([
            'client_id' => $client->id,
            'recipients' => 'ads@example.com',
        ]);

        $this->artisan('marketing-alerts:evaluate')->assertExitCode(0);

        $spikeEvent = $spikeRule->events()->sole();
        $this->assertSame('2026-11-26', $spikeEvent->observed_on->toDateString());
        $this->assertSame('google_ads', $spikeEvent->source);
        $this->assertStringContainsString('TWD 3,000.00', $spikeEvent->message);
        $this->assertStringContainsString('高 200%', $spikeEvent->message);
        $roasEvent = $roasRule->events()->sole();
        $this->assertStringContainsString('ROAS 0.50 倍', $roasEvent->message);

        $outbox = NotificationOutbox::query()->withoutGlobalScopes()->where('event_type', NotificationOutbox::EVENT_TYPE_MARKETING_ALERT)->get();
        $this->assertCount(3, $outbox);
        $this->assertSame($client->user_id, $outbox->first()->user_id);
        $this->assertTrue($outbox->every(static fn (NotificationOutbox $item): bool => $item->status === 'sent'));
        $this->assertStringContainsString('單日花費暴增', $outbox->first()->subject);
        $this->assertNotNull($spikeRule->refresh()->last_triggered_at);

        $this->artisan('marketing-alerts:evaluate')->assertExitCode(0);

        $this->assertDatabaseCount('marketing_alert_events', 2);
        $this->assertSame(3, NotificationOutbox::query()->withoutGlobalScopes()->count());
    }

    public function test_spend_spike_needs_enough_history_and_cpa_alert_uses_threshold(): void
    {
        ['client' => $client, 'target' => $target] = $this->createClientWithTarget();
        $this->createGoogleAdsReport($target, [
            '2026-11-24' => ['spend' => 100, 'conversions' => 1, 'value' => 500],
            '2026-11-25' => ['spend' => 100, 'conversions' => 1, 'value' => 500],
            '2026-11-26' => ['spend' => 900, 'conversions' => 2, 'value' => 3000],
        ]);
        MarketingAlertRule::factory()->metric(MarketingAlertRule::METRIC_SPEND_SPIKE, '50')->create(['client_id' => $client->id]);
        $cpaRule = MarketingAlertRule::factory()->metric(MarketingAlertRule::METRIC_CPA_ABOVE, '400')->create(['client_id' => $client->id]);

        $this->artisan('marketing-alerts:evaluate')->assertExitCode(0);

        $this->assertSame([$cpaRule->id], MarketingAlertEvent::query()->pluck('marketing_alert_rule_id')->all());
        $this->assertStringContainsString('CPA TWD 450.00', $cpaRule->events()->sole()->message);
    }

    public function test_meta_conversions_stopping_is_detected(): void
    {
        ['owner' => $owner, 'client' => $client, 'target' => $target] = $this->createClientWithTarget(metaCampaignId: '120000000000000001');
        $connection = MetaMarketingConnection::query()->create([
            'user_id' => $owner->id,
            'access_token' => 'meta-access-token',
            'ad_account_id' => '123456789',
            'ad_account_name' => 'Example Ads',
        ]);
        $rows = [];

        foreach (['2026-11-22' => 2, '2026-11-23' => 1, '2026-11-24' => 1, '2026-11-26' => 0] as $date => $purchases) {
            $rows[] = [
                'date' => $date,
                'spend_amount' => '800',
                'impressions' => 10000,
                'reach' => 8000,
                'clicks' => 200,
                'actions' => $purchases === 0 ? [] : [['action_type' => 'omni_purchase', 'value' => (string) $purchases]],
                'action_values' => [],
            ];
        }

        SocialEngagementTargetMetaMarketingReport::query()->create([
            'meta_marketing_connection_id' => $connection->id,
            'social_engagement_target_id' => $target->id,
            'ad_account_id' => '123456789',
            'campaign_id' => $target->meta_campaign_id,
            'campaign_name' => $target->tag,
            'starts_on' => $target->starts_on,
            'ends_on' => $target->ends_on,
            'spend_amount' => '3200',
            'impressions' => 40000,
            'reach' => 30000,
            'clicks' => 800,
            'currency_code' => 'TWD',
            'daily_metrics' => ['status' => 'complete', 'rows' => $rows],
            'synced_at' => now(),
        ]);
        $rule = MarketingAlertRule::factory()->metric(MarketingAlertRule::METRIC_CONVERSIONS_STOPPED)->create(['client_id' => $client->id]);

        $this->artisan('marketing-alerts:evaluate')->assertExitCode(0);

        $event = $rule->events()->sole();
        $this->assertSame('meta_marketing', $event->source);
        $this->assertStringContainsString('沒有轉換，前 7 日共有 4 次轉換', $event->message);
    }

    public function test_budget_overpace_alerts_once_per_campaign(): void
    {
        ['client' => $client, 'target' => $target] = $this->createClientWithTarget();
        $target->forceFill(['campaign_budget_amount' => '10000', 'campaign_budget_currency' => 'TWD'])->save();
        $this->createGoogleAdsReport($target, [
            '2026-11-01' => ['spend' => 3000, 'conversions' => 1, 'value' => 100],
            '2026-11-02' => ['spend' => 3000, 'conversions' => 1, 'value' => 100],
        ]);
        $rule = MarketingAlertRule::factory()->metric(MarketingAlertRule::METRIC_BUDGET_OVERPACE, '10')->create(['client_id' => $client->id]);

        $this->artisan('marketing-alerts:evaluate')->assertExitCode(0);
        $this->travelTo(Carbon::parse('2026-11-28 07:23:00', 'Asia/Taipei'));
        $this->artisan('marketing-alerts:evaluate')->assertExitCode(0);

        $event = $rule->events()->sole();
        $this->assertSame('budget', $event->source);
        $this->assertSame('2026-12-31', $event->observed_on->toDateString());
        $this->assertStringContainsString('已達預算 TWD 10,000.00 的 60%', $event->message);
    }

    public function test_stale_sync_alerts_and_paused_or_inaccessible_rules_are_skipped(): void
    {
        ['client' => $client, 'target' => $target] = $this->createClientWithTarget();
        $report = $this->createGoogleAdsReport($target, ['2026-11-20' => ['spend' => 100, 'conversions' => 1, 'value' => 100]]);
        $report->forceFill(['synced_at' => now()->subHours(40)])->save();
        $rule = MarketingAlertRule::factory()->metric(MarketingAlertRule::METRIC_SYNC_STALE)->create(['client_id' => $client->id]);
        MarketingAlertRule::factory()->metric(MarketingAlertRule::METRIC_SYNC_STALE)->create([
            'client_id' => $client->id,
            'paused_at' => now(),
        ]);
        ['owner' => $disabledOwner, 'client' => $disabledClient, 'target' => $disabledTarget] = $this->createClientWithTarget();
        $this->createGoogleAdsReport($disabledTarget, [])->forceFill(['synced_at' => now()->subDays(5)])->save();
        $disabledRule = MarketingAlertRule::factory()->metric(MarketingAlertRule::METRIC_SYNC_STALE)->create(['client_id' => $disabledClient->id]);
        Organization::query()->updateOrCreate(
            ['owner_user_id' => $disabledOwner->id],
            ['name' => '停用組織', 'is_enabled' => false],
        );

        $this->artisan('marketing-alerts:evaluate')->assertExitCode(0);

        $this->assertStringContainsString('已超過 36 小時未更新', $rule->events()->sole()->message);
        $this->assertDatabaseCount('marketing_alert_events', 1);
        $this->assertSame(0, $disabledRule->events()->count());
    }

    public function test_anomaly_rule_flags_metrics_outside_the_campaigns_normal_range(): void
    {
        ['client' => $client, 'target' => $target] = $this->createClientWithTarget();
        $days = [];

        for ($day = 12; $day <= 25; $day++) {
            $days['2026-11-'.$day] = ['spend' => $day % 2 === 0 ? 1000 : 1080, 'conversions' => $day % 2 === 0 ? 2 : 3, 'value' => 3000];
        }

        $days['2026-11-26'] = ['spend' => 1040, 'conversions' => 0, 'value' => 0];
        $this->createGoogleAdsReport($target, $days);
        $rule = MarketingAlertRule::factory()->metric(MarketingAlertRule::METRIC_ANOMALY, '3')->create(['client_id' => $client->id]);

        $this->artisan('marketing-alerts:evaluate')->assertExitCode(0);

        $event = $rule->events()->sole();
        $this->assertSame('2026-11-26', $event->observed_on->toDateString());
        $this->assertStringContainsString('數據偏離平常範圍', $event->message);
        $this->assertStringContainsString('轉換 0.0（前 14 日平均 2.5，偏低', $event->message);
        $this->assertStringNotContainsString('花費', $event->message, 'Spend stayed inside its usual range.');
        $this->assertStringNotContainsString('CTR', $event->message, 'A flat CTR that did not move is not anomalous.');
    }

    public function test_anomaly_rule_needs_a_week_of_history(): void
    {
        ['client' => $client, 'target' => $target] = $this->createClientWithTarget();
        $this->createGoogleAdsReport($target, [
            '2026-11-22' => ['spend' => 100, 'conversions' => 2, 'value' => 300],
            '2026-11-23' => ['spend' => 110, 'conversions' => 3, 'value' => 300],
            '2026-11-24' => ['spend' => 100, 'conversions' => 2, 'value' => 300],
            '2026-11-25' => ['spend' => 110, 'conversions' => 3, 'value' => 300],
            '2026-11-26' => ['spend' => 5000, 'conversions' => 0, 'value' => 0],
        ]);
        MarketingAlertRule::factory()->metric(MarketingAlertRule::METRIC_ANOMALY, '3')->create(['client_id' => $client->id]);

        $this->artisan('marketing-alerts:evaluate')->assertExitCode(0);

        $this->assertDatabaseCount('marketing_alert_events', 0);
    }

    public function test_owner_can_mark_alerts_handled_and_muted_alerts_are_recorded_without_notifying(): void
    {
        ['owner' => $owner, 'client' => $client, 'target' => $target] = $this->createClientWithTarget();
        $this->createGoogleAdsReport($target, [
            '2026-11-26' => ['spend' => 1000, 'conversions' => 1, 'value' => 500],
            '2026-11-27' => ['spend' => 1000, 'conversions' => 1, 'value' => 500],
            '2026-11-28' => ['spend' => 1000, 'conversions' => 1, 'value' => 500],
        ]);
        $rule = MarketingAlertRule::factory()->metric(MarketingAlertRule::METRIC_ROAS_BELOW, '1')->create([
            'client_id' => $client->id,
            'recipients' => 'ads@example.com',
        ]);

        $this->artisan('marketing-alerts:evaluate')->assertExitCode(0);
        $firstEvent = $rule->events()->sole();
        $this->assertSame(1, NotificationOutbox::query()->withoutGlobalScopes()->count());

        $this->actingAs($owner)
            ->get(route('clients.marketing-alerts.index', $client))
            ->assertOk()
            ->assertSee('待處理 1 則')
            ->assertSee('全部標示為已處理');

        $this->patch(route('clients.marketing-alerts.events.update', [$client, $firstEvent]), [
            'handled' => '1',
            'mute_days' => '7',
        ])->assertRedirect(route('clients.marketing-alerts.index', $client));

        $firstEvent->refresh();
        $this->assertNotNull($firstEvent->acknowledged_at);
        $this->assertSame($owner->id, $firstEvent->acknowledged_by_user_id);
        $this->assertSame('2026-12-04', $firstEvent->muted_until->toDateString());

        $this->travelTo(Carbon::parse('2026-11-28 07:23:00', 'Asia/Taipei'));
        $this->artisan('marketing-alerts:evaluate')->assertExitCode(0);

        $mutedEvent = $rule->events()->whereKeyNot($firstEvent->id)->sole();
        $this->assertNotNull($mutedEvent->notification_suppressed_at);
        $this->assertNotNull($mutedEvent->acknowledged_at);
        $this->assertSame(1, NotificationOutbox::query()->withoutGlobalScopes()->count());

        $this->get(route('clients.marketing-alerts.index', $client))
            ->assertOk()
            ->assertSee('靜音中，未通知')
            ->assertSee('待處理 0 則');

        $this->patch(route('clients.marketing-alerts.events.update', [$client, $firstEvent]), ['handled' => '0'])
            ->assertRedirect(route('clients.marketing-alerts.index', $client));
        $firstEvent->refresh();
        $this->assertNull($firstEvent->acknowledged_at);
        $this->assertNull($firstEvent->muted_until);

        $this->travelTo(Carbon::parse('2026-11-29 07:23:00', 'Asia/Taipei'));
        $this->artisan('marketing-alerts:evaluate')->assertExitCode(0);

        $this->assertSame(3, $rule->events()->count());
        $this->assertSame(2, NotificationOutbox::query()->withoutGlobalScopes()->count());
    }

    public function test_mute_days_are_validated_and_acknowledge_all_only_touches_the_clients_alerts(): void
    {
        ['owner' => $owner, 'client' => $client] = $this->createClientWithTarget();
        ['client' => $otherClient, 'target' => $otherTarget] = $this->createClientWithTarget();
        $rule = MarketingAlertRule::factory()->create(['client_id' => $client->id]);
        $otherRule = MarketingAlertRule::factory()->create(['client_id' => $otherClient->id]);
        $event = $this->createAlertEvent($rule, $client->socialEngagementTargets()->sole(), '2026-11-25');
        $secondEvent = $this->createAlertEvent($rule, $client->socialEngagementTargets()->sole(), '2026-11-26');
        $otherEvent = $this->createAlertEvent($otherRule, $otherTarget, '2026-11-26');
        $sameOwnerClient = Client::factory()->for($owner)->create();

        $this->actingAs($owner)
            ->patch(route('clients.marketing-alerts.events.update', [$client, $event]), ['handled' => '1', 'mute_days' => '365'])
            ->assertSessionHasErrors('mute_days');
        $this->patch(route('clients.marketing-alerts.events.update', [$sameOwnerClient, $event]), ['handled' => '1'])
            ->assertNotFound();
        $this->patch(route('clients.marketing-alerts.events.update', [$client, $otherEvent]), ['handled' => '1'])
            ->assertNotFound();

        $this->post(route('clients.marketing-alerts.events.acknowledge-all', $client))
            ->assertRedirect(route('clients.marketing-alerts.index', $client))
            ->assertSessionHas('status', '已將 2 則警示標示為已處理。');

        $this->assertNotNull($event->refresh()->acknowledged_at);
        $this->assertSame($owner->id, $secondEvent->refresh()->acknowledged_by_user_id);
        $this->assertNull($otherEvent->refresh()->acknowledged_at);
    }

    public function test_read_only_members_cannot_handle_alerts(): void
    {
        ['owner' => $owner, 'client' => $client, 'target' => $target] = $this->createClientWithTarget();
        $rule = MarketingAlertRule::factory()->create(['client_id' => $client->id]);
        $event = $this->createAlertEvent($rule, $target, '2026-11-26');
        $member = User::factory()->create();
        WorkspaceMembership::factory()->create([
            'owner_user_id' => $owner->id,
            'member_user_id' => $member->id,
            'role' => WorkspaceMembership::ROLE_READ_ONLY,
            'client_scope' => WorkspaceMembership::CLIENT_SCOPE_ALL,
        ]);

        $this->actingAs($member)
            ->get(route('clients.marketing-alerts.index', $client))
            ->assertOk()
            ->assertSee('待處理')
            ->assertDontSee('處理並靜音')
            ->assertDontSee('全部標示為已處理');
        $this->patch(route('clients.marketing-alerts.events.update', [$client, $event]), ['handled' => '1'])->assertForbidden();
        $this->post(route('clients.marketing-alerts.events.acknowledge-all', $client))->assertForbidden();

        $this->assertNull($event->refresh()->acknowledged_at);
    }

    private function createAlertEvent(MarketingAlertRule $rule, SocialEngagementTarget $target, string $observedOn): MarketingAlertEvent
    {
        return $rule->events()->create([
            'social_engagement_target_id' => $target->id,
            'source' => 'google_ads',
            'observed_on' => $observedOn,
            'message' => '測試警示',
        ]);
    }

    /**
     * @return array{owner: User, client: Client, target: SocialEngagementTarget}
     */
    private function createClientWithTarget(?string $metaCampaignId = null): array
    {
        $owner = User::factory()->create();
        $client = Client::factory()->for($owner)->create(['name' => '北區客戶']);
        $target = SocialEngagementTarget::factory()->create([
            'client_id' => $client->id,
            'tag' => '年終檔期',
            'social_platform' => 'facebook',
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-12-31',
            'google_ads_campaign_id' => $metaCampaignId === null ? '987654321' : null,
            'meta_campaign_id' => $metaCampaignId,
        ]);

        return compact('owner', 'client', 'target');
    }

    /**
     * @param  array<string, array{spend: int|float, conversions: int|float, value: int|float}>  $days
     */
    private function createGoogleAdsReport(SocialEngagementTarget $target, array $days): SocialEngagementTargetGoogleAdsReport
    {
        $rows = [];

        foreach ($days as $date => $metrics) {
            $rows[] = [
                'date' => $date,
                'spend_amount' => (string) $metrics['spend'],
                'impressions' => 10000,
                'clicks' => 300,
                'conversions' => (string) $metrics['conversions'],
                'conversions_value' => (string) $metrics['value'],
            ];
        }

        return SocialEngagementTargetGoogleAdsReport::factory()->create([
            'social_engagement_target_id' => $target->id,
            'campaign_id' => $target->google_ads_campaign_id,
            'starts_on' => $target->starts_on,
            'ends_on' => $target->ends_on,
            'currency_code' => 'TWD',
            'daily_metrics' => ['status' => 'complete', 'rows' => $rows],
            'synced_at' => now(),
        ]);
    }
}
