<?php

namespace App\Models;

use Database\Factories\GoogleSearchConsoleConnectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['user_id', 'site_url', 'refresh_token'])]
#[Hidden(['workspace_id', 'refresh_token'])]
class GoogleSearchConsoleConnection extends Model
{
    /** @use HasFactory<GoogleSearchConsoleConnectionFactory> */
    use BelongsToAuthenticatedWorkspace, HasFactory;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function clientSites(): HasMany
    {
        return $this->hasMany(GoogleSearchConsoleClientSite::class);
    }

    protected function casts(): array
    {
        return [
            'refresh_token' => 'encrypted',
        ];
    }
}
