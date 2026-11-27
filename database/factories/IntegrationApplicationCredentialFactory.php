<?php

namespace Database\Factories;

use App\Models\IntegrationApplicationCredential;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IntegrationApplicationCredential>
 */
class IntegrationApplicationCredentialFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'provider_code' => 'google_analytics',
            'client_id' => $this->faker->uuid().'@apps.googleusercontent.com',
            'client_secret' => $this->faker->sha256(),
            'last_updated_by_user_id' => User::factory(),
        ];
    }
}
