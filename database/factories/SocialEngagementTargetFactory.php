<?php

namespace Database\Factories;

use App\Models\SocialEngagementTarget;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SocialEngagementTarget>
 */
class SocialEngagementTargetFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tag' => fake()->words(2, true),
            'social_platform' => fake()->randomElement(['facebook', 'instagram', 'threads', 'dcard']),
            'target_interactions' => fake()->numberBetween(100, 10000),
            'starts_on' => null,
            'ends_on' => null,
        ];
    }
}
