<?php

namespace App\Models;

use Database\Factories\StatusPageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;

#[Fillable(['setting_key', 'title', 'public_token', 'public_token_hash', 'is_published'])]
class StatusPage extends Model
{
    /** @use HasFactory<StatusPageFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::addGlobalScope(new AuthenticatedAccountScope);

        static::creating(static function (StatusPage $statusPage): void {
            $user = Auth::user();

            if ($user instanceof User) {
                $statusPage->forceFill(['user_id' => $user->workspaceOwnerId()]);
            }
        });
    }

    protected $hidden = ['user_id', 'public_token', 'public_token_hash'];

    protected function casts(): array
    {
        return [
            'public_token' => 'encrypted',
            'is_published' => 'boolean',
        ];
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(StatusPageSubscription::class);
    }
}
