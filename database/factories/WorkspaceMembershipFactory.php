<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\WorkspaceMembership;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<WorkspaceMembership>
 */
class WorkspaceMembershipFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'owner_user_id' => User::factory(),
            'member_user_id' => User::factory(),
            'role' => WorkspaceMembership::ROLE_READ_ONLY,
            'accepted_at' => Carbon::now(),
            'revoked_at' => null,
        ];
    }
}
