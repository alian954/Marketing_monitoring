<?php

namespace App\Services;

class TeamsWebhookUrlPolicy
{
    public static function isAllowed(string $url): bool
    {
        $parts = parse_url($url);

        if (filter_var($url, FILTER_VALIDATE_URL) === false || ! is_array($parts)) {
            return false;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        $allowedHost = str_ends_with($host, '.logic.azure.com')
            || str_ends_with($host, '.api.powerplatform.com');
        $allowedPort = ! array_key_exists('port', $parts) || (int) $parts['port'] === 443;

        return strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && $allowedHost
            && $allowedPort
            && ! array_key_exists('user', $parts)
            && ! array_key_exists('pass', $parts)
            && ! array_key_exists('fragment', $parts)
            && trim((string) ($parts['path'] ?? ''), '/') !== '';
    }
}
