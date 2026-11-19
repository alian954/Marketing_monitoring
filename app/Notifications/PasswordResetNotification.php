<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use InvalidArgumentException;

class PasswordResetNotification extends Notification
{
    public function __construct(public readonly string $token) {}

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
        if (! $notifiable instanceof User) {
            throw new InvalidArgumentException('Password reset notifications require a user account.');
        }

        $resetUrl = url(route('password.reset', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ], false));

        return (new MailMessage)
            ->subject('重設監測台密碼')
            ->greeting('您好，'.$notifiable->name)
            ->line('我們收到重設監測台帳戶密碼的要求。')
            ->action('設定新密碼', $resetUrl)
            ->line('此連結將於 '.config('auth.passwords.users.expire').' 分鐘後失效。')
            ->line('如果您沒有提出這項要求，請忽略這封郵件；目前密碼不會變更。');
    }
}
