<?php

namespace App\Models;

use Database\Factories\StatusPageSubscriptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['status_page_id', 'email', 'email_hash', 'confirmation_token', 'confirmation_token_hash', 'confirmation_expires_at', 'confirmed_at', 'unsubscribed_at'])]
#[Hidden(['email', 'email_hash', 'confirmation_token', 'confirmation_token_hash'])]
class StatusPageSubscription extends Model
{
    /** @use HasFactory<StatusPageSubscriptionFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'email' => 'encrypted',
            'confirmation_token' => 'encrypted',
            'confirmation_expires_at' => 'immutable_datetime',
            'confirmed_at' => 'immutable_datetime',
            'unsubscribed_at' => 'immutable_datetime',
        ];
    }

    public function statusPage(): BelongsTo
    {
        return $this->belongsTo(StatusPage::class);
    }

    public function outboxMessages(): HasMany
    {
        return $this->hasMany(NotificationOutbox::class);
    }

    public function isActive(): bool
    {
        return $this->confirmed_at !== null && $this->unsubscribed_at === null;
    }
}
