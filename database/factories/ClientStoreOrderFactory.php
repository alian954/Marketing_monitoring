<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\ClientStoreOrder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClientStoreOrder>
 */
class ClientStoreOrderFactory extends Factory
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
            'order_reference' => 'order:'.fake()->unique()->numerify('#######'),
            'ordered_on' => fake()->dateTimeBetween('-30 days', 'now')->format('Y-m-d'),
            'currency_code' => 'TWD',
            'revenue' => (string) fake()->numberBetween(300, 5000),
            'orders_count' => 1,
            'new_customer_orders' => null,
            'source_label' => null,
        ];
    }
}
