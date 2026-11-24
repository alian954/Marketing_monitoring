<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

class ReadOnlyApiRequest
{
    private const MAX_RETRY_AFTER_SECONDS = 2;

    public static function forBearerToken(
        string $accessToken,
        int $timeoutSeconds,
        bool $retryRateLimitedResponses = true,
    ): PendingRequest {
        return Http::withToken($accessToken)
            ->acceptJson()
            ->connectTimeout(min(3, $timeoutSeconds))
            ->timeout($timeoutSeconds)
            ->retry(
                3,
                static fn (int $attempt, Throwable $exception): int => self::retryDelayMilliseconds($attempt, $exception),
                static fn (Throwable $exception): bool => self::shouldRetry($exception, $retryRateLimitedResponses),
                throw: false,
            );
    }

    private static function shouldRetry(Throwable $exception, bool $retryRateLimitedResponses): bool
    {
        if ($exception instanceof ConnectionException) {
            return true;
        }

        if (! $exception instanceof RequestException) {
            return false;
        }

        $status = $exception->response->status();

        if (! in_array($status, [429, 500, 502, 503, 504], true)
            || ($status === 429 && ! $retryRateLimitedResponses)) {
            return false;
        }

        $retryAfterSeconds = self::retryAfterSeconds($exception->response);

        return $retryAfterSeconds === null || $retryAfterSeconds <= self::MAX_RETRY_AFTER_SECONDS;
    }

    private static function retryDelayMilliseconds(int $attempt, Throwable $exception): int
    {
        if ($exception instanceof RequestException) {
            $retryAfterSeconds = self::retryAfterSeconds($exception->response);

            if ($retryAfterSeconds !== null) {
                return $retryAfterSeconds * 1000;
            }
        }

        return $attempt === 1 ? 250 : 750;
    }

    private static function retryAfterSeconds(Response $response): ?int
    {
        $retryAfter = $response->header('Retry-After');

        if (! is_string($retryAfter) || trim($retryAfter) === '') {
            return null;
        }

        $retryAfter = trim($retryAfter);

        if (ctype_digit($retryAfter)) {
            return (int) $retryAfter;
        }

        $retryAt = strtotime($retryAfter);

        return $retryAt === false ? null : max(0, $retryAt - time());
    }
}
