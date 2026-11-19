<?php

namespace Database\Factories;

use App\Models\StatusPage;
use App\Models\StatusPageSubscription;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<StatusPageSubscription>
 */
class StatusPageSubscriptionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $email = fake()->unique()->safeEmail();
        $confirmationToken = Str::random(64);

        return [
            'status_page_id' => StatusPage::factory(),
            'email' => $email,
            'email_hash' => hash_hmac('sha256', mb_strtolower($email), (string) config('app.key')),
            'confirmation_token' => $confirmationToken,
            'confirmation_token_hash' => hash('sha256', $confirmationToken),
            'confirmation_expires_at' => now()->addDay(),
            'confirmed_at' => now(),
            'unsubscribed_at' => null,
        ];
    }
}
