<?php

namespace App\Models;

use Database\Factories\PlatformAuditEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable(['workspace_id', 'actor_user_id', 'action', 'subject_type', 'subject_id', 'metadata', 'ip_address'])]
class PlatformAuditEvent extends Model
{
    /** @use HasFactory<PlatformAuditEventFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Platform audit events are append-only.');
        });
        static::deleting(static function (): never {
            throw new LogicException('Platform audit events are append-only.');
        });
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function actionLabel(): string
    {
        return $this->action?->label() ?? '未知操作';
    }

    /**
     * Resolve member names referenced by a page of events in one query.
     *
     * @param  iterable<PlatformAuditEvent>  $events
     * @return array<int, string>
     */
    public static function memberNamesFor(iterable $events): array
    {
        $memberUserIds = collect($events)
            ->map(static fn (PlatformAuditEvent $event): mixed => $event->metadata['member_user_id'] ?? null)
            ->filter(static fn (mixed $memberUserId): bool => is_int($memberUserId))
            ->unique()
            ->values();

        if ($memberUserIds->isEmpty()) {
            return [];
        }

        return User::query()->whereKey($memberUserIds)->pluck('name', 'id')->all();
    }

    /**
     * Human-readable lines describing the event metadata.
     *
     * @param  array<int, string>  $memberNames  Member display names keyed by user ID.
     * @return list<string>
     */
    public function detailLines(array $memberNames = []): array
    {
        $lines = [];

        foreach ($this->metadata ?? [] as $key => $value) {
            if ($key === 'member_user_id' && is_int($value)) {
                $lines[] = '成員：'.($memberNames[$value] ?? '已刪除帳號');

                continue;
            }

            if ($key === 'changes' && is_array($value)) {
                foreach ($value as $field => $change) {
                    $lines[] = self::fieldLabel((string) $field).'：'
                        .self::displayValue($change['before'] ?? null, (string) $field).' → '
                        .self::displayValue($change['after'] ?? null, (string) $field);
                }

                continue;
            }

            if (in_array($key, ['before', 'after'], true) && is_array($value)) {
                $lines[] = ($key === 'before' ? '變更前' : '變更後').'：'.collect($value)
                    ->map(static fn (mixed $fieldValue, string $field): string => self::fieldLabel($field).' '.self::displayValue($fieldValue, $field))
                    ->implode('；');

                continue;
            }

            $lines[] = self::fieldLabel((string) $key).'：'.self::displayValue($value, (string) $key);
        }

        return $lines;
    }

    private static function fieldLabel(string $field): string
    {
        return [
            'name' => '名稱',
            'plan_name' => '方案名稱',
            'subscription_ends_on' => '訂閱到期日',
            'seat_limit' => '席次上限',
            'client_limit' => '客戶上限',
            'monitor_limit' => '監測項目上限',
            'campaign_limit' => '活動上限',
            'is_enabled' => '啟用',
            'entitlements_enforced' => '依訂閱授權',
            'lifecycle' => '產品狀態',
            'product_code' => '產品代碼',
            'product_id' => '產品',
            'plan_code' => '方案代碼',
            'product_plan_id' => '方案',
            'version' => '版本',
            'status' => '狀態',
            'billing_interval' => '計費週期',
            'amount_minor' => '金額（最小單位）',
            'currency_code' => '幣別',
            'subscription_id' => '訂閱',
            'quantity' => '數量',
            'starts_at' => '開始時間',
            'ends_at' => '結束時間',
            'current_period_starts_at' => '本期開始',
            'current_period_ends_at' => '本期結束',
            'trial_ends_at' => '試用到期',
            'cancel_at_period_end' => '期末取消',
            'role' => '權限',
            'client_scope' => '客戶範圍',
            'client_ids' => '指定客戶',
            'member_user_id' => '成員帳號',
            'invitation_id' => '邀請',
            'created_account' => '新建帳號',
            'order_number' => '訂單編號',
            'source_order_number' => '來源訂單',
            'total_minor' => '訂單金額（最小單位）',
            'item_count' => '項目數',
            'method' => '收款方式',
            'order_status' => '訂單狀態',
            'reason' => '原因',
        ][$field] ?? $field;
    }

    private const VALUE_LABELS = [
        'role' => ['read_only' => '唯讀', 'editor' => '可編輯'],
        'client_scope' => ['all' => '全部客戶', 'selected' => '指定客戶'],
        'lifecycle' => ['active' => '啟用中', 'maintenance' => '維護中', 'retired' => '已停售'],
        'method' => ['manual_bank_transfer' => '銀行轉帳', 'manual_cash' => '現金', 'manual_other' => '其他人工收款'],
        'order_status' => ['issued' => '待收款', 'paid' => '已收款', 'canceled' => '已取消', 'refunded' => '已退款'],
    ];

    private static function displayValue(mixed $value, ?string $field = null): string
    {
        if (is_string($value) && $field !== null && isset(self::VALUE_LABELS[$field][$value])) {
            return self::VALUE_LABELS[$field][$value];
        }

        return match (true) {
            $value === null, $value === [] => '未設定',
            $value === true => '是',
            $value === false => '否',
            is_array($value) => implode('、', array_map(static fn (mixed $item): string => self::displayValue($item), $value)),
            default => (string) $value,
        };
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'action' => PlatformAuditAction::class,
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
