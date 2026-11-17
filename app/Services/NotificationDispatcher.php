<?php

namespace App\Services;

use App\Jobs\SendNotificationOutbox;
use App\Models\CheckRun;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\NotificationOutbox;

class NotificationDispatcher
{
    public function __construct(private readonly UrlMasker $urlMasker) {}

    public function queue(Incident $incident, Monitor $monitor, CheckRun $run, string $eventType): void
    {
        $recipient = (string) config('monitoring.notify_email');

        if ($recipient === '') {
            return;
        }

        $eventKey = hash('sha256', $incident->id.':'.$eventType);
        $url = $this->urlMasker->mask($monitor->url);
        $reason = $this->reasonLabel($incident->rule_key);
        $code = $incident->rule_key === 'adsbot_difference' ? $run->adsbot_status : $run->normal_status;
        $statusLine = $code === null ? '檢查錯誤：'.($run->error_type ?? $run->adsbot_error_type ?? 'unknown') : 'HTTP 狀態碼：'.$code;
        $timeLine = $run->elapsed_ms === null ? '耗時：無資料' : '耗時：'.$run->elapsed_ms.' 毫秒';
        $previousSuccess = CheckRun::query()
            ->where('monitor_id', $monitor->id)
            ->where('config_version', $run->config_version)
            ->where('status', 'completed')
            ->where('normal_status', '>=', 200)
            ->where('normal_status', '<', 400)
            ->where('id', '<', $run->id)
            ->latest('finished_at')
            ->value('finished_at');
        $stateText = $eventType === 'opened' ? '異常已確認' : '連續兩次檢查正常，事件已恢復';
        $subject = '['.config('app.name').'] '.$monitor->name.' '.$stateText;
        $body = implode("\n", [
            $monitor->name.' — '.$stateText,
            '網址：'.$url,
            '原因：'.$reason,
            '偵測時間（UTC）：'.now()->utc()->toIso8601String(),
            $statusLine,
            $timeLine,
            '前次成功檢查時間（UTC）：'.($previousSuccess ?: '未記錄'),
            $incident->rule_key === 'adsbot_difference' ? 'AdsBot 結果僅為 User-Agent 字串模擬，不代表 Google 官方擷取。' : '',
        ]);

        $outbox = NotificationOutbox::query()->firstOrCreate(['event_key' => $eventKey], [
            'incident_id' => $incident->id,
            'recipient' => $recipient,
            'event_type' => $eventType,
            'status' => 'pending',
            'subject' => $subject,
            'body' => $body,
        ]);

        if ($outbox->wasRecentlyCreated) {
            SendNotificationOutbox::dispatch($outbox->id)->afterCommit();
        }
    }

    private function reasonLabel(string $ruleKey): string
    {
        return match ($ruleKey) {
            'http' => 'HTTP 連線異常',
            'content' => '缺少預期文字',
            'tls_expired' => 'TLS 憑證已過期',
            'tls_handshake' => 'TLS 交握失敗',
            'tls_14d' => 'TLS 憑證將於 14 天內到期',
            'tls_7d' => 'TLS 憑證將於 7 天內到期',
            'probe_restricted' => '檢查受到速率限制或封鎖',
            'adsbot_difference' => 'AdsBot User-Agent 模擬結果不同',
            default => $ruleKey,
        };
    }
}
