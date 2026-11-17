<?php

namespace Database\Factories;

use App\Models\Incident;
use App\Models\NotificationOutbox;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<NotificationOutbox>
 */
class NotificationOutboxFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'incident_id' => Incident::factory(),
            'event_key' => hash('sha256', (string) Str::uuid()),
            'recipient' => 'owner@example.com',
            'event_type' => 'opened',
            'status' => 'pending',
            'attempts' => 0,
            'attempt_started_at' => null,
            'sent_at' => null,
            'subject' => 'Monitor incident opened',
            'body' => 'A test monitor has failed twice.',
            'last_error' => null,
        ];
    }
}
