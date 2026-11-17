<?php

namespace App\Models;

use Database\Factories\NotificationOutboxFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['incident_id', 'event_key', 'recipient', 'event_type', 'status', 'attempts', 'attempt_started_at', 'sent_at', 'subject', 'body', 'last_error'])]
class NotificationOutbox extends Model
{
    protected $table = 'notification_outbox';

    /** @use HasFactory<NotificationOutboxFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['attempt_started_at' => 'immutable_datetime', 'sent_at' => 'immutable_datetime'];
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }
}
