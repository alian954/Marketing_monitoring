<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\GoogleAdsClientAccount;
use App\Models\GoogleAdsConnection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<GoogleAdsClientAccount>
 */
class GoogleAdsClientAccountFactory extends Factory
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
            'google_ads_connection_id' => function (array $attributes): int {
                $client = Client::withoutGlobalScopes()->findOrFail($attributes['client_id']);
                $connection = GoogleAdsConnection::query()->firstOrCreate(
                    ['user_id' => $client->user_id],
                    ['refresh_token' => 'factory-'.$client->user_id.'-'.Str::random(16)],
                );

                return (int) $connection->getKey();
            },
            'customer_id' => fake()->numerify('##########'),
            'login_customer_id' => null,
            'customer_name' => fake()->company(),
            'currency_code' => 'TWD',
        ];
    }
}
