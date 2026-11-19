<?php

namespace App\Http\Controllers;

use App\Models\Monitor;
use App\Models\NotificationOutbox;
use App\Services\NotificationTestDispatcher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class NotificationController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $this->validatedFilters($request);

        $notifications = $this->notificationQuery($filters)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();
        $monitors = Monitor::query()->orderBy('name')->get(['id', 'name']);

        $notifications->getCollection()->each(function (NotificationOutbox $notification): void {
            $notification->setAttribute('masked_recipient', $this->maskedRecipient($notification));
        });

        return view('notifications.index', [
            'notifications' => $notifications,
            'filters' => [
                'status' => $filters['status'] ?? '',
                'event_type' => $filters['event_type'] ?? '',
                'channel' => $filters['channel'] ?? '',
                'monitor_id' => (string) ($filters['monitor_id'] ?? ''),
            ],
            'eventLabels' => $this->eventLabels(),
            'channelLabels' => $this->channelLabels(),
            'monitors' => $monitors,
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $filters = $this->validatedFilters($request);

        return response()->streamDownload(function () use ($filters): void {
            $stream = fopen('php://output', 'w');
            $eventLabels = $this->eventLabels();
            fputcsv($stream, ['event_type', 'monitor_name', 'channel', 'status', 'attempts', 'created_at_utc', 'sent_at_utc']);

            $this->notificationQuery($filters)
                ->lazyById()
                ->each(function (NotificationOutbox $notification) use ($stream, $eventLabels): void {
                    $monitorName = $notification->monitor?->name ?? $notification->incident?->monitor?->name ?? '';
                    $eventLabel = $eventLabels[$notification->event_type] ?? '狀態通知';
                    $fields = [
                        $eventLabel,
                        $monitorName,
                        $notification->channel,
                        $notification->status,
                        (string) $notification->attempts,
                        $notification->created_at?->utc()->toIso8601String() ?? '',
                        $notification->sent_at?->utc()->toIso8601String() ?? '',
                    ];

                    fputcsv($stream, array_map($this->escapeCsvFormula(...), $fields), ',', '"', '');
                });

            fclose($stream);
        }, 'notifications.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function sendTestNotification(NotificationTestDispatcher $dispatcher): RedirectResponse
    {
        $queuedCount = $dispatcher->queue();

        if ($queuedCount === 0) {
            return redirect()->route('notifications.index')
                ->withErrors(['notification' => '尚未設定全域通知收件信箱、Slack 或 Discord webhook。']);
        }

        return redirect()->route('notifications.index', ['event_type' => NotificationOutbox::EVENT_TYPE_TEST])
            ->with('status', "已排入 {$queuedCount} 筆測試通知；可在此查看各管道的寄送狀態。");
    }

    public function sendMonitorTestNotification(Monitor $monitor, NotificationTestDispatcher $dispatcher): RedirectResponse
    {
        $queuedCount = $dispatcher->queue($monitor);

        if ($queuedCount === 0) {
            return redirect()->route('monitors.show', $monitor)
                ->withErrors(['notification' => '尚未設定此監測的收件信箱或可用的全域通知管道。']);
        }

        return redirect()->route('notifications.index', [
            'event_type' => NotificationOutbox::EVENT_TYPE_TEST,
            'monitor_id' => $monitor->id,
        ])->with('status', "已排入 {$queuedCount} 筆「{$monitor->name}」測試通知；可查看各收件管道的寄送狀態。");
    }

    /** @return array{status?: string, event_type?: string, channel?: string, monitor_id?: int|string} */
    private function validatedFilters(Request $request): array
    {
        return $request->validate([
            'status' => ['nullable', 'in:pending,sending,sent,failed,unknown,cancelled'],
            'event_type' => ['nullable', 'in:opened,recovered,reminder,'.NotificationOutbox::EVENT_TYPE_ESCALATION.','.NotificationOutbox::EVENT_TYPE_ESCALATION_STAGE_2.',dns_daily_summary,'.NotificationOutbox::EVENT_TYPE_TEST.','.NotificationOutbox::EVENT_TYPE_STATUS_OPENED.','.NotificationOutbox::EVENT_TYPE_STATUS_RECOVERED],
            'channel' => ['nullable', 'in:email,slack,discord'],
            'monitor_id' => ['nullable', 'integer', 'exists:monitors,id'],
        ]);
    }

    /** @param array{status?: string, event_type?: string, channel?: string, monitor_id?: int|string} $filters */
    private function notificationQuery(array $filters): Builder
    {
        return NotificationOutbox::query()
            ->with(['monitor:id,name', 'incident.monitor'])
            ->when($filters['status'] ?? null, fn (Builder $query, string $status): Builder => $query->where('status', $status))
            ->when($filters['event_type'] ?? null, fn (Builder $query, string $eventType): Builder => $query->where('event_type', $eventType))
            ->when($filters['channel'] ?? null, fn (Builder $query, string $channel): Builder => $query->where('channel', $channel))
            ->when($filters['monitor_id'] ?? null, function (Builder $query, int|string $monitorId): void {
                $query->where(function (Builder $query) use ($monitorId): void {
                    $query->where('monitor_id', $monitorId)
                        ->orWhereHas('incident', static fn (Builder $incidentQuery): Builder => $incidentQuery->where('monitor_id', $monitorId));
                });
            });
    }

    /** @return array<string, string> */
    private function eventLabels(): array
    {
        return [
            'opened' => '異常通知',
            'recovered' => '恢復通知',
            'reminder' => '持續事件提醒',
            NotificationOutbox::EVENT_TYPE_ESCALATION => '事件升級通知',
            NotificationOutbox::EVENT_TYPE_ESCALATION_STAGE_2 => '第二階段升級通知',
            'dns_daily_summary' => '每日 DNS 變更摘要',
            NotificationOutbox::EVENT_TYPE_STATUS_OPENED => '公開狀態頁異常更新',
            NotificationOutbox::EVENT_TYPE_STATUS_RECOVERED => '公開狀態頁恢復更新',
            NotificationOutbox::EVENT_TYPE_TEST => '測試通知',
        ];
    }

    /** @return array<string, string> */
    private function channelLabels(): array
    {
        return [
            NotificationOutbox::CHANNEL_EMAIL => '電子郵件',
            NotificationOutbox::CHANNEL_SLACK => 'Slack webhook',
            NotificationOutbox::CHANNEL_DISCORD => 'Discord webhook',
        ];
    }

    private function maskedRecipient(NotificationOutbox $notification): string
    {
        if (in_array($notification->channel, [NotificationOutbox::CHANNEL_SLACK, NotificationOutbox::CHANNEL_DISCORD], true)) {
            return 'Webhook 端點已遮蔽';
        }

        $recipient = (string) $notification->recipient;
        $separatorPosition = strrpos($recipient, '@');

        if ($recipient === '' || $separatorPosition === false) {
            return '收件者已遮蔽';
        }

        $localPart = substr($recipient, 0, $separatorPosition);
        $domain = substr($recipient, $separatorPosition + 1);
        $localPartLength = mb_strlen($localPart);
        $visiblePrefix = mb_substr($localPart, 0, 1);
        $visibleSuffix = $localPartLength > 2 ? mb_substr($localPart, -1) : '';

        return $visiblePrefix.'***'.$visibleSuffix.'@'.$domain;
    }

    private function escapeCsvFormula(string $value): string
    {
        return preg_match('/\A[\t\r ]*[=+@-]/u', $value) === 1 ? "'".$value : $value;
    }
}
