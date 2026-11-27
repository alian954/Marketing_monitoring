<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An AI-written performance summary for one client and reporting period.
 *
 * The data sent to the model is captured when the request is made, so the queued job never
 * needs the requesting user's workspace scope to read marketing data.
 */
#[Fillable(['from_on', 'to_on', 'status', 'input_payload', 'requested_by_user_id'])]
#[Hidden(['input_payload'])]
class ClientMarketingInsight extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /** @return array{summary: string, highlights: list<string>, issues: list<array{title: string, detail: string}>, recommendations: list<string>}|null */
    public function sections(): ?array
    {
        $content = $this->content;

        if (! is_array($content) || ! is_string($content['summary'] ?? null)) {
            return null;
        }

        $strings = static fn (mixed $items): array => array_values(array_filter(
            is_array($items) ? $items : [],
            static fn (mixed $item): bool => is_string($item) && $item !== '',
        ));

        return [
            'summary' => $content['summary'],
            'highlights' => $strings($content['highlights'] ?? []),
            'issues' => array_values(array_filter(
                is_array($content['issues'] ?? null) ? $content['issues'] : [],
                static fn (mixed $issue): bool => is_array($issue) && is_string($issue['title'] ?? null) && is_string($issue['detail'] ?? null),
            )),
            'recommendations' => $strings($content['recommendations'] ?? []),
        ];
    }

    protected function casts(): array
    {
        return [
            'from_on' => 'immutable_date',
            'to_on' => 'immutable_date',
            'input_payload' => 'encrypted:array',
            'content' => 'array',
            'completed_at' => 'immutable_datetime',
        ];
    }
}
