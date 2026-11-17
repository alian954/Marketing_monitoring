<?php

namespace App\Jobs;

use App\Models\NotificationOutbox;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
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

            $outbox->forceFill([
                'status' => 'sending',
                'attempts' => $outbox->attempts + 1,
                'attempt_started_at' => now(),
                'last_error' => null,
            ])->save();

            return ['recipient' => $outbox->recipient, 'subject' => $outbox->subject, 'body' => $outbox->body];
        });

        if ($message === null) {
            return;
        }

        try {
            Mail::raw($message['body'], function ($mail) use ($message): void {
                $mail->to($message['recipient'])->subject($message['subject']);
            });

            NotificationOutbox::query()->whereKey($this->outboxId)->where('status', 'sending')->update([
                'status' => 'sent',
                'sent_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (Throwable $exception) {
            report($exception);
            NotificationOutbox::query()->whereKey($this->outboxId)->where('status', 'sending')->update([
                'status' => 'unknown',
                'last_error' => '寄送結果不明（'.class_basename($exception).'）。重試前請先確認收件狀態。',
                'updated_at' => now(),
            ]);
        }
    }
}
