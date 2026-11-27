<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\ClientMarketingAnnotation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClientMarketingAnnotation>
 */
class ClientMarketingAnnotationFactory extends Factory
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
            'occurred_on' => fake()->dateTimeBetween('-30 days', 'now')->format('Y-m-d'),
            'category' => fake()->randomElement(array_keys(ClientMarketingAnnotation::CATEGORIES)),
            'title' => fake()->sentence(4),
            'note' => null,
        ];
    }
}
