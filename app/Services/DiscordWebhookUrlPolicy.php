<?php

namespace App\Services;

class DiscordWebhookUrlPolicy
{
    public static function isAllowed(string $url): bool
    {
        $parts = parse_url($url);

        if (filter_var($url, FILTER_VALIDATE_URL) === false || ! is_array($parts)) {
            return false;
        }

        return strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && strtolower((string) ($parts['host'] ?? '')) === 'discord.com'
            && ! array_key_exists('user', $parts)
            && ! array_key_exists('pass', $parts)
            && ! array_key_exists('port', $parts)
            && ! array_key_exists('query', $parts)
            && ! array_key_exists('fragment', $parts)
            && preg_match('/\A\/api\/webhooks\/[0-9]{1,20}\/[A-Za-z0-9._-]+\z/', (string) ($parts['path'] ?? '')) === 1;
    }
}
