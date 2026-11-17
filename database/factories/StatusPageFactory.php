<?php

namespace Database\Factories;

use App\Models\StatusPage;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<StatusPage>
 */
class StatusPageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $token = Str::random(64);

        return [
            'setting_key' => (string) Str::uuid(),
            'title' => '服務狀態',
            'public_token' => $token,
            'public_token_hash' => hash('sha256', $token),
            'is_published' => false,
        ];
    }
}
