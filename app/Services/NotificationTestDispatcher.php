<?php

namespace App\Services;

use App\Jobs\SendNotificationOutbox;
use App\Models\Monitor;
use App\Models\NotificationOutbox;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class NotificationTestDispatcher
{
    public function queue(?Monitor $monitor = null): int
    {
        $destinations = [];
        $monitorRecipients = NotificationEmailRecipientParser::parse($monitor?->notification_email);
        $emailRecipients = $monitorRecipients === []
            ? NotificationEmailRecipientParser::parse((string) config('monitoring.notify_email'))
            : $monitorRecipients;

        foreach ($emailRecipients as $recipient) {
            $destinations[] = [NotificationOutbox::CHANNEL_EMAIL, $recipient];
        }

        $monitorSlackWebhookUrl = trim((string) $monitor?->notification_slack_webhook);
        $slackWebhookUrl = $monitorSlackWebhookUrl === ''
            ? trim((string) config('monitoring.notify_slack_webhook'))
            : $monitorSlackWebhookUrl;

        if ($slackWebhookUrl !== '') {
            $destinations[] = [NotificationOutbox::CHANNEL_SLACK, $slackWebhookUrl];
        }

        $monitorDiscordWebhookUrl = trim((string) $monitor?->notification_discord_webhook);
        $discordWebhookUrl = $monitorDiscordWebhookUrl === ''
            ? trim((string) config('monitoring.notify_discord_webhook'))
            : $monitorDiscordWebhookUrl;

        if ($discordWebhookUrl !== '') {
            $destinations[] = [NotificationOutbox::CHANNEL_DISCORD, $discordWebhookUrl];
        }

        if ($destinations === []) {
            return 0;
        }

        $batchId = (string) Str::uuid();
        $monitorName = $monitor === null
            ? null
            : trim(preg_replace('/[\r\n]+/u', ' ', $monitor->name) ?? $monitor->name);

        return DB::transaction(function () use ($batchId, $destinations, $monitor, $monitorName): int {
            $queuedCount = 0;

            foreach ($destinations as [$channel, $recipient]) {
                $outbox = NotificationOutbox::query()->create([
                    'incident_id' => null,
                    'monitor_id' => $monitor?->id,
                    'event_key' => hash('sha256', implode(':', [
                        NotificationOutbox::EVENT_TYPE_TEST,
                        $batchId,
                        $channel,
                        hash('sha256', mb_strtolower($recipient)),
                    ])),
                    'channel' => $channel,
                    'recipient' => $recipient,
                    'event_type' => NotificationOutbox::EVENT_TYPE_TEST,
                    'status' => 'pending',
                    'subject' => '['.config('app.name').'] '.($monitorName === null ? '' : $monitorName.' ').'測試通知',
                    'body' => "這是一則測試通知。\n".($monitorName === null ? '' : '監測項目：'.$monitorName."\n").'若收到此訊息，表示目前設定的通知管道可以接收訊息。',
                ]);

                SendNotificationOutbox::dispatch($outbox->id)->afterCommit();
                $queuedCount++;
            }

            return $queuedCount;
        });
    }
}
