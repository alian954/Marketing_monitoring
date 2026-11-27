<?php

namespace Database\Factories;

use App\Models\IntegrationApplicationCredentialEvent;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IntegrationApplicationCredentialEvent>
 */
class IntegrationApplicationCredentialEventFactory extends Factory
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
            'actor_user_id' => User::factory(),
            'provider_code' => 'google_analytics',
            'action' => 'updated',
        ];
    }
}
