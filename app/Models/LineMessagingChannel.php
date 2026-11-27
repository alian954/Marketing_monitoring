<?php

namespace App\Models;

use Database\Factories\LineMessagingChannelFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['user_id', 'workspace_id', 'channel_access_token', 'channel_secret', 'bot_user_id', 'bot_display_name', 'bot_basic_id'])]
#[Hidden(['user_id', 'workspace_id', 'channel_access_token', 'channel_secret', 'webhook_key'])]
class LineMessagingChannel extends Model
{
    /** @use HasFactory<LineMessagingChannelFactory> */
    use BelongsToAuthenticatedWorkspace, HasFactory;

    protected static function booted(): void
    {
        static::creating(static function (LineMessagingChannel $channel): void {
            if ($channel->webhook_key === null) {
                $channel->forceFill(['webhook_key' => Str::random(64)]);
            }
        });
    }

    public function targets(): HasMany
    {
        return $this->hasMany(LineNotificationTarget::class);
    }

    protected function casts(): array
    {
        return [
            'channel_access_token' => 'encrypted',
            'channel_secret' => 'encrypted',
            'last_webhook_received_at' => 'immutable_datetime',
        ];
    }
}
