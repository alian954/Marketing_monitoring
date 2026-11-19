<?php

namespace App\Services;

use App\Jobs\SendNotificationOutbox;
use App\Models\CheckRun;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\NotificationOutbox;

class NotificationDispatcher
{
    public function __construct(
        private readonly UrlMasker $urlMasker,
        private readonly StatusPageSubscriptionDispatcher $statusPageSubscriptions,
    ) {}

    /** @param list<string>|null $emailRecipientsOverride */
    public function queue(
        Incident $incident,
        Monitor $monitor,
        ?CheckRun $run,
        string $eventType,
        ?string $idempotencySuffix = null,
        ?array $emailRecipientsOverride = null,
        bool $includeWebhookChannels = true,
    ): bool {
        $monitorRecipients = NotificationEmailRecipientParser::parse($monitor->notification_email);
        $emailRecipients = $emailRecipientsOverride ?? ($monitorRecipients === []
            ? NotificationEmailRecipientParser::parse((string) config('monitoring.notify_email'))
            : $monitorRecipients);
        $destinations = [];

        foreach ($emailRecipients as $emailRecipient) {
            $destinations[] = [NotificationOutbox::CHANNEL_EMAIL, $emailRecipient];
        }

        $monitorSlackWebhookUrl = trim((string) $monitor->notification_slack_webhook);
        $slackWebhookUrl = $monitorSlackWebhookUrl === ''
            ? trim((string) config('monitoring.notify_slack_webhook'))
            : $monitorSlackWebhookUrl;

        if ($includeWebhookChannels && $slackWebhookUrl !== '') {
            $destinations[] = [NotificationOutbox::CHANNEL_SLACK, $slackWebhookUrl];
        }

        $monitorDiscordWebhookUrl = trim((string) $monitor->notification_discord_webhook);
        $discordWebhookUrl = $monitorDiscordWebhookUrl === ''
            ? trim((string) config('monitoring.notify_discord_webhook'))
            : $monitorDiscordWebhookUrl;

        if ($includeWebhookChannels && $discordWebhookUrl !== '') {
            $destinations[] = [NotificationOutbox::CHANNEL_DISCORD, $discordWebhookUrl];
        }

        $monitorTeamsWebhookUrl = trim((string) $monitor->notification_teams_webhook);
        $teamsWebhookUrl = $monitorTeamsWebhookUrl === ''
            ? trim((string) config('monitoring.notify_teams_webhook'))
            : $monitorTeamsWebhookUrl;

        if ($includeWebhookChannels && $teamsWebhookUrl !== '') {
            $destinations[] = [NotificationOutbox::CHANNEL_TEAMS, $teamsWebhookUrl];
        }

        $statusSubscribersQueued = $incident->rule_key === 'monitor_stale'
            ? false
            : $this->statusPageSubscriptions->queue($incident, $monitor, $eventType);

        if ($destinations === []) {
            return $statusSubscribersQueued;
        }

        $url = $this->urlMasker->mask($monitor->url);
        $reason = $this->reasonLabel($incident->rule_key, $monitor);
        $code = $run === null
            ? null
            : ($incident->rule_key === 'adsbot_difference' ? $run->adsbot_status : $run->normal_status);
        $statusLine = $incident->rule_key === 'monitor_stale'
            ? '檢查結果已超過預期更新時間，尚未取得新結果。'
            : ($code === null
                ? '檢查錯誤：'.($run?->error_type ?? $run?->adsbot_error_type ?? 'unknown')
                : 'HTTP 狀態碼：'.$code);
        $timeLine = $incident->rule_key === 'response_time' && $run?->normal_elapsed_ms !== null
            ? 'HTTP 回應耗時：'.$run?->normal_elapsed_ms.' 毫秒'
            : ($incident->rule_key === 'monitor_stale'
                ? '前次完成檢查時間（UTC）：'.($monitor->last_checked_at?->utc()->toIso8601String() ?? '尚無完成記錄')
                : ($run?->elapsed_ms === null ? '耗時：無資料' : '耗時：'.$run->elapsed_ms.' 毫秒'));
        $snapshot = $run === null ? [] : json_decode($run->config_snapshot, true, flags: JSON_THROW_ON_ERROR);
        $thresholdLine = $incident->rule_key === 'response_time'
            ? '設定門檻：'.($snapshot['response_time_threshold_ms'] ?? $monitor->response_time_threshold_ms).' 毫秒'
            : '';
        $acceptedStatusCodes = array_map('intval', $monitor->accepted_status_codes ?? []);
        $successfulStatusCodes = [...range(200, 399), ...$acceptedStatusCodes];
        $previousSuccessQuery = CheckRun::query()
            ->where('monitor_id', $monitor->id)
            ->where('config_version', $run?->config_version ?? $monitor->config_version)
            ->where('status', 'completed')
            ->whereNull('error_type')
            ->whereIn('normal_status', $successfulStatusCodes);

        if ($run !== null) {
            $previousSuccessQuery->where('id', '<', $run->id);
        }

        $previousSuccess = $previousSuccessQuery->latest('finished_at')->value('finished_at');
        $stateText = match ($eventType) {
            'opened' => $incident->rule_key === 'monitor_stale' ? '監測資料逾時' : '異常已確認',
            'reminder' => '異常仍未恢復',
            NotificationOutbox::EVENT_TYPE_ESCALATION => '異常仍未確認，已升級告警',
            NotificationOutbox::EVENT_TYPE_ESCALATION_STAGE_2 => '異常仍未確認，第二階段已升級告警',
            default => $incident->rule_key === 'monitor_stale' ? '監測資料已恢復' : '連續兩次檢查正常，事件已恢復',
        };
        $subject = '['.config('app.name').'] '.$monitor->name.' '.$stateText;
        $bodyLines = [
            $monitor->name.' — '.$stateText,
            '網址：'.$url,
            '原因：'.$reason,
            '偵測時間（UTC）：'.now()->utc()->toIso8601String(),
            $statusLine,
            $timeLine,
            $thresholdLine,
            '前次成功檢查時間（UTC）：'.($previousSuccess ?: '未記錄'),
            $incident->rule_key === 'adsbot_difference' ? 'AdsBot 結果僅為 User-Agent 字串模擬，不代表 Google 官方擷取。' : '',
        ];

        if ($monitor->socialPlatform() !== null) {
            $bodyLines[] = '此監測只讀取免登入的 HTTP 回應；平台登入要求或自動請求限制可能造成無法確認，請手動開啟網址核對貼文。這不代表貼文未發布或已刪除。';
        }

        $body = implode("\n", $bodyLines);
        $queued = false;

        foreach ($destinations as [$channel, $destination]) {
            $channelSuffix = match ($channel) {
                NotificationOutbox::CHANNEL_EMAIL => count($emailRecipients) === 1
                    ? ''
                    : ':email:'.hash('sha256', mb_strtolower($destination)),
                default => ':'.$channel,
            };
            $suffix = $idempotencySuffix === null ? '' : ':'.$idempotencySuffix;
            $eventKey = hash('sha256', $incident->id.':'.$eventType.$channelSuffix.$suffix);
            $outbox = NotificationOutbox::query()->firstOrCreate(['event_key' => $eventKey], [
                'incident_id' => $incident->id,
                'channel' => $channel,
                'recipient' => $destination,
                'event_type' => $eventType,
                'status' => 'pending',
                'subject' => $subject,
                'body' => $body,
            ]);

            if ($outbox->wasRecentlyCreated) {
                $queued = true;
                SendNotificationOutbox::dispatch($outbox->id)->afterCommit();
            }
        }

        return $queued || $statusSubscribersQueued;
    }

