<?php

namespace Tests\Feature;

use App\Models\CheckRun;
use App\Models\Monitor;
use App\Models\NotificationOutbox;
use App\Models\SocialEngagementTarget;
use App\Models\StatusPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Tests\TestCase;

class AccountDataIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_accounts_can_track_the_same_post_and_campaign_without_sharing_reports(): void
    {
        $firstUser = User::factory()->create();
        $secondUser = User::factory()->create();
        $url = 'https://www.instagram.com/p/SHARED-CAMPAIGN-01/';
        $firstMonitor = $this->createOwnedMonitor($firstUser, 'FIRST_ACCOUNT_POST', $url, 'Shared launch');
        $secondMonitor = $this->createOwnedMonitor($secondUser, 'SECOND_ACCOUNT_POST', $url, 'Shared launch');
        $firstTarget = $this->createOwnedTarget($firstUser, 'Shared launch', 100);
        $secondTarget = $this->createOwnedTarget($secondUser, 'Shared launch', 900);
        $this->createMetricRun($firstMonitor, ['likes' => 20, 'comments' => 2]);
        $this->createMetricRun($secondMonitor, ['likes' => 200, 'comments' => 20]);

        $this->actingAs($firstUser)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('FIRST_ACCOUNT_POST')
            ->assertDontSee('SECOND_ACCOUNT_POST');

        $firstReport = $this->get(route('social-engagement-targets.report', $firstTarget))
            ->assertOk()
            ->assertSee('FIRST_ACCOUNT_POST')
            ->assertDontSee('SECOND_ACCOUNT_POST');

        $this->assertSame(22, $firstReport->viewData('report')['interaction_total']);

        $this->actingAs($secondUser)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('SECOND_ACCOUNT_POST')
            ->assertDontSee('FIRST_ACCOUNT_POST');

        $secondReport = $this->get(route('social-engagement-targets.report', $secondTarget))
            ->assertOk()
            ->assertSee('SECOND_ACCOUNT_POST')
            ->assertDontSee('FIRST_ACCOUNT_POST');

        $this->assertSame(220, $secondReport->viewData('report')['interaction_total']);
        $this->assertDatabaseCount('monitors', 2);
        $this->assertDatabaseCount('social_engagement_targets', 2);
    }

    public function test_authenticated_accounts_can_create_the_same_url_and_campaign_tag(): void
    {
        Config::set('monitoring.transport', 'fixture');
        $firstUser = User::factory()->create();
        $secondUser = User::factory()->create();
        $monitorPayload = [
            'name' => 'Shared landing page',
            'url' => 'http://fixture.test/ok',
            'label' => 'Shared launch',
            'expected_text_mode' => 'present',
            'user_id' => $secondUser->id,
        ];

        $this->actingAs($firstUser)
            ->post(route('monitors.store'), $monitorPayload)
            ->assertRedirect();

        $this->actingAs($secondUser)
            ->post(route('monitors.store'), $monitorPayload)
            ->assertRedirect();

        $this->assertDatabaseCount('monitors', 2);
        $this->assertSame(
            [$firstUser->id, $secondUser->id],
            Monitor::withoutGlobalScopes()->orderBy('user_id')->pluck('user_id')->all(),
        );

        Auth::logout();

        $this->createOwnedMonitor(
            $firstUser,
            'FIRST_SOCIAL_POST',
            'https://www.instagram.com/p/SHARED-TAG-FIRST/',
            'Shared campaign tag',
        );
        $this->createOwnedMonitor(
            $secondUser,
            'SECOND_SOCIAL_POST',
            'https://www.instagram.com/p/SHARED-TAG-SECOND/',
            'Shared campaign tag',
        );
        $campaignPayload = [
            'tag' => 'Shared campaign tag',
            'social_platform' => 'instagram',
            'target_interactions' => 500,
            'user_id' => $firstUser->id,
        ];

        $this->actingAs($firstUser)
            ->post(route('social-engagement-targets.store'), $campaignPayload)
            ->assertRedirect();

        $campaignPayload['target_interactions'] = 900;
        $campaignPayload['user_id'] = $firstUser->id;

        $this->actingAs($secondUser)
            ->post(route('social-engagement-targets.store'), $campaignPayload)
            ->assertRedirect();

        $this->assertDatabaseCount('social_engagement_targets', 2);
        $this->assertSame(
            [
                [$firstUser->id, 500],
                [$secondUser->id, 900],
            ],
            SocialEngagementTarget::withoutGlobalScopes()
                ->orderBy('user_id')
                ->get(['user_id', 'target_interactions'])
                ->map(static fn (SocialEngagementTarget $target): array => [(int) $target->user_id, $target->target_interactions])
                ->all(),
        );
    }

    public function test_account_cannot_open_export_or_mutate_another_accounts_monitor_or_campaign(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $monitor = $this->createOwnedMonitor(
            $owner,
            'OWNER_ONLY_POST',
            'https://www.facebook.com/share/p/OWNER-ONLY-01/',
            'Owner campaign',
        );
        $target = $this->createOwnedTarget($owner, 'Owner campaign', 50, 'facebook');

        $this->actingAs($otherUser)
            ->get(route('monitors.show', $monitor))
            ->assertNotFound();
        $this->get(route('monitors.checks.export', $monitor))
            ->assertNotFound();
        $this->get(route('social-engagement-targets.report', $target))
            ->assertNotFound();
        $this->get(route('social-engagement-targets.posts', $target))
            ->assertNotFound();
        $this->get(route('social-engagement-targets.report.export', $target))
            ->assertNotFound();
        $this->put(route('social-engagement-targets.ad-spend.update', $target), [
            'ad_spend_amount' => '999.00',
            'ad_spend_currency' => 'TWD',
        ])->assertNotFound();
        $this->delete(route('social-engagement-targets.destroy', $target))
            ->assertNotFound();

        $this->assertDatabaseHas('monitors', ['id' => $monitor->id, 'name' => 'OWNER_ONLY_POST']);
        $this->assertDatabaseHas('social_engagement_targets', ['id' => $target->id, 'target_interactions' => 50]);
    }

    public function test_public_status_link_shows_only_the_monitors_owned_by_its_account(): void
    {
        $firstUser = User::factory()->create();
        $secondUser = User::factory()->create();
        $firstMonitor = $this->createOwnedMonitor(
            $firstUser,
            'FIRST_PUBLIC_SERVICE',
            'https://first-account.example/status',
            publicStatus: true,
        );
        $this->createOwnedMonitor(
            $secondUser,
            'SECOND_PUBLIC_SERVICE',
            'https://second-account.example/status',
            publicStatus: true,
        );
        $statusPage = $this->createOwnedStatusPage($firstUser, Str::random(64));

        $this->actingAs($secondUser)
            ->get(route('status-pages.public', ['token' => $statusPage->public_token]))
            ->assertOk()
            ->assertSee('FIRST_PUBLIC_SERVICE')
            ->assertDontSee('SECOND_PUBLIC_SERVICE');

        $this->assertDatabaseHas('monitors', ['id' => $firstMonitor->id, 'user_id' => $firstUser->id]);
    }

    public function test_status_page_settings_with_the_same_default_key_are_account_specific(): void
    {
        $firstUser = User::factory()->create();
        $secondUser = User::factory()->create();
        $firstPage = $this->createOwnedStatusPage($firstUser, Str::random(64), 'First account status');
        $secondPage = $this->createOwnedStatusPage($secondUser, Str::random(64), 'Second account private status');

        $this->actingAs($firstUser)
            ->get(route('status-pages.edit'))
            ->assertOk()
            ->assertSee('First account status')
            ->assertDontSee($secondPage->title);

        $this->actingAs($secondUser)
            ->post(route('status-pages.update'), [
                'title' => 'Second account status',
                'is_published' => '0',
            ])
            ->assertRedirect(route('status-pages.edit'));

        $this->assertDatabaseHas('status_pages', ['id' => $firstPage->id, 'title' => $firstPage->title]);
        $this->assertDatabaseHas('status_pages', ['id' => $secondPage->id, 'title' => 'Second account status']);
        $this->assertDatabaseCount('status_pages', 2);
    }

    public function test_notification_history_and_retry_routes_are_limited_to_the_monitor_owner(): void
    {
        $firstUser = User::factory()->create();
        $secondUser = User::factory()->create();
        $firstMonitor = $this->createOwnedMonitor(
            $firstUser,
            'FIRST_ACCOUNT_NOTIFICATION',
            'https://first-account.example/notification',
        );
        $secondMonitor = $this->createOwnedMonitor(
            $secondUser,
            'SECOND_ACCOUNT_NOTIFICATION',
            'https://second-account.example/notification',
        );
        $firstOutbox = NotificationOutbox::factory()->create([
            'incident_id' => null,
            'monitor_id' => $firstMonitor->id,
            'body' => 'first-account-private-message',
        ]);
        $secondOutbox = NotificationOutbox::factory()->create([
            'incident_id' => null,
            'monitor_id' => $secondMonitor->id,
            'body' => 'second-account-private-message',
        ]);

        $this->actingAs($firstUser)
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('FIRST_ACCOUNT_NOTIFICATION')
            ->assertDontSee('SECOND_ACCOUNT_NOTIFICATION')
            ->assertDontSee('second-account-private-message');

        $this->get(route('notifications.export'))
            ->assertOk()
            ->assertDontSee('SECOND_ACCOUNT_NOTIFICATION');

        $this->post(route('notifications.retry', $secondOutbox), [
            'acknowledge_duplicate_risk' => '1',
        ])->assertNotFound();

        $this->assertDatabaseHas('notification_outbox', ['id' => $firstOutbox->id, 'user_id' => $firstUser->id]);
        $this->assertDatabaseHas('notification_outbox', ['id' => $secondOutbox->id, 'user_id' => $secondUser->id]);
    }

    private function createOwnedMonitor(
        User $owner,
        string $name,
        string $url,
        ?string $label = null,
        bool $publicStatus = false,
    ): Monitor {
        $monitor = Monitor::factory()->make([
            'name' => $name,
            'url' => $url,
            'url_hash' => Monitor::fingerprintUrl($url),
            'label' => $label,
            'public_status' => $publicStatus,
        ]);
        $monitor->forceFill(['user_id' => $owner->id])->save();

        return $monitor;
    }

    private function createOwnedTarget(User $owner, string $tag, int $targetInteractions, string $platform = 'instagram'): SocialEngagementTarget
    {
        $target = SocialEngagementTarget::factory()->make([
            'tag' => $tag,
            'social_platform' => $platform,
            'target_interactions' => $targetInteractions,
        ]);
        $target->forceFill(['user_id' => $owner->id])->save();

        return $target;
    }

    /** @param array<string, int> $metrics */
    private function createMetricRun(Monitor $monitor, array $metrics): CheckRun
    {
        return CheckRun::factory()->for($monitor)->create([
            'response_public_engagement_metrics' => $metrics + ['shares' => null, 'views' => null],
            'response_published_at' => now()->subDay(),
            'finished_at' => now(),
        ]);
    }

    private function createOwnedStatusPage(User $owner, string $token, string $title = '服務狀態'): StatusPage
    {
        $statusPage = StatusPage::factory()->make([
            'setting_key' => 'default',
            'title' => $title,
            'public_token' => $token,
            'public_token_hash' => hash('sha256', $token),
            'is_published' => true,
        ]);
        $statusPage->forceFill(['user_id' => $owner->id])->save();

        return $statusPage;
    }
}
