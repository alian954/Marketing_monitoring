<?php

namespace Database\Factories;

use App\Models\GoogleAdsConnection;
use App\Models\MetaMarketingConnection;
use App\Models\SocialEngagementTarget;
use App\Models\SocialEngagementTargetPaidAdSnapshot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SocialEngagementTargetPaidAdSnapshot>
 */
class SocialEngagementTargetPaidAdSnapshotFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'social_engagement_target_id' => SocialEngagementTarget::factory(),
            'meta_marketing_connection_id' => MetaMarketingConnection::factory(),
            'google_ads_connection_id' => null,
            'source' => SocialEngagementTargetPaidAdSnapshot::SOURCE_META_MARKETING,
            'account_id' => fake()->numerify('#########'),
            'campaign_id' => fake()->numerify('##########'),
            'campaign_name' => fake()->words(3, true),
            'starts_on' => now()->subDays(14)->toDateString(),
            'ends_on' => now()->toDateString(),
            'spend_amount' => fake()->randomFloat(4, 0, 100000),
            'impressions' => fake()->numberBetween(0, 1000000),
            'reach' => fake()->numberBetween(0, 1000000),
            'clicks' => fake()->numberBetween(0, 100000),
            'conversions' => null,
            'conversions_value' => null,
            'currency_code' => 'TWD',
            'synced_at' => now(),
        ];
    }

    public function forMetaMarketing(MetaMarketingConnection $connection): static
    {
        return $this->state(fn (): array => [
            'meta_marketing_connection_id' => $connection->getKey(),
            'google_ads_connection_id' => null,
            'source' => SocialEngagementTargetPaidAdSnapshot::SOURCE_META_MARKETING,
            'account_id' => $connection->ad_account_id,
        ]);
    }

    public function forGoogleAds(GoogleAdsConnection $connection): static
    {
        return $this->state(fn (): array => [
            'meta_marketing_connection_id' => null,
            'google_ads_connection_id' => $connection->getKey(),
            'source' => SocialEngagementTargetPaidAdSnapshot::SOURCE_GOOGLE_ADS,
            'account_id' => $connection->customer_id,
            'reach' => null,
        ]);
    }
}