    private function reasonLabel(string $ruleKey, Monitor $monitor): string
    {
        return match ($ruleKey) {
            'http' => 'HTTP 連線異常',
            'content' => $monitor->socialPlatform() === null ? '缺少預期文字' : '原始回應未包含預期貼文文字',
            'forbidden_text' => '回應包含不應出現的文字',
            'response_header' => 'HTTP 回應標頭不符合設定',
            'response_time' => 'HTTP 回應時間超過設定門檻',
            'monitor_stale' => '監測檢查結果已超過預期更新時間',
            'tls_expired' => 'TLS 憑證已過期',
            'tls_handshake' => 'TLS 交握失敗',
            'tls_14d' => 'TLS 憑證將於 14 天內到期',
            'tls_7d' => 'TLS 憑證將於 7 天內到期',
            'tls_30d' => 'TLS 憑證將於 30 天內到期',
            'domain_expired' => '網域已到期',
            'domain_7d' => '網域將於 7 天內到期',
            'domain_14d' => '網域將於 14 天內到期',
            'domain_30d' => '網域將於 30 天內到期',
            'probe_restricted' => '檢查受到速率限制或封鎖',
            'probe_incomplete' => '回應內容超過讀取上限，文字比對未完成',
            'adsbot_difference' => 'AdsBot User-Agent 模擬結果不同',
            default => $ruleKey,
        };
    }
}
