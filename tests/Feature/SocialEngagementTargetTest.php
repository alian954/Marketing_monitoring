<?php

namespace Tests\Feature;

use App\Models\CheckRun;
use App\Models\Monitor;
use App\Models\SocialEngagementTarget;
use App\Models\User;
use Database\Seeders\SocialEngagementTargetSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SocialEngagementTargetTest extends TestCase
{
    use RefreshDatabase;

    public function test_social_engagement_targets_require_authentication(): void
    {
        $this->post(route('social-engagement-targets.store'), [
            'tag' => 'Campaign',
            'social_platform' => 'facebook',
            'target_interactions' => 100,
        ])->assertRedirect(route('login'));

        $this->assertDatabaseCount('social_engagement_targets', 0);
    }

    public function test_authenticated_user_can_create_and_update_a_tag_platform_target(): void
    {
        $this->createSocialMonitor('facebook', 'POST123', 'Summer launch');
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(route('dashboard', ['engagement_platform' => 'facebook', 'engagement_tag' => 'tag:Summer launch']))
            ->post(route('social-engagement-targets.store'), [
                'tag' => 'Summer launch',
                'social_platform' => 'facebook',
                'target_interactions' => 2500,
                'engagement_platform' => 'facebook',
                'engagement_tag' => 'tag:Summer launch',
            ])
            ->assertRedirect(route('dashboard', ['engagement_platform' => 'facebook', 'engagement_tag' => 'tag:Summer launch']))
            ->assertSessionHas('status');

        $target = SocialEngagementTarget::query()->sole();
        $this->assertSame('Summer launch', $target->tag);
        $this->assertSame('facebook', $target->social_platform);
        $this->assertSame(2500, $target->target_interactions);

        $this->actingAs($user)
            ->post(route('social-engagement-targets.store'), [
                'tag' => 'Summer launch',
                'social_platform' => 'facebook',
                'target_interactions' => 3000,
            ])
            ->assertRedirect(route('dashboard'));

        $this->assertDatabaseCount('social_engagement_targets', 1);
        $this->assertSame(3000, $target->fresh()->target_interactions);
    }

    public function test_target_validation_rejects_untracked_tags_and_invalid_target_values(): void
    {
        $this->createSocialMonitor('facebook', 'POST123', 'Summer launch');

        $this->actingAs(User::factory()->create())
            ->from(route('dashboard'))
            ->post(route('social-engagement-targets.store'), [
                'tag' => 'Unknown campaign',
                'social_platform' => 'facebook',
                'target_interactions' => 0,
            ])
            ->assertRedirect(route('dashboard'))
            ->assertSessionHasErrorsIn('socialEngagementTarget', ['tag', 'target_interactions']);

        $this->assertDatabaseCount('social_engagement_targets', 0);
    }

    public function test_target_validation_rejects_a_tag_that_is_not_tracked_on_the_selected_platform(): void
    {
        $this->createSocialMonitor('facebook', 'POST123', 'Summer launch');

        $this->actingAs(User::factory()->create())
            ->from(route('dashboard'))
            ->post(route('social-engagement-targets.store'), [
                'tag' => 'Summer launch',
                'social_platform' => 'instagram',
                'target_interactions' => 100,
            ])
            ->assertRedirect(route('dashboard'))
            ->assertSessionHasErrorsIn('socialEngagementTarget', ['tag']);

        $this->assertDatabaseCount('social_engagement_targets', 0);
    }

    public function test_dashboard_shows_goal_progress_and_coverage_per_platform(): void
    {
        $facebookComplete = $this->createSocialMonitor('facebook', 'FB-COMPLETE', 'Summer launch');
        $this->createMetricRun($facebookComplete, ['likes' => 10, 'comments' => 5, 'shares' => 5]);
        $facebookIncomplete = $this->createSocialMonitor('facebook', 'FB-INCOMPLETE', 'Summer launch');
        $this->createMetricRun($facebookIncomplete, ['likes' => 90, 'comments' => 40, 'shares' => null]);

        $instagram = $this->createSocialMonitor('instagram', 'IG-COMPLETE', 'Summer launch');
        $this->createMetricRun($instagram, ['likes' => 20, 'comments' => 10, 'shares' => 900]);

        SocialEngagementTarget::factory()->create([
            'tag' => 'Summer launch',
            'social_platform' => 'facebook',
            'target_interactions' => 50,
        ]);
        SocialEngagementTarget::factory()->create([
            'tag' => 'Summer launch',
            'social_platform' => 'instagram',
            'target_interactions' => 60,
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('dashboard', ['engagement_tag' => 'tag:Summer launch']))
            ->assertOk()
            ->assertSee('活動互動目標')
            ->assertSee('已讀 1／2 篇')
            ->assertSee('20／50')
            ->assertSee('30／60');

        $targets = collect($response->viewData('socialEngagementTargets'))->keyBy('social_platform');

        $this->assertSame(20, $targets['facebook']['current_interactions']);
        $this->assertSame(40.0, $targets['facebook']['completion_percent']);
        $this->assertSame(1, $targets['facebook']['available_post_count']);
        $this->assertSame(2, $targets['facebook']['tracked_post_count']);
        $this->assertSame(30, $targets['instagram']['current_interactions']);
        $this->assertSame(50.0, $targets['instagram']['completion_percent']);
        $this->assertSame(1, $targets['instagram']['available_post_count']);
        $this->assertSame(1, $targets['instagram']['tracked_post_count']);

        $filteredResponse = $this->actingAs(User::factory()->create())
            ->get(route('dashboard', [
                'engagement_platform' => 'facebook',
                'engagement_tag' => 'tag:Summer launch',
            ]))
            ->assertOk();

        $this->assertSame(['facebook'], array_column($filteredResponse->viewData('socialEngagementTargets'), 'social_platform'));
    }

    public function test_target_with_no_complete_public_metrics_has_no_completion_percentage(): void
    {
        $this->createSocialMonitor('dcard', '262476944', 'Summer launch');
        SocialEngagementTarget::factory()->create([
            'tag' => 'Summer launch',
            'social_platform' => 'dcard',
            'target_interactions' => 100,
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('dashboard', ['engagement_tag' => 'tag:Summer launch']))
            ->assertOk();

        $target = $response->viewData('socialEngagementTargets')[0];
        $this->assertNull($target['current_interactions']);
        $this->assertNull($target['completion_percent']);
        $this->assertSame(0, $target['available_post_count']);
        $this->assertSame(1, $target['tracked_post_count']);
    }

    public function test_complete_zero_interactions_count_as_zero_percent_instead_of_missing_data(): void
    {
        $monitor = $this->createSocialMonitor('facebook', 'FB-ZERO', 'Summer launch');
        $this->createMetricRun($monitor, ['likes' => 0, 'comments' => 0, 'shares' => 0]);
        $targetModel = SocialEngagementTarget::factory()->create([
            'tag' => 'Summer launch',
            'social_platform' => 'facebook',
            'target_interactions' => 100,
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('dashboard', ['engagement_tag' => 'tag:Summer launch']))
            ->assertOk();

        $target = $response->viewData('socialEngagementTargets')[0];
        $this->assertSame(0, $target['current_interactions']);
        $this->assertSame(0.0, $target['completion_percent']);
        $this->assertSame(1, $target['available_post_count']);
        $this->assertSame(1, $target['tracked_post_count']);
        $this->assertModelExists($targetModel);
    }

    public function test_local_target_seeder_uses_an_existing_tagged_social_post_and_is_idempotent(): void
    {
        $this->createSocialMonitor('facebook', 'UNTAGGED-POST', null);
        $this->createSocialMonitor('threads', 'THREAD123', 'Spring campaign');
        app()->detectEnvironment(static fn (): string => 'local');
        $seeder = app(SocialEngagementTargetSeeder::class);

        $seeder->run();
        $seeder->run();

        $target = SocialEngagementTarget::query()->sole();
        $this->assertSame('Spring campaign', $target->tag);
        $this->assertSame('threads', $target->social_platform);
        $this->assertSame(1000, $target->target_interactions);
    }

    public function test_authenticated_user_can_delete_a_social_engagement_target(): void
    {
        $target = SocialEngagementTarget::factory()->create();

        $this->actingAs(User::factory()->create())
            ->delete(route('social-engagement-targets.destroy', $target))
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('status');

        $this->assertModelMissing($target);
    }

    private function createSocialMonitor(string $platform, string $postId, ?string $tag): Monitor
    {
        $url = match ($platform) {
            'facebook' => 'https://www.facebook.com/share/p/'.$postId.'/',
            'instagram' => 'https://www.instagram.com/reel/'.$postId.'/',
            'threads' => 'https://www.threads.com/share/'.$postId,
            'dcard' => 'https://www.dcard.tw/f/makeup/p/'.$postId,
            default => throw new \InvalidArgumentException('Unsupported social platform.'),
        };

        return Monitor::factory()->create([
            'name' => ucfirst($platform).' '.$postId,
            'url' => $url,
            'url_hash' => Monitor::fingerprintUrl($url),
            'label' => $tag,
        ]);
    }

    /** @param array{likes: ?int, comments: ?int, shares: ?int} $metrics */
    private function createMetricRun(Monitor $monitor, array $metrics): CheckRun
    {
        return CheckRun::factory()->for($monitor)->create([
            'config_version' => $monitor->config_version,
            'response_public_engagement_metrics' => $metrics + ['views' => null, 'source' => 'json_ld'],
        ]);
    }
}
