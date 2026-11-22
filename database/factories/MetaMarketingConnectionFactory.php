<?php

namespace Database\Factories;

use App\Models\MetaMarketingConnection;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MetaMarketingConnection>
 */
class MetaMarketingConnectionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'access_token' => fake()->sha256(),
            'token_expires_at' => now()->addDays(30),
            'ad_account_id' => fake()->numerify('#########'),
            'ad_account_name' => fake()->company(),
            'currency_code' => 'TWD',
        ];
    }
}
