<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\ClientReportShare;
use App\Models\ClientReportTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClientReportTemplate>
 */
class ClientReportTemplateFactory extends Factory
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
            'name' => fake()->words(3, true),
            'report_brand_name' => null,
            'campaign_target_ids' => [],
            'report_sections' => ClientReportShare::DEFAULT_REPORT_SECTIONS,
            'compare_previous_period' => false,
        ];
    }
}
