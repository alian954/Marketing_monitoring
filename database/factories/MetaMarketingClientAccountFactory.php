<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\MetaMarketingClientAccount;
use App\Models\MetaMarketingConnection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<MetaMarketingClientAccount>
 */
class MetaMarketingClientAccountFactory extends Factory
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
            'meta_marketing_connection_id' => function (array $attributes): int {
                $client = Client::withoutGlobalScopes()->findOrFail($attributes['client_id']);
                $connection = MetaMarketingConnection::query()->firstOrCreate(
                    ['user_id' => $client->user_id],
                    ['access_token' => 'factory-'.$client->user_id.'-'.Str::random(16)],
                );

                return (int) $connection->getKey();
            },
            'ad_account_id' => fake()->numerify('#########'),
            'ad_account_name' => fake()->company(),
            'currency_code' => 'TWD',
        ];
    }
}
