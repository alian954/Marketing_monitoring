<?php

namespace App\Models;

use Database\Factories\NotificationOutboxFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['incident_id', 'monitor_id', 'status_page_subscription_id', 'event_key', 'channel', 'recipient', 'event_type', 'status', 'attempts', 'attempt_started_at', 'sent_at', 'subject', 'body', 'last_error'])]
#[Hidden(['recipient'])]
class NotificationOutbox extends Model
{
    public const CHANNEL_EMAIL = 'email';

    public const CHANNEL_SLACK = 'slack';

    public const EVENT_TYPE_TEST = 'test';

    public const EVENT_TYPE_ESCALATION = 'escalation';

    public const EVENT_TYPE_ESCALATION_STAGE_2 = 'escalation_stage_2';

    public const EVENT_TYPE_REMINDER = 'reminder';

    public const SNOOZABLE_EVENT_TYPES = [
        self::EVENT_TYPE_REMINDER,
        self::EVENT_TYPE_ESCALATION,
        self::EVENT_TYPE_ESCALATION_STAGE_2,
    ];

    public const EVENT_TYPE_STATUS_OPENED = 'status_opened';

    public const EVENT_TYPE_STATUS_RECOVERED = 'status_recovered';

    protected $table = 'notification_outbox';

    /** @use HasFactory<NotificationOutboxFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'recipient' => 'encrypted',
            'attempt_started_at' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
        ];
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function monitor(): BelongsTo
    {
        return $this->belongsTo(Monitor::class);
    }

    public function statusPageSubscription(): BelongsTo
    {
        return $this->belongsTo(StatusPageSubscription::class);
    }
}
