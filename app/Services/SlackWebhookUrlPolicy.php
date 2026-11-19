<?php

namespace App\Services;

class SlackWebhookUrlPolicy
{
    /**
     * Determine whether a URL is a valid Slack incoming webhook destination.
     */
    public static function isAllowed(string $url): bool
    {
        $parts = parse_url($url);

        if (filter_var($url, FILTER_VALIDATE_URL) === false || ! is_array($parts)) {
            return false;
        }

        return strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && in_array(strtolower((string) ($parts['host'] ?? '')), ['hooks.slack.com', 'hooks.slack-gov.com'], true)
            && ! array_key_exists('user', $parts)
            && ! array_key_exists('pass', $parts)
            && ! array_key_exists('port', $parts)
            && ! array_key_exists('query', $parts)
            && ! array_key_exists('fragment', $parts)
            && trim((string) ($parts['path'] ?? ''), '/') !== '';
    }
}
