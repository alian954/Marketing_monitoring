<?php

namespace Tests\Feature;

use App\Models\SocialEngagementTarget;
use App\Models\User;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SocialEngagementCampaignUtmLinkBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_utm_campaign_generates_a_link_using_campaign_values_and_preserves_destination_details(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $target = SocialEngagementTarget::factory()->create([
            'tag' => 'Spring launch',
            'ga4_campaign_name' => 'Spring Launch',
            'ga4_source_name' => 'instagram',
            'ga4_medium_name' => 'social',
        ]);
        $destinationUrl = 'https://shop.example/products?ref=homepage&UTM_Source=old&utm_content=old&utm_term=running#details';
        $generatedUrl = 'https://shop.example/products?ref=homepage&utm_term=running&utm_source=instagram&utm_medium=social&utm_campaign=Spring%20Launch&utm_content=video%2015s%2Fblue%26admin%3Dtrue#details';

        $this->from(route('social-engagement-targets.report', $target))
            ->post(route('social-engagement-targets.report.utm-link-preview', $target), [
                'destination_url' => $destinationUrl,
                'utm_content' => 'video 15s/blue&admin=true',
                'utm_source' => 'untrusted-source',
                'utm_medium' => 'untrusted-medium',
                'utm_campaign' => 'untrusted-campaign',
            ])
            ->assertRedirect(route('social-engagement-targets.report', $target))
            ->assertSessionHas('campaignUtmLinkBuilderResult', [
                'destination_url' => $destinationUrl,
                'utm_content' => 'video 15s/blue&admin=true',
                'url' => $generatedUrl,
            ]);

        $this->get(route('social-engagement-targets.report', $target))
            ->assertOk()
            ->assertSee('追蹤連結產生器')
            ->assertSee('name="destination_url"', false)
            ->assertSee('name="utm_content"', false)
            ->assertSee('value="'.e($generatedUrl).'"', false)
            ->assertSee('commandfor="campaign-utm-generated-url"', false);
    }

    public function test_report_links_to_campaign_settings_when_manual_utm_source_or_medium_is_missing(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $target = SocialEngagementTarget::factory()->create([
            'tag' => 'Spring launch',
            'ga4_source_name' => null,
            'ga4_medium_name' => null,
        ]);
        $settingsUrl = route('dashboard', [
            'client_id' => $target->client_id,
            'engagement_platform' => $target->social_platform,
            'engagement_tag' => 'tag:'.$target->tag,
        ]);

        $this->get(route('social-engagement-targets.report', $target))
            ->assertOk()
            ->assertSee('先設定活動的 GA4 來源與媒介')
            ->assertSee($settingsUrl)
            ->assertDontSee(route('social-engagement-targets.report.utm-link-preview', $target), false);
    }

    public function test_google_ads_auto_tagged_campaign_does_not_offer_manual_utm_links(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $target = SocialEngagementTarget::factory()->create([
            'ga4_source_name' => 'google',
            'ga4_medium_name' => 'cpc',
            'google_ads_campaign_id' => '123456',
        ]);

        $this->get(route('social-engagement-targets.report', $target))
            ->assertOk()
            ->assertSee('此活動使用 Google Ads 自動標記')
            ->assertDontSee(route('social-engagement-targets.report.utm-link-preview', $target), false);
    }

    public function test_utm_link_preview_requires_authentication(): void
    {
        $target = SocialEngagementTarget::factory()->create();

        $this->post(route('social-engagement-targets.report.utm-link-preview', $target), [
            'destination_url' => 'https://shop.example/products',
            'utm_content' => 'video-blue',
        ])
            ->assertRedirect(route('login'));
    }

    public function test_utm_link_preview_cannot_access_another_workspace_campaign(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);
        $target = SocialEngagementTarget::factory()->create([
            'ga4_source_name' => 'instagram',
            'ga4_medium_name' => 'social',
        ]);
        $this->actingAs(User::factory()->create());

        $this->post(route('social-engagement-targets.report.utm-link-preview', $target), [
            'destination_url' => 'https://shop.example/products',
            'utm_content' => 'video-blue',
        ])->assertNotFound();
    }

    public function test_read_only_workspace_member_can_generate_a_utm_link_without_changing_the_campaign(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        WorkspaceMembership::factory()->create([
            'owner_user_id' => $owner->id,
            'member_user_id' => $member->id,
            'role' => WorkspaceMembership::ROLE_READ_ONLY,
            'client_scope' => WorkspaceMembership::CLIENT_SCOPE_ALL,
        ]);
        $this->actingAs($owner);
        $target = SocialEngagementTarget::factory()->create([
            'tag' => 'Spring launch',
            'ga4_campaign_name' => 'Spring launch',
            'ga4_source_name' => 'instagram',
            'ga4_medium_name' => 'social',
        ]);
        $this->actingAs($member);

        $this->post(route('social-engagement-targets.report.utm-link-preview', $target), [
            'destination_url' => 'https://shop.example/products',
            'utm_content' => 'video-blue',
        ])
            ->assertRedirect(route('social-engagement-targets.report', $target))
            ->assertSessionHas('campaignUtmLinkBuilderResult.url', 'https://shop.example/products?utm_source=instagram&utm_medium=social&utm_campaign=Spring%20launch&utm_content=video-blue');

        $this->assertSame('Spring launch', $target->fresh()->tag);
    }

    #[DataProvider('unavailableCampaignConfigurations')]
    public function test_utm_link_preview_requires_manual_source_and_medium_configuration(array $targetOverrides): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $target = SocialEngagementTarget::factory()->create($targetOverrides);

        $this->post(route('social-engagement-targets.report.utm-link-preview', $target), [
            'destination_url' => 'https://shop.example/products',
            'utm_content' => 'video-blue',
        ])->assertNotFound();
    }

    public static function unavailableCampaignConfigurations(): array
    {
        return [
            'Google Ads auto tagging' => [[
                'ga4_source_name' => 'google',
                'ga4_medium_name' => 'cpc',
                'google_ads_campaign_id' => '123456',
            ]],
            'missing source' => [[
                'ga4_source_name' => null,
                'ga4_medium_name' => 'social',
            ]],
            'missing medium' => [[
                'ga4_source_name' => 'instagram',
                'ga4_medium_name' => null,
            ]],
        ];
    }

    #[DataProvider('invalidPreviewInputs')]
    public function test_utm_link_preview_rejects_invalid_urls_and_content(array $overrides, array $expectedErrorFields): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $target = SocialEngagementTarget::factory()->create([
            'ga4_source_name' => 'instagram',
            'ga4_medium_name' => 'social',
        ]);
        $input = array_replace([
            'destination_url' => 'https://shop.example/products',
            'utm_content' => 'video-blue',
        ], $overrides);

        $this->from(route('social-engagement-targets.report', $target))
            ->post(route('social-engagement-targets.report.utm-link-preview', $target), $input)
            ->assertRedirect(route('social-engagement-targets.report', $target))
            ->assertSessionHasErrorsIn('utmBuilder', $expectedErrorFields);
    }

    public static function invalidPreviewInputs(): array
    {
        return [
            'required fields' => [['destination_url' => '', 'utm_content' => ''], ['destination_url', 'utm_content']],
            'unsupported protocol' => [['destination_url' => 'ftp://shop.example/products'], ['destination_url']],
            'URL credentials' => [['destination_url' => 'https://client:secret@shop.example/products'], ['destination_url']],
            'destination URL too long' => [['destination_url' => 'https://shop.example/'.str_repeat('p', 2048)], ['destination_url']],
            'UTM content too long' => [['utm_content' => str_repeat('x', 4097)], ['utm_content']],
        ];
    }
}
