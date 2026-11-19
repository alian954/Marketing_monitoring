<?php

namespace App\Services;

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
}
