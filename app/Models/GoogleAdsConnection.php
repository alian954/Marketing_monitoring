<?php

namespace App\Models;

use Database\Factories\GoogleAdsConnectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'user_id',
    'refresh_token',
    'customer_id',
    'login_customer_id',
    'customer_name',
    'currency_code',
    'scheduled_sync_status',
    'scheduled_sync_started_at',
    'scheduled_sync_finished_at',
    'scheduled_sync_synced_count',
    'scheduled_sync_skipped_count',
    'scheduled_sync_failed_count',
    'scheduled_sync_error',
])]
#[Hidden(['refresh_token'])]
class GoogleAdsConnection extends Model
{
    /** @use HasFactory<GoogleAdsConnectionFactory> */
    use HasFactory;

    public function clientAccounts(): HasMany
    {
        return $this->hasMany(GoogleAdsClientAccount::class, 'google_ads_connection_id');
    }

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
