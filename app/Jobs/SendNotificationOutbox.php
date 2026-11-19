<?php

namespace App\Jobs;

use App\Models\Incident;
use App\Models\NotificationOutbox;
use App\Models\StatusPageSubscription;
use App\Services\SlackWebhookUrlPolicy;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
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

            if (in_array($outbox->event_type, [
                NotificationOutbox::EVENT_TYPE_ESCALATION,
                NotificationOutbox::EVENT_TYPE_ESCALATION_STAGE_2,
            ], true)) {
                $incident = Incident::query()->lockForUpdate()->find($outbox->incident_id);

                if (
                    $incident === null
                    || $incident->state !== Incident::STATE_OPEN
                    || $incident->acknowledged_at !== null
                ) {
                    $outbox->forceFill([
                        'status' => 'cancelled',
                        'last_error' => '事件已認領或結束，升級通知未寄出。',
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
                'last_error' => '寄送結果不明（'.class_basename($exception).'）。重試前請先確認收件狀態。',
                'updated_at' => now(),
            ]);
        }
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
}
