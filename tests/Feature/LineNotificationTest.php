<?php

namespace Tests\Feature;

use App\Jobs\SendNotificationOutbox;
use App\Models\Client;
use App\Models\LineMessagingChannel;
use App\Models\LineNotificationTarget;
use App\Models\MarketingAlertRule;
use App\Models\NotificationOutbox;
use App\Models\Organization;
use App\Models\SocialEngagementTarget;
use App\Models\SocialEngagementTargetGoogleAdsReport;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class LineNotificationTest extends TestCase
{
    use RefreshDatabase;

    private const CHANNEL_SECRET = '0123456789abcdef0123456789abcdef';

    private const GROUP_ID = 'C0123456789abcdef0123456789abcdef';

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Http::preventStrayRequests();
        $this->travelTo(Carbon::parse('2026-11-27 07:23:00', 'Asia/Taipei'));
    }

    public function test_owner_can_connect_line_official_account_with_encrypted_credentials(): void
    {
        ['owner' => $owner] = $this->createWorkspaceOwner();
        Http::fake([
            'api.line.me/v2/bot/info' => Http::response([
                'userId' => 'Uaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
                'basicId' => '@ads-alert',
                'displayName' => '行銷警示小幫手',
            ]),
        ]);

        $this->actingAs($owner)
            ->put(route('integrations.line.update'), [
                'channel_secret' => self::CHANNEL_SECRET,
                'channel_access_token' => 'line-access-token-value',
            ])
            ->assertRedirect(route('integrations.line.edit'));

        $channel = LineMessagingChannel::query()->sole();
        $this->assertSame('line-access-token-value', $channel->channel_access_token);
        $this->assertSame('行銷警示小幫手', $channel->bot_display_name);
        $this->assertStringNotContainsString('line-access-token-value', (string) DB::table('line_messaging_channels')->value('channel_access_token'));
        $this->assertStringNotContainsString(self::CHANNEL_SECRET, (string) DB::table('line_messaging_channels')->value('channel_secret'));
        Http::assertSent(static fn (ClientRequest $request): bool => $request->hasHeader('Authorization', 'Bearer line-access-token-value'));

        $this->get(route('integrations.line.edit'))
            ->assertOk()
            ->assertSee('行銷警示小幫手')
            ->assertSee(route('line.webhook', $channel->webhook_key))
            ->assertDontSee('line-access-token-value')
            ->assertDontSee(self::CHANNEL_SECRET);
    }

    public function test_rejected_access_token_is_not_saved(): void
    {
        ['owner' => $owner] = $this->createWorkspaceOwner();
        Http::fake(['api.line.me/v2/bot/info' => Http::response(['message' => 'Authentication failed'], 401)]);

        $this->actingAs($owner)
            ->put(route('integrations.line.update'), [
                'channel_secret' => self::CHANNEL_SECRET,
                'channel_access_token' => 'revoked-token',
            ])
            ->assertRedirect(route('integrations.line.edit'))
            ->assertSessionHasErrorsIn('lineChannel', 'channel_access_token');

        $this->assertDatabaseCount('line_messaging_channels', 0);
    }

    public function test_non_owner_members_cannot_manage_line_settings(): void
    {
        ['owner' => $owner] = $this->createWorkspaceOwner();
        $member = User::factory()->create();
        WorkspaceMembership::factory()->create([
            'owner_user_id' => $owner->id,
            'member_user_id' => $member->id,
            'role' => WorkspaceMembership::ROLE_EDITOR,
            'client_scope' => WorkspaceMembership::CLIENT_SCOPE_ALL,
        ]);

        $this->actingAs($member)->get(route('integrations.line.edit'))->assertForbidden();
        $this->put(route('integrations.line.update'), [
            'channel_secret' => self::CHANNEL_SECRET,
            'channel_access_token' => 'member-token',
        ])->assertForbidden();
    }

    public function test_webhook_records_group_join_and_leave_only_with_valid_signature(): void
    {
        ['owner' => $owner, 'workspace' => $workspace] = $this->createWorkspaceOwner();
        $channel = $this->createChannel($owner, $workspace);
        Http::fake([
            'api.line.me/v2/bot/group/'.self::GROUP_ID.'/summary' => Http::response(['groupId' => self::GROUP_ID, 'groupName' => '北區客戶投放群']),
        ]);
        $joinPayload = json_encode([
            'destination' => 'Uaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            'events' => [[
                'type' => 'join',
                'timestamp' => 1764200000000,
                'source' => ['type' => 'group', 'groupId' => self::GROUP_ID],
            ]],
        ], JSON_THROW_ON_ERROR);

        $this->postLineWebhook($channel, $joinPayload, 'invalid-signature')->assertUnauthorized();
        $this->assertDatabaseCount('line_notification_targets', 0);

        $this->postLineWebhook($channel, $joinPayload)->assertOk();

        $target = LineNotificationTarget::query()->sole();
        $this->assertSame(self::GROUP_ID, $target->target_id);
        $this->assertSame(LineNotificationTarget::TYPE_GROUP, $target->target_type);
        $this->assertSame('北區客戶投放群', $target->display_name);
        $this->assertSame($workspace->id, $target->workspace_id);
        $this->assertTrue($target->isActive());
        $this->assertNotNull($channel->refresh()->last_webhook_received_at);

        $this->postLineWebhook($channel, json_encode([
            'events' => [['type' => 'leave', 'source' => ['type' => 'group', 'groupId' => self::GROUP_ID]]],
        ], JSON_THROW_ON_ERROR))->assertOk();

        $this->assertFalse($target->refresh()->isActive());
    }

    public function test_webhook_records_followers_and_accepts_line_verification_requests(): void
    {
        ['owner' => $owner, 'workspace' => $workspace] = $this->createWorkspaceOwner();
        $channel = $this->createChannel($owner, $workspace);
        $userId = 'U0123456789abcdef0123456789abcdef';
        Http::fake([
            'api.line.me/v2/bot/profile/'.$userId => Http::response(['userId' => $userId, 'displayName' => '投放經理']),
        ]);

        $this->postLineWebhook($channel, json_encode(['events' => []], JSON_THROW_ON_ERROR))->assertOk();
        $this->postLineWebhook($channel, json_encode([
            'events' => [
                ['type' => 'follow', 'source' => ['type' => 'user', 'userId' => $userId]],
                ['type' => 'message', 'source' => ['type' => 'group', 'groupId' => self::GROUP_ID]],
                ['type' => 'join', 'source' => ['type' => 'group', 'groupId' => 'not-a-line-id']],
            ],
        ], JSON_THROW_ON_ERROR))->assertOk();

        $target = LineNotificationTarget::query()->sole();
        $this->assertSame(LineNotificationTarget::TYPE_USER, $target->target_type);
        $this->assertSame('投放經理', $target->display_name);
    }

    public function test_marketing_alert_is_pushed_to_selected_line_group(): void
    {
        ['owner' => $owner, 'workspace' => $workspace] = $this->createWorkspaceOwner();
        $channel = $this->createChannel($owner, $workspace);
        $lineTarget = LineNotificationTarget::factory()->for($channel, 'channel')->create(['target_id' => self::GROUP_ID]);
        ['client' => $client, 'target' => $campaign] = $this->createClientWithCampaign($owner);
        $this->createSpendSpikeReport($campaign);
        Http::fake(['api.line.me/v2/bot/message/push' => Http::response([])]);

        $this->actingAs($owner)
            ->post(route('clients.marketing-alerts.store', $client), [
                'metric' => MarketingAlertRule::METRIC_SPEND_SPIKE,
                'threshold' => '50',
                'line_notification_target_id' => $lineTarget->id,
            ])
            ->assertRedirect(route('clients.marketing-alerts.index', $client))
            ->assertSessionHasNoErrors();

        $rule = $client->marketingAlertRules()->sole();
        $this->assertSame($lineTarget->id, $rule->line_notification_target_id);
        $this->get(route('clients.marketing-alerts.index', $client))
            ->assertOk()
            ->assertSee('LINE：客戶專案群組（群組）');

        auth()->logout();
        $this->artisan('marketing-alerts:evaluate')->assertExitCode(0);

        $outbox = NotificationOutbox::query()->withoutGlobalScopes()->sole();
        $this->assertSame(NotificationOutbox::CHANNEL_LINE, $outbox->channel);
        $this->assertSame('sent', $outbox->status);
        Http::assertSent(function (ClientRequest $request) use ($channel): bool {
            return $request->url() === 'https://api.line.me/v2/bot/message/push'
                && $request->hasHeader('Authorization', 'Bearer '.$channel->channel_access_token)
                && preg_match('/\A[0-9a-f-]{36}\z/', $request->header('X-Line-Retry-Key')[0] ?? '') === 1
                && $request['to'] === self::GROUP_ID
                && str_contains($request['messages'][0]['text'], '單日花費暴增')
                && str_contains($request['messages'][0]['text'], '北區客戶');
        });
    }

    public function test_line_targets_from_another_workspace_cannot_be_used_or_tested(): void
    {
        ['owner' => $owner, 'workspace' => $workspace] = $this->createWorkspaceOwner();
        ['owner' => $otherOwner] = $this->createWorkspaceOwner();
        $foreignTarget = LineNotificationTarget::factory()
            ->for($this->createChannel($owner, $workspace), 'channel')
            ->create();
        ['client' => $otherClient] = $this->createClientWithCampaign($otherOwner);

        $this->actingAs($otherOwner)
            ->post(route('clients.marketing-alerts.store', $otherClient), [
                'metric' => MarketingAlertRule::METRIC_SYNC_STALE,
                'line_notification_target_id' => $foreignTarget->id,
            ])
            ->assertSessionHasErrors('line_notification_target_id');
        $this->post(route('integrations.line.targets.test', $foreignTarget))->assertNotFound();
        $this->delete(route('integrations.line.targets.destroy', $foreignTarget))->assertNotFound();

        $this->assertDatabaseCount('marketing_alert_rules', 0);
        $this->assertDatabaseHas('line_notification_targets', ['id' => $foreignTarget->id]);
    }

    public function test_test_message_is_sent_and_left_targets_fail_without_calling_line(): void
    {
        ['owner' => $owner, 'workspace' => $workspace] = $this->createWorkspaceOwner();
        $channel = $this->createChannel($owner, $workspace);
        $lineTarget = LineNotificationTarget::factory()->for($channel, 'channel')->create(['target_id' => self::GROUP_ID]);
        Http::fake(['api.line.me/v2/bot/message/push' => Http::response([])]);

        $this->actingAs($owner)
            ->post(route('integrations.line.targets.test', $lineTarget))
            ->assertRedirect(route('integrations.line.edit'));

        $this->assertSame('sent', NotificationOutbox::query()->sole()->status);
        Http::assertSentCount(1);

        $lineTarget->forceFill(['left_at' => now()])->save();
        $outbox = NotificationOutbox::factory()->create([
            'channel' => NotificationOutbox::CHANNEL_LINE,
            'recipient' => self::GROUP_ID,
            'status' => 'pending',
            'workspace_id' => $workspace->id,
        ]);

        dispatch_sync(new SendNotificationOutbox($outbox->id));

        $outbox->refresh();
        $this->assertSame('unknown', $outbox->status);
        $this->assertStringContainsString('已離開此群組', (string) $outbox->last_error);
        Http::assertSentCount(1);
    }

    /** @return array{owner: User, workspace: Workspace} */
    private function createWorkspaceOwner(): array
    {
        $owner = User::factory()->create();
        $organization = Organization::factory()->for($owner, 'owner')->has(Workspace::factory(), 'workspace')->create();

        return ['owner' => $owner, 'workspace' => $organization->workspace];
    }

    private function createChannel(User $owner, Workspace $workspace): LineMessagingChannel
    {
        return LineMessagingChannel::factory()->create([
            'user_id' => $owner->id,
            'workspace_id' => $workspace->id,
            'channel_secret' => self::CHANNEL_SECRET,
        ]);
    }

    private function postLineWebhook(LineMessagingChannel $channel, string $payload, ?string $signature = null): TestResponse
    {
        $signature ??= base64_encode(hash_hmac('sha256', $payload, self::CHANNEL_SECRET, true));

        return $this->call(
            'POST',
            route('line.webhook', $channel->webhook_key),
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_LINE_SIGNATURE' => $signature],
            content: $payload,
        );
    }

    /** @return array{client: Client, target: SocialEngagementTarget} */
    private function createClientWithCampaign(User $owner): array
    {
        $client = Client::factory()->for($owner)->create(['name' => '北區客戶']);
        $target = SocialEngagementTarget::factory()->create([
            'client_id' => $client->id,
            'tag' => '年終檔期',
            'social_platform' => 'facebook',
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-12-31',
            'google_ads_campaign_id' => '987654321',
        ]);

        return compact('client', 'target');
    }

    private function createSpendSpikeReport(SocialEngagementTarget $target): void
    {
        $rows = [];

        foreach (['2026-11-19' => 1000, '2026-11-20' => 1000, '2026-11-21' => 1000, '2026-11-26' => 3000] as $date => $spend) {
            $rows[] = [
                'date' => $date,
                'spend_amount' => (string) $spend,
                'impressions' => 10000,
                'clicks' => 300,
                'conversions' => '2',
                'conversions_value' => '6000',
            ];
        }

        SocialEngagementTargetGoogleAdsReport::factory()->create([
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
