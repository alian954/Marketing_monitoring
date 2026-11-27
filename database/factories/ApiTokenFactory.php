<?php

namespace Database\Factories;

use App\Models\ApiToken;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ApiToken>
 */
class ApiTokenFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $plainText = ApiToken::PREFIX.bin2hex(random_bytes(24));

        return [
            'user_id' => User::factory(),
            'workspace_owner_user_id' => fn (array $attributes): mixed => $attributes['user_id'],
            'name' => 'Looker Studio',
            'token_hash' => ApiToken::hash($plainText),
            'token_prefix' => substr($plainText, 0, 10),
            'expires_at' => null,
        ];
    }
}
