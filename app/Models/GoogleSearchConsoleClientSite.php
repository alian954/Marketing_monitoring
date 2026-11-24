<?php

namespace App\Models;

use Database\Factories\GoogleSearchConsoleClientSiteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['google_search_console_connection_id', 'client_id', 'site_url'])]
class GoogleSearchConsoleClientSite extends Model
{
    /** @use HasFactory<GoogleSearchConsoleClientSiteFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::addGlobalScope(new AuthenticatedRelationScope('client'));
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(GoogleSearchConsoleConnection::class, 'google_search_console_connection_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
