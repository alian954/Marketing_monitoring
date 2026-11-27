<?php

namespace App\Notifications;

use Carbon\CarbonImmutable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SubscriptionExpiringNotification extends Notification
{
    /**
     * @param  list<array{product_name: string, ends_at: CarbonImmutable}>  $expiringProducts
     */
    public function __construct(
        public readonly string $organizationName,
        public readonly array $expiringProducts,
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject('「'.$this->organizationName.'」的產品訂閱即將到期')
            ->line('以下產品訂閱即將到期：');

        foreach ($this->expiringProducts as $expiringProduct) {
            $message->line('・'.$expiringProduct['product_name'].'：服務至 '.self::lastServiceDay($expiringProduct['ends_at']).'（台灣時間）');
        }

        return $message
            ->line('請聯絡平台管理員辦理續約，以免服務中斷。')
            ->line('若已完成續約付款，可忽略此郵件。');
    }

    /**
     * Access ends at the start of the following day, so the last full service day is one second earlier.
     */
    public static function lastServiceDay(CarbonImmutable $endsAt): string
    {
        return $endsAt->setTimezone('Asia/Taipei')->subSecond()->format('Y/n/j');
    }
}
