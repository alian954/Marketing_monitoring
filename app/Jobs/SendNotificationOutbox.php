<?php

namespace App\Jobs;

use App\Models\Incident;
use App\Models\LineNotificationTarget;
use App\Models\NotificationOutbox;
use App\Models\StatusPageSubscription;
use App\Services\DiscordWebhookUrlPolicy;
use App\Services\LineMessagingApi;
use App\Services\LineMessagingApiException;
use App\Services\SlackWebhookUrlPolicy;
use App\Services\TeamsWebhookUrlPolicy;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use Ramsey\Uuid\Uuid;
use Throwable;

class SendNotificationOutbox implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 20;

    public int $uniqueFor = 120;

    public function __construct(public readonly int $outboxId) {}

    public function uniqueId(): string
    {
        return (string) $this->outboxId;
    }

    public function handle(): void
    {
        $message = DB::transaction(function (): ?array {
            $outbox = NotificationOutbox::query()->lockForUpdate()->find($this->outboxId);

            if ($outbox === null || $outbox->status !== 'pending') {
                return null;
            }

            if ($outbox->status_page_subscription_id !== null) {
                $subscription = StatusPageSubscription::query()
                    ->lockForUpdate()
                    ->find($outbox->status_page_subscription_id);

                if ($subscription === null || ! $subscription->isActive()) {
                    $outbox->forceFill([
                        'status' => 'cancelled',
                        'last_error' => '公開狀態頁訂閱已取消，通知未寄出。',
                    ])->save();

                    return null;
                }
            }

            if (in_array($outbox->event_type, NotificationOutbox::SNOOZABLE_EVENT_TYPES, true)) {
                $incident = Incident::query()->lockForUpdate()->find($outbox->incident_id);

                if ($incident?->snoozed_until?->isFuture()) {
                    return null;
                }

                if (
                    $incident === null
                    || $incident->state !== Incident::STATE_OPEN
                    || (in_array($outbox->event_type, [
                        NotificationOutbox::EVENT_TYPE_ESCALATION,
                        NotificationOutbox::EVENT_TYPE_ESCALATION_STAGE_2,
                    ], true) && $incident->acknowledged_at !== null)
                ) {
                    $outbox->forceFill([
                        'status' => 'cancelled',
                        'last_error' => '事件已結束或升級事件已認領，通知未寄出。',
                    ])->save();

                    return null;
                }
            }

            $outbox->forceFill([
                'status' => 'sending',
                'attempts' => $outbox->attempts + 1,
                'attempt_started_at' => now(),
                'last_error' => null,
            ])->save();

            return [
                'channel' => $outbox->channel,
                'recipient' => $outbox->recipient,
                'subject' => $outbox->subject,
                'body' => $outbox->body,
                'workspace_id' => is_numeric($outbox->workspace_id) ? (int) $outbox->workspace_id : null,
                'event_key' => (string) $outbox->event_key,
            ];
        });

        if ($message === null) {
            return;
        }

        try {
            if ($message['channel'] === NotificationOutbox::CHANNEL_EMAIL) {
                $this->sendEmail($message);
            } elseif ($message['channel'] === NotificationOutbox::CHANNEL_SLACK) {
                $this->sendSlack($message);
            } elseif ($message['channel'] === NotificationOutbox::CHANNEL_DISCORD) {
                $this->sendDiscord($message);
            } elseif ($message['channel'] === NotificationOutbox::CHANNEL_TEAMS) {
                $this->sendTeams($message);
            } elseif ($message['channel'] === NotificationOutbox::CHANNEL_LINE) {
                $this->sendLine($message);
            } else {
                throw new InvalidArgumentException('Unsupported notification channel.');
            }

            NotificationOutbox::query()->whereKey($this->outboxId)->where('status', 'sending')->update([
                'status' => 'sent',
                'sent_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (Throwable $exception) {
            if ($message['channel'] === NotificationOutbox::CHANNEL_EMAIL) {
                report($exception);
            }

            NotificationOutbox::query()->whereKey($this->outboxId)->where('status', 'sending')->update([
                'status' => 'unknown',
                'last_error' => $exception instanceof LineMessagingApiException
                    ? $exception->getMessage().'重試前請先確認 LINE 是否已收到。'
                    : '寄送結果不明（'.class_basename($exception).'）。重試前請先確認收件狀態。',
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Resolves the LINE target inside the outbox workspace so a target ID can never borrow another workspace's channel token.
     *
     * @param  array{channel: string, recipient: string, subject: string, body: string, workspace_id: int|null, event_key: string}  $message
     */
    private function sendLine(array $message): void
    {
        $target = $message['workspace_id'] === null
            ? null
            : LineNotificationTarget::query()
                ->withoutGlobalScopes()
                ->where('workspace_id', $message['workspace_id'])
                ->where('target_id', $message['recipient'])
                ->with(['channel' => static fn ($query) => $query->withoutGlobalScopes()])
                ->first();

        if ($target === null || $target->channel === null || (int) $target->channel->workspace_id !== $message['workspace_id']) {
            throw new LineMessagingApiException('找不到 LINE 推播對象或官方帳號連線已移除。');
        }

        if (! $target->isActive()) {
            throw new LineMessagingApiException('LINE 官方帳號已離開此群組或被對象封鎖。');
        }

        app(LineMessagingApi::class)->pushText(
            (string) $target->channel->channel_access_token,
            $target->target_id,
            $message['subject']."\n\n".$message['body'],
            Uuid::uuid5(Uuid::NAMESPACE_URL, 'line-notification:'.$message['event_key'])->toString(),
        );
    }

    /** @param array{channel: string, recipient: string, subject: string, body: string} $message */
    private function sendEmail(array $message): void
    {
        Mail::raw($message['body'], function ($mail) use ($message): void {
            $mail->to($message['recipient'])->subject($message['subject']);
        });
    }

    /** @param array{channel: string, recipient: string, subject: string, body: string} $message */
    private function sendSlack(array $message): void
    {
        $webhookUrl = $message['recipient'];

        if (! SlackWebhookUrlPolicy::isAllowed($webhookUrl)) {
            throw new InvalidArgumentException('Slack webhook URL must use an allowed Slack HTTPS host.');
        }

        Http::connectTimeout(3)
            ->timeout(10)
            ->withoutRedirecting()
            ->post($webhookUrl, ['text' => $message['subject']."\n\n".$message['body']])
            ->throw();
    }

    /** @param array{channel: string, recipient: string, subject: string, body: string} $message */
    private function sendDiscord(array $message): void
    {
        $webhookUrl = $message['recipient'];

        if (! DiscordWebhookUrlPolicy::isAllowed($webhookUrl)) {
            throw new InvalidArgumentException('Discord webhook URL must use an allowed Discord HTTPS host and path.');
        }

        $content = $message['subject']."\n\n".$message['body'];

        if (mb_strlen($content) > 2000) {
            $content = mb_substr($content, 0, 1999).'…';
        }

        Http::connectTimeout(3)
            ->timeout(10)
            ->withoutRedirecting()
            ->post($webhookUrl.'?wait=true', [
                'content' => $content,
                'allowed_mentions' => ['parse' => []],
            ])
            ->throw();
    }

    /** @param array{channel: string, recipient: string, subject: string, body: string} $message */
    private function sendTeams(array $message): void
    {
        $webhookUrl = $message['recipient'];

        if (! TeamsWebhookUrlPolicy::isAllowed($webhookUrl)) {
            throw new InvalidArgumentException('Teams webhook URL must use an allowed Microsoft HTTPS host.');
        }

        $content = $message['subject']."\n\n".$message['body'];

        if (strlen($content) > 25_000) {
            $content = mb_strcut($content, 0, 24_980, 'UTF-8')."\n\n[內容已截斷]";
        }

        Http::connectTimeout(3)
            ->timeout(10)
            ->withoutRedirecting()
            ->post($webhookUrl, ['text' => $content])
            ->throw();
    }
}
