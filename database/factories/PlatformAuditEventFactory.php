<?php

namespace Database\Factories;

use App\Models\PlatformAuditAction;
use App\Models\PlatformAuditEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlatformAuditEvent>
 */
class PlatformAuditEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => null,
            'actor_user_id' => User::factory(),
            'action' => PlatformAuditAction::OrganizationUpdated,
            'subject_type' => 'organization',
            'subject_id' => fake()->numberBetween(1, 1000),
            'metadata' => [],
            'ip_address' => fake()->ipv4(),
        ];
    }
}
