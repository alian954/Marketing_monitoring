<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\ClientLead;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClientLead>
 */
class ClientLeadFactory extends Factory
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
            'lead_reference' => 'lead:'.fake()->unique()->numerify('######'),
            'created_on' => fake()->dateTimeBetween('-30 days', 'now')->format('Y-m-d'),
            'platform' => 'meta_marketing',
            'campaign_name' => null,
            'stage' => ClientLead::STAGE_NEW,
            'deal_value' => null,
            'won_on' => null,
            'currency_code' => 'TWD',
            'google_click_id' => null,
        ];
    }
}
