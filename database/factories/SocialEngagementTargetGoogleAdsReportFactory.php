<?php

namespace Database\Factories;

use App\Models\GoogleAdsConnection;
use App\Models\SocialEngagementTarget;
use App\Models\SocialEngagementTargetGoogleAdsReport;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SocialEngagementTargetGoogleAdsReport>
 */
class SocialEngagementTargetGoogleAdsReportFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'google_ads_connection_id' => GoogleAdsConnection::factory(),
            'social_engagement_target_id' => SocialEngagementTarget::factory(),
            'customer_id' => fake()->numerify('##########'),
            'campaign_id' => fake()->numerify('##################'),
            'campaign_name' => fake()->words(3, true),
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-30',
            'spend_amount' => '100.0000',
            'impressions' => 1000,
            'clicks' => 50,
            'conversions' => '5.0000',
            'conversions_value' => '250.0000',
            'currency_code' => 'TWD',
            'synced_at' => now(),
        ];
    }
}
