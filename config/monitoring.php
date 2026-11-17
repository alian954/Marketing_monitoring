<?php

return [
    'transport' => env('MONITOR_TRANSPORT', 'secure'),
    'interval_seconds' => 900,
    'confirmation_delay_seconds' => 60,
    'request_timeout_seconds' => 10,
    'connect_timeout_seconds' => 3,
    'max_redirects' => 5,
    'max_body_bytes' => 1_048_576,
    'stale_grace_seconds' => 900,
    'retention_days' => 30,
    'notify_email' => env('MONITOR_NOTIFY_EMAIL'),
    'default_allowed_redirect_hosts' => array_filter(array_map(
        static fn (string $host): string => mb_strtolower(trim($host)),
        explode(',', (string) env('MONITOR_ALLOWED_REDIRECT_HOSTS', '')),
    )),
];
