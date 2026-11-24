<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\GoogleAnalyticsClientProperty;
use App\Models\GoogleAnalyticsConnection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<GoogleAnalyticsClientProperty>
 */
class GoogleAnalyticsClientPropertyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'google_analytics_connection_id' => function (array $attributes): int {
                $client = Client::query()->findOrFail($attributes['client_id']);
                $connection = GoogleAnalyticsConnection::query()->firstOrCreate(
                    ['user_id' => $client->user_id],
                    ['refresh_token' => 'factory-'.$client->user_id.'-'.Str::random(16)],
                );

                return (int) $connection->getKey();
            },
            'property_id' => fake()->numerify('##########'),
        ];
    }
}
