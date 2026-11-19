<?php

namespace App\Services\Probe;

class RetryAfterParser
{
    public const MAX_DELAY_SECONDS = 86_400;

    public function headerValue(string $line): ?string
    {
        if (preg_match('/\ARetry-After:[ \t]*(.*?)[ \t]*\r?\n?\z/i', $line, $matches) !== 1) {
            return null;
        }

        return trim($matches[1]);
    }

    public function seconds(?string $header, ?\DateTimeImmutable $asOf = null): ?int
    {
        $value = trim((string) $header);

        if ($value === '') {
            return null;
        }

        if (ctype_digit($value)) {
            return min((int) $value, self::MAX_DELAY_SECONDS);
        }

        if (preg_match('/\A[+-]?\d+\z/', $value) === 1) {
            return null;
        }

        try {
            $retryAt = new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
        } catch (\Throwable) {
            return null;
        }

        $asOf ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $seconds = $retryAt->getTimestamp() - $asOf->getTimestamp();

        if ($seconds <= 0) {
            return null;
        }

        return min($seconds, self::MAX_DELAY_SECONDS);
    }
}
