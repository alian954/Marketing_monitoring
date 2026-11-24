<?php

namespace App\Models;

use Database\Factories\GoogleAnalyticsClientPropertyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['google_analytics_connection_id', 'client_id', 'property_id'])]
class GoogleAnalyticsClientProperty extends Model
{
    /** @use HasFactory<GoogleAnalyticsClientPropertyFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::addGlobalScope(new AuthenticatedRelationScope('client'));
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(GoogleAnalyticsConnection::class, 'google_analytics_connection_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
