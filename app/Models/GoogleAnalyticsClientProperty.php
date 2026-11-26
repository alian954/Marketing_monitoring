<?php

namespace App\Models;

use Database\Factories\GoogleAnalyticsClientPropertyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['google_analytics_connection_id', 'client_id', 'property_id'])]
#[Hidden(['workspace_id'])]
class GoogleAnalyticsClientProperty extends Model
{
    use BelongsToAuthenticatedClientWorkspace;

    /** @use HasFactory<GoogleAnalyticsClientPropertyFactory> */
    use HasFactory;

    public function connection(): BelongsTo
    {
        return $this->belongsTo(GoogleAnalyticsConnection::class, 'google_analytics_connection_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
