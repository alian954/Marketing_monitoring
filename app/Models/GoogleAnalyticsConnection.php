<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'user_id',
    'property_id',
    'refresh_token',
    'scheduled_sync_status',
    'scheduled_sync_started_at',
    'scheduled_sync_finished_at',
    'scheduled_sync_synced_count',
    'scheduled_sync_skipped_count',
    'scheduled_sync_failed_count',
    'scheduled_sync_error',
])]
class GoogleAnalyticsConnection extends Model
{
    protected function casts(): array
    {
        return [
            'refresh_token' => 'encrypted',
            'scheduled_sync_started_at' => 'immutable_datetime',
            'scheduled_sync_finished_at' => 'immutable_datetime',
            'scheduled_sync_synced_count' => 'integer',
            'scheduled_sync_skipped_count' => 'integer',
            'scheduled_sync_failed_count' => 'integer',
        ];
    }
}
