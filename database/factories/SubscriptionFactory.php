<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subscription>
 */
class SubscriptionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'status' => 'active',
            'starts_at' => now()->subDay(),
            'current_period_starts_at' => now()->subDay(),
            'current_period_ends_at' => null,
            'trial_ends_at' => null,
            'cancel_at_period_end' => false,
            'canceled_at' => null,
            'billing_provider' => null,
            'provider_subscription_id' => null,
        ];
    }
}
