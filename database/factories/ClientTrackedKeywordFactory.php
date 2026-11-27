<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\ClientTrackedKeyword;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClientTrackedKeyword>
 */
class ClientTrackedKeywordFactory extends Factory
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
            'keyword' => mb_strtolower(fake()->unique()->words(2, true)),
            'target_position' => null,
        ];
    }
}
