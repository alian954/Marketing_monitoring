<?php

namespace App\Services;

use Illuminate\Http\Request;

/**
 * Where the admin console lives: always under /admin, optionally pinned to a dedicated host.
 */
class PlatformConsole
{
    public const PATH_PREFIX = 'admin';

    public static function isEnabled(): bool
    {
        return (bool) config('platform.console_enabled', true);
    }

    public static function domain(): ?string
    {
        $domain = config('platform.console_domain');

        return is_string($domain) && $domain !== '' ? $domain : null;
    }

    public static function isConsoleHost(Request $request): bool
    {
        $domain = self::domain();

        return $domain !== null && mb_strtolower($request->getHost()) === $domain;
    }

    public static function isConsolePath(string $path): bool
    {
        $path = trim($path, '/');

        return $path === self::PATH_PREFIX || str_starts_with($path, self::PATH_PREFIX.'/');
    }

    public static function isConsoleRequest(Request $request): bool
    {
        if (! self::isEnabled() || ! self::isConsolePath($request->path())) {
            return false;
        }

        return self::domain() === null || self::isConsoleHost($request);
    }

    /**
     * Whether a URL (such as an intended redirect) points into the console.
     */
    public static function isConsoleUrl(string $url): bool
    {
        if (! self::isConsolePath((string) parse_url($url, PHP_URL_PATH))) {
            return false;
        }

        $domain = self::domain();
        $host = parse_url($url, PHP_URL_HOST);

        return $domain === null || ! is_string($host) || mb_strtolower($host) === $domain;
    }
}
