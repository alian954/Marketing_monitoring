<?php

namespace App\Console\Commands;

use App\Models\SubscriptionItem;
use App\Notifications\SubscriptionExpiringNotification;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

#[Signature('billing:send-expiry-reminders')]
#[Description('寄送 14 天內到期的產品訂閱提醒給組織擁有者。')]
class SendSubscriptionExpiryReminders extends Command
{
    private const REMINDER_WINDOW_DAYS = 14;

    public function handle(): int
    {
        $now = now();
        $sentCount = 0;
        $failedCount = 0;

        $dueItems = SubscriptionItem::query()
            ->with(['product:id,name', 'organization.owner'])
            ->where('status', 'active')
            ->whereNotNull('ends_at')
            ->where('ends_at', '>', $now)
            ->where('ends_at', '<=', $now->copy()->addDays(self::REMINDER_WINDOW_DAYS))
            ->where(static function (Builder $query): void {
                $query->whereNull('expiry_reminder_sent_for')->orWhereColumn('expiry_reminder_sent_for', '<>', 'ends_at');
            })
            ->whereHas('subscription', static fn (Builder $query): Builder => $query->whereIn('status', ['active', 'trialing']))
            ->orderBy('ends_at')
            ->get();

        foreach ($dueItems->groupBy('organization_id') as $organizationItems) {
            /** @var Collection<int, SubscriptionItem> $organizationItems */
            $organization = $organizationItems->first()->organization;
            $owner = $organization?->owner;

            if ($owner === null || ! $organization->isAccessible()) {
                continue;
            }

            try {
                $owner->notify(new SubscriptionExpiringNotification(
                    $organization->name,
                    $organizationItems->map(static fn (SubscriptionItem $item): array => [
                        'product_name' => $item->product->name,
                        'ends_at' => $item->ends_at,
                    ])->values()->all(),
                ));
            } catch (Throwable $exception) {
                $failedCount++;
                Log::warning('Subscription expiry reminder could not be sent.', [
                    'organization_id' => $organization->getKey(),
                    'exception' => $exception::class,
                ]);

                continue;
            }

            foreach ($organizationItems as $item) {
                $item->forceFill(['expiry_reminder_sent_for' => $item->ends_at])->save();
            }

            $sentCount++;
        }

        $this->info("已寄送 {$sentCount} 份到期提醒，失敗 {$failedCount} 份。");

        return $failedCount === 0 ? self::SUCCESS : self::FAILURE;
    }
}
