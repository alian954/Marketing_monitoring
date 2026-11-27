<?php

namespace Database\Factories;

use App\Models\LineMessagingChannel;
use App\Models\LineNotificationTarget;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LineNotificationTarget>
 */
class LineNotificationTargetFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'line_messaging_channel_id' => LineMessagingChannel::factory(),
            'workspace_id' => static fn (array $attributes): int => (int) LineMessagingChannel::query()
                ->withoutGlobalScopes()
                ->whereKey($attributes['line_messaging_channel_id'])
                ->value('workspace_id'),
            'target_type' => LineNotificationTarget::TYPE_GROUP,
            'target_id' => 'C'.fake()->regexify('[0-9a-f]{32}'),
            'display_name' => '客戶專案群組',
            'joined_at' => now(),
            'left_at' => null,
        ];
    }

    public function left(): static
    {
        return $this->state(fn (): array => ['left_at' => now()]);
    }
}
