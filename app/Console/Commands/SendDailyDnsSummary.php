<?php

namespace App\Console\Commands;

use App\Jobs\SendNotificationOutbox;
use App\Models\DnsChangeEvent;
use App\Models\NotificationOutbox;
use App\Services\UrlMasker;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

#[Signature('monitors:send-dns-summary')]
#[Description('將前一日 DNS 變更摘要加入通知佇列')]
class SendDailyDnsSummary extends Command
{
    public function handle(UrlMasker $urlMasker): int
    {
        $recipient = (string) config('monitoring.notify_email');

        if ($recipient === '') {
            $this->info('未設定通知收件人，未建立 DNS 摘要。');

            return self::SUCCESS;
        }

        $periodEnd = now()->toImmutable()->startOfDay();
        $periodStart = $periodEnd->subDay();
        $reportDate = $periodStart->toDateString();
        $events = DnsChangeEvent::query()
            ->with('monitor')
            ->where('detected_at', '>=', $periodStart)
            ->where('detected_at', '<', $periodEnd)
            ->orderBy('detected_at')
            ->orderBy('id')
            ->get();

        if ($events->isEmpty()) {
            $this->info('前一日 '.$reportDate.' 沒有 DNS 變更，未建立摘要。');

            return self::SUCCESS;
        }

        $eventKey = hash('sha256', implode(':', ['dns_daily_summary', $recipient, $reportDate]));
        $outbox = NotificationOutbox::query()->firstOrCreate(['event_key' => $eventKey], [
            'incident_id' => null,
            'recipient' => $recipient,
            'event_type' => 'dns_daily_summary',
            'status' => 'pending',
            'subject' => '['.config('app.name').'] DNS 變更摘要 '.$reportDate,
            'body' => $this->summaryBody($events, $urlMasker, $reportDate),
        ]);

        if ($outbox->wasRecentlyCreated) {
            SendNotificationOutbox::dispatch($outbox->id);
            $this->info('已將 '.$events->count().' 筆 DNS 變更加入摘要寄送佇列。');

            return self::SUCCESS;
        }

        $this->info('前一日 DNS 摘要已建立，未重複寄送。');

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int, DnsChangeEvent>  $events
     */
    private function summaryBody(Collection $events, UrlMasker $urlMasker, string $reportDate): string
    {
        $lines = [
            'DNS 變更每日摘要 — '.$reportDate,
            '摘要日期時區：'.config('app.timezone'),
            '共偵測到 '.$events->count().' 筆主機 IP 變更。',
            '本摘要只比較探測期間驗證為公開位址的主機 IP，不代表完整 DNS 記錄集。',
            '',
        ];

        foreach ($events as $event) {
            $monitor = $event->monitor;

            if ($monitor === null) {
                continue;
            }

            $lines[] = $monitor->name;
            $lines[] = '網址：'.$urlMasker->mask($monitor->url);
            $lines[] = '偵測時間（UTC）：'.$event->detected_at->utc()->toIso8601String();
            $lines[] = '變更前：'.implode(', ', $event->previous_ips ?? []);
            $lines[] = '變更後：'.implode(', ', $event->current_ips ?? []);
            $lines[] = '';
        }

        return implode("\n", $lines);
    }
}
