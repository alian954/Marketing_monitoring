<?php

namespace App\Models;

use Database\Factories\LineNotificationTargetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['line_messaging_channel_id', 'workspace_id', 'target_type', 'target_id', 'display_name', 'joined_at', 'left_at'])]
#[Hidden(['workspace_id', 'target_id'])]
class LineNotificationTarget extends Model
{
    public const TYPE_USER = 'user';

    public const TYPE_GROUP = 'group';

    public const TYPE_ROOM = 'room';

    /** @var array<string, string> */
    public const TYPE_LABELS = [
        self::TYPE_USER => '個人好友',
        self::TYPE_GROUP => '群組',
        self::TYPE_ROOM => '多人聊天室',
    ];

    /** @use HasFactory<LineNotificationTargetFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::addGlobalScope('authenticated_workspace', static function (Builder $query): void {
            if (AccountScopeContext::authenticatedUserId() === null) {
                return;
            }

            $query->where(
                $query->getModel()->qualifyColumn('workspace_id'),
                AccountScopeContext::authenticatedWorkspaceId() ?? 0,
            );
        });
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(LineMessagingChannel::class, 'line_messaging_channel_id');
    }

    /**
     * @param  Builder<LineNotificationTarget>  $query
     * @return Builder<LineNotificationTarget>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('left_at');
    }

    public function isActive(): bool
    {
        return $this->left_at === null;
    }

    public function label(): string
    {
        $typeLabel = self::TYPE_LABELS[$this->target_type] ?? $this->target_type;
        $name = trim((string) $this->display_name);

        return $name === '' ? $typeLabel.'（未取得名稱）' : $name.'（'.$typeLabel.'）';
    }

    protected function casts(): array
    {
        return [
            'joined_at' => 'immutable_datetime',
            'left_at' => 'immutable_datetime',
        ];
    }
}
