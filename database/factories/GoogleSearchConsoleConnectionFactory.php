<?php

namespace Database\Factories;

use App\Models\GoogleSearchConsoleConnection;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GoogleSearchConsoleConnection>
 */
class GoogleSearchConsoleConnectionFactory extends Factory
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
            'site_url' => 'https://example.com/',
            'refresh_token' => fake()->uuid(),
        ];
    }
}
