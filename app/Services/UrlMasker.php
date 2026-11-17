<?php

namespace App\Services;

class UrlMasker
{
    public function mask(string $url): string
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return '[invalid URL]';
        }

        $masked = $parts['scheme'].'://'.$parts['host'];

        if (isset($parts['port'])) {
            $masked .= ':'.$parts['port'];
        }

        $masked .= $parts['path'] ?? '/';

        if (isset($parts['query'])) {
            $segments = [];

            foreach (explode('&', $parts['query']) as $segment) {
                [$rawKey, $rawValue] = array_pad(explode('=', $segment, 2), 2, '');
                $key = rawurldecode($rawKey);
                $sensitive = preg_match('/token|secret|password|passwd|auth|e-?mail|session|code|api.?key|signature|credential|access/i', $key) === 1;
                $segments[] = $rawKey.'='.($sensitive ? 'REDACTED' : $rawValue);
            }

            $masked .= '?'.implode('&', $segments);
        }

        return $masked;
    }
}
