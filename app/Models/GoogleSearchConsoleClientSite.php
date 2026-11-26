<?php

namespace App\Models;

use Database\Factories\GoogleSearchConsoleClientSiteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['google_search_console_connection_id', 'client_id', 'site_url'])]
#[Hidden(['workspace_id'])]
class GoogleSearchConsoleClientSite extends Model
{
    use BelongsToAuthenticatedClientWorkspace;

    /** @use HasFactory<GoogleSearchConsoleClientSiteFactory> */
    use HasFactory;

    public function connection(): BelongsTo
    {
        return $this->belongsTo(GoogleSearchConsoleConnection::class, 'google_search_console_connection_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
