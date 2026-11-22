<?php

namespace Database\Factories;

use App\Models\GoogleAnalyticsCampaignSnapshot;
use App\Models\GoogleAnalyticsConnection;
use App\Models\SocialEngagementTarget;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GoogleAnalyticsCampaignSnapshot>
 */
class GoogleAnalyticsCampaignSnapshotFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'google_analytics_connection_id' => fn (array $attributes): int => GoogleAnalyticsConnection::query()->create([
                'user_id' => User::factory()->create()->getKey(),
                'property_id' => '123456789',
                'refresh_token' => 'test-refresh-token',
            ])->getKey(),
            'social_engagement_target_id' => SocialEngagementTarget::factory(),
            'snapshot_date' => today()->toDateString(),
            'campaign_name' => fake()->slug(3),
            'source_name' => 'facebook',
            'starts_on' => today()->subDays(7)->toDateString(),
            'ends_on' => today()->addDays(23)->toDateString(),
            'sessions' => fake()->numberBetween(0, 10000),
            'engaged_sessions' => fake()->numberBetween(0, 10000),
            'key_events' => fake()->randomFloat(4, 0, 500),
            'total_revenue' => fake()->randomFloat(4, 0, 100000),
            'currency_code' => 'TWD',
            'synced_at' => now(),
        ];
    }
}
