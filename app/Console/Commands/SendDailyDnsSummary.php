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
        $destinations = [];
        $emailRecipient = trim((string) config('monitoring.notify_email'));
        $slackWebhookUrl = trim((string) config('monitoring.notify_slack_webhook'));
        $discordWebhookUrl = trim((string) config('monitoring.notify_discord_webhook'));
        $teamsWebhookUrl = trim((string) config('monitoring.notify_teams_webhook'));

        if ($emailRecipient !== '') {
            $destinations[] = [NotificationOutbox::CHANNEL_EMAIL, $emailRecipient];
        }

        if ($slackWebhookUrl !== '') {
            $destinations[] = [NotificationOutbox::CHANNEL_SLACK, $slackWebhookUrl];
        }

        if ($discordWebhookUrl !== '') {
            $destinations[] = [NotificationOutbox::CHANNEL_DISCORD, $discordWebhookUrl];
        }

        if ($teamsWebhookUrl !== '') {
            $destinations[] = [NotificationOutbox::CHANNEL_TEAMS, $teamsWebhookUrl];
        }

        if ($destinations === []) {
            $this->info('未設定任何通知管道，未建立 DNS 摘要。');

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

        $queuedCount = 0;

        foreach ($events->groupBy(static fn (DnsChangeEvent $event): string => (string) ($event->monitor?->user_id ?? 'unowned')) as $ownerKey => $accountEvents) {
            $userId = $ownerKey === 'unowned' ? null : (int) $ownerKey;
            $subject = '['.config('app.name').'] DNS 變更摘要 '.$reportDate;
            $body = $this->summaryBody($accountEvents, $urlMasker, $reportDate);

            foreach ($destinations as [$channel, $recipient]) {
                $eventKeyParts = $channel === NotificationOutbox::CHANNEL_EMAIL
                    ? ['dns_daily_summary', $userId ?? 'unowned', $recipient, $reportDate]
                    : ['dns_daily_summary', $userId ?? 'unowned', $channel, hash('sha256', $recipient), $reportDate];
                $eventKey = hash('sha256', implode(':', $eventKeyParts));
                $outbox = NotificationOutbox::query()->firstOrCreate(['event_key' => $eventKey], [
                    'user_id' => $userId,
                    'incident_id' => null,
                    'channel' => $channel,
                    'recipient' => $recipient,
                    'event_type' => 'dns_daily_summary',
                    'status' => 'pending',
                    'subject' => $subject,
                    'body' => $body,
                ]);

                if ($outbox->wasRecentlyCreated) {
                    SendNotificationOutbox::dispatch($outbox->id);
                    $queuedCount++;
                }
            }
        }

        if ($queuedCount > 0) {
            $this->info('已將 '.$events->count().' 筆 DNS 變更摘要加入 '.$queuedCount.' 個通知管道。');

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
            '本摘要只比較探測期間驗證為公開位址的 A/AAAA 主機 IP；「無公開 IP 回答」表示檢查時未取得公開 IP 位址。',
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
            $lines[] = '變更前：'.$event->previousIpsLabel();
            $lines[] = '變更後：'.$event->currentIpsLabel();
            $lines[] = '';
        }

        return implode("\n", $lines);
    }
}
