<?php

namespace App\Models;

use Database\Factories\GoogleAdsClientAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['google_ads_connection_id', 'client_id', 'customer_id', 'login_customer_id', 'customer_name', 'currency_code'])]
class GoogleAdsClientAccount extends Model
{
    /** @use HasFactory<GoogleAdsClientAccountFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::addGlobalScope(new AuthenticatedRelationScope('client'));
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(GoogleAdsConnection::class, 'google_ads_connection_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
