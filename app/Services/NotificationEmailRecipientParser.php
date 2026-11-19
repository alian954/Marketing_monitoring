<?php

namespace App\Services;

use Illuminate\Support\Facades\Validator;

final class NotificationEmailRecipientParser
{
    /** @return list<string> */
    public static function parse(?string $input): array
    {
        $values = preg_split('/[\r\n,;]+/', (string) $input, flags: PREG_SPLIT_NO_EMPTY) ?: [];
        $recipients = [];
        $seen = [];

        foreach ($values as $value) {
            $recipient = trim($value);
            $key = mb_strtolower($recipient);

            if ($recipient === '' || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $recipients[] = $recipient;
        }

        return $recipients;
    }

    public static function normalize(?string $input): ?string
    {
        $recipients = self::parse($input);

        return $recipients === [] ? null : implode("\n", $recipients);
    }

    public static function validationError(?string $notificationEmail): ?string
    {
        if ($notificationEmail === null) {
            return null;
        }

        $recipients = explode("\n", $notificationEmail);
        $validator = Validator::make(
            ['recipients' => $recipients],
            [
                'recipients' => ['required', 'array', 'max:10'],
                'recipients.*' => ['required', 'string', 'email', 'max:254'],
            ],
        );

        if (! $validator->fails()) {
            return null;
        }

        return count($recipients) > 10
            ? '最多可設定 10 個告警收件信箱。'
            : '每個告警收件信箱都必須是有效電子郵件地址，且單一地址不得超過 254 個字元。';
    }
}
