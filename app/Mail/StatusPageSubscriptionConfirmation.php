<?php

namespace App\Mail;

use DateTimeImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class StatusPageSubscriptionConfirmation extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $statusPageTitle,
        public readonly string $confirmationUrl,
        public readonly DateTimeImmutable $expiresAt,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '['.config('app.name').'] 確認狀態頁更新訂閱',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.status-page-subscription-confirmation',
        );
    }
}
