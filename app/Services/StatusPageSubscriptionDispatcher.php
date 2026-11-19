<?php

namespace App\Services;

use App\Jobs\SendNotificationOutbox;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\NotificationOutbox;
use App\Models\StatusPage;
use App\Models\StatusPageSubscription;
use Illuminate\Support\Facades\URL;

class StatusPageSubscriptionDispatcher
{
    public function queue(Incident $incident, Monitor $monitor, string $eventType): bool
    {
        if (! in_array($eventType, ['opened', 'recovered'], true) || ! $monitor->public_status) {
            return false;
        }

        $statusPage = StatusPage::query()
            ->where('setting_key', 'default')
            ->where('is_published', true)
            ->first();

        if ($statusPage === null) {
            return false;
        }

        $subscriptions = StatusPageSubscription::query()
            ->where('status_page_id', $statusPage->id)
            ->whereNotNull('confirmed_at')
            ->whereNull('unsubscribed_at')
            ->whereNotNull('email')
            ->orderBy('id')
            ->get();
        $eventLabel = $eventType === 'opened' ? '服務異常' : '服務恢復';
        $outboxEventType = $eventType === 'opened'
            ? NotificationOutbox::EVENT_TYPE_STATUS_OPENED
            : NotificationOutbox::EVENT_TYPE_STATUS_RECOVERED;
        $queued = false;

        foreach ($subscriptions as $subscription) {
            $unsubscribeUrl = URL::signedRoute('status-page-subscriptions.unsubscribe', [
                'subscription' => $subscription->id,
            ]);
            $subject = mb_substr('['.$statusPage->title.'] '.$monitor->name.' '.$eventLabel, 0, 180);
            $body = implode("\n", [
                $statusPage->title,
                $monitor->name.'：'.$eventLabel,
                '更新時間（UTC）：'.now()->utc()->toIso8601String(),
                '查看公開狀態頁：'.route('status-pages.public', ['token' => $statusPage->public_token]),
                '取消訂閱：'.$unsubscribeUrl,
            ]);
            $eventKey = hash('sha256', implode(':', [
                'status-page-subscription',
                $subscription->id,
                $incident->id,
                $eventType,
            ]));
            $outbox = NotificationOutbox::query()->firstOrCreate(['event_key' => $eventKey], [
                'incident_id' => $incident->id,
                'monitor_id' => $monitor->id,
                'status_page_subscription_id' => $subscription->id,
                'channel' => NotificationOutbox::CHANNEL_EMAIL,
                'recipient' => $subscription->email,
                'event_type' => $outboxEventType,
                'status' => 'pending',
                'subject' => $subject,
                'body' => $body,
            ]);

            if ($outbox->wasRecentlyCreated) {
                $queued = true;
                SendNotificationOutbox::dispatch($outbox->id)->afterCommit();
            }
        }

        return $queued;
    }
}
