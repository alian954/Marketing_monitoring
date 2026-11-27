<?php

namespace Database\Factories;

use App\Models\LineMessagingChannel;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<LineMessagingChannel>
 */
class LineMessagingChannelFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'workspace_id' => Workspace::factory(),
            'channel_access_token' => Str::random(120),
            'channel_secret' => Str::random(32),
            'bot_user_id' => 'U'.Str::lower(Str::random(32)),
            'bot_display_name' => '行銷警示小幫手',
            'bot_basic_id' => '@'.Str::lower(Str::random(8)),
        ];
    }
}
