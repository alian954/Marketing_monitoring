<?php

namespace App\Mail;

use DateTimeImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ClientReportDelivered extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $brandName,
        public readonly string $clientName,
        public readonly string $reportTitle,
        public readonly string $periodLabel,
        public readonly string $reportUrl,
        public readonly DateTimeImmutable $expiresAt,
        public readonly string $csvContent,
        public readonly string $csvFilename,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '['.$this->brandName.'] '.$this->clientName.'｜'.$this->reportTitle.' '.$this->periodLabel,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.client-report-delivered',
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return [
            Attachment::fromData(fn (): string => $this->csvContent, $this->csvFilename)
                ->withMime('text/csv'),
        ];
    }
}
