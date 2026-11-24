<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\GoogleSearchConsoleClientSite;
use App\Models\GoogleSearchConsoleConnection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GoogleSearchConsoleClientSite>
 */
class GoogleSearchConsoleClientSiteFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'google_search_console_connection_id' => GoogleSearchConsoleConnection::factory(),
            'client_id' => Client::factory(),
            'site_url' => 'https://example.com/',
        ];
    }
}
