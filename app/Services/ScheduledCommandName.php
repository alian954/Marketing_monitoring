<?php

namespace App\Services;

use Illuminate\Console\Scheduling\Event;

/**
 * Extracts the Artisan command name from a scheduled event so it can be matched to its owning product.
 */
class ScheduledCommandName
{
    public static function fromEvent(Event $event): ?string
    {
        if (! is_string($event->command)
            || preg_match('/artisan[\'"]?\s+[\'"]?([a-z0-9-]+(?::[a-z0-9-]+)+)/', $event->command, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }
}
