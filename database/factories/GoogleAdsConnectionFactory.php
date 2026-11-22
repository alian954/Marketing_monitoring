<?php

namespace Database\Factories;

use App\Models\GoogleAdsConnection;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GoogleAdsConnection>
 */
class GoogleAdsConnectionFactory extends Factory
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
            'refresh_token' => fake()->sha256(),
            'customer_id' => fake()->numerify('##########'),
            'login_customer_id' => null,
            'customer_name' => fake()->company(),
            'currency_code' => 'TWD',
        ];
    }
}
