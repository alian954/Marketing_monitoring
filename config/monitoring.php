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
    'retry_after_fallback_seconds' => max(0, (int) env('MONITOR_RETRY_AFTER_FALLBACK_SECONDS', 300)),
    'retention_days' => max(1, (int) env('MONITOR_CHECK_RUN_RETENTION_DAYS', 30)),
    'schedule_jitter_max_seconds' => 60,
    'incident_reminder_interval_minutes' => (int) env('MONITOR_INCIDENT_REMINDER_INTERVAL_MINUTES', 360),
    'notify_email' => env('MONITOR_NOTIFY_EMAIL'),
    'notify_slack_webhook' => env('MONITOR_SLACK_WEBHOOK_URL'),
    'notify_discord_webhook' => env('MONITOR_DISCORD_WEBHOOK_URL'),
    'notify_teams_webhook' => env('MONITOR_NOTIFY_TEAMS_WEBHOOK_URL'),
    'default_allowed_redirect_hosts' => array_filter(array_map(
        static fn (string $host): string => mb_strtolower(trim($host)),
        explode(',', (string) env('MONITOR_ALLOWED_REDIRECT_HOSTS', '')),
    )),
];
