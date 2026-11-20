<?php

namespace App\Models;

use App\Services\Probe\ResponseHeaderCondition;
use Carbon\CarbonImmutable;
use Database\Factories\MonitorFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['name', 'url', 'url_hash', 'label', 'notification_email', 'notification_escalation_email', 'notification_escalation_delay_minutes', 'notification_escalation_stage_2_email', 'notification_escalation_stage_2_delay_minutes', 'notification_slack_webhook', 'notification_discord_webhook', 'notification_teams_webhook', 'request_headers', 'expected_response_header', 'enabled', 'allowed_redirect_hosts', 'accepted_status_codes', 'expected_text', 'expected_text_mode', 'alert_on_visible_text_change', 'alert_on_response_metadata_change', 'response_time_threshold_ms', 'min_response_body_bytes', 'max_response_body_bytes', 'timeout_seconds', 'confirmation_delay_seconds', 'public_status', 'interval_seconds', 'next_check_at', 'last_checked_at'])]
#[Hidden(['notification_escalation_email', 'notification_escalation_stage_2_email', 'notification_slack_webhook', 'notification_discord_webhook', 'notification_teams_webhook', 'request_headers', 'expected_response_header'])]
class Monitor extends Model
{
    public const MAX_INTERVAL_SECONDS = 3600;

    /** @var list<int> */
    public const SUPPORTED_INTERVAL_SECONDS = [60, 300, 900, 1800, self::MAX_INTERVAL_SECONDS];

    /** @var array<string, string> */
    private const SOCIAL_DOMAINS = [
        'facebook.com' => 'Facebook',
        'fb.watch' => 'Facebook',
        'instagram.com' => 'Instagram',
        'instagr.am' => 'Instagram',
        'threads.com' => 'Threads',
        'threads.net' => 'Threads',
    ];

    /** @use HasFactory<MonitorFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(static function (Monitor $monitor): void {
            $monitor->forceFill([
                'social_platform' => self::socialPlatformKeyForUrl((string) $monitor->url),
            ]);
        });
    }

    protected function casts(): array
    {
        return [
            'url' => 'encrypted',
            'notification_email' => 'encrypted',
            'notification_escalation_email' => 'encrypted',
            'notification_escalation_stage_2_email' => 'encrypted',
            'notification_slack_webhook' => 'encrypted',
            'notification_discord_webhook' => 'encrypted',
            'notification_teams_webhook' => 'encrypted',
            'request_headers' => 'encrypted:array',
            'expected_response_header' => 'encrypted',
            'allowed_redirect_hosts' => 'array',
            'accepted_status_codes' => 'array',
            'enabled' => 'boolean',
            'alert_on_visible_text_change' => 'boolean',
            'alert_on_response_metadata_change' => 'boolean',
            'config_version' => 'integer',
            'response_time_threshold_ms' => 'integer',
            'min_response_body_bytes' => 'integer',
            'max_response_body_bytes' => 'integer',
            'timeout_seconds' => 'integer',
            'confirmation_delay_seconds' => 'integer',
            'notification_escalation_delay_minutes' => 'integer',
            'notification_escalation_stage_2_delay_minutes' => 'integer',
            'public_status' => 'boolean',
            'interval_seconds' => 'integer',
            'next_check_at' => 'immutable_datetime',
            'last_checked_at' => 'immutable_datetime',
        ];
    }

    public function checkRuns(): HasMany
    {
        return $this->hasMany(CheckRun::class);
    }

    public function incidents(): HasMany
    {
        return $this->hasMany(Incident::class);
    }

    public function dnsChanges(): HasMany
    {
        return $this->hasMany(DnsChangeEvent::class);
    }

    public function maintenanceWindows(): HasMany
    {
        return $this->hasMany(MaintenanceWindow::class);
    }

    public function tlsObservations(): HasMany
    {
        return $this->hasMany(TlsObservation::class);
    }

    public function domainRegistrationObservations(): HasMany
    {
        return $this->hasMany(DomainRegistrationObservation::class);
    }

    public function activeIncidents(): HasMany
    {
        return $this->hasMany(Incident::class)->whereIn('state', [Incident::STATE_SUSPECT, Incident::STATE_OPEN]);
    }

    public function latestCheckRun(): HasOne
    {
        return $this->hasOne(CheckRun::class)
            ->ofMany(['scheduled_at' => 'MAX'], static function (Builder $query): void {
                $query->whereNotIn('source', ['tls_daily', 'domain_daily']);
            });
    }

    public function latestTlsObservation(): HasOne
    {
        return $this->hasOne(TlsObservation::class)->ofMany(['observed_on' => 'MAX']);
    }

    public function latestDomainRegistrationObservation(): HasOne
    {
        return $this->hasOne(DomainRegistrationObservation::class)->ofMany(['observed_on' => 'MAX']);
    }

    public function socialPlatform(): ?string
    {
        return self::socialPlatformForUrl($this->url);
    }

    public function expectedResponseHeaderName(): ?string
    {
        return ResponseHeaderCondition::headerName($this->expected_response_header);
    }

    public function expectedResponseHeaderChecksPresenceOnly(): bool
    {
        $parsed = ResponseHeaderCondition::parse($this->expected_response_header);

        return $parsed !== null && $parsed['value'] === '';
    }

    public static function socialPlatformKeyForUrl(string $url): ?string
    {
        return match (self::socialPlatformForUrl($url)) {
            'Facebook' => 'facebook',
            'Instagram' => 'instagram',
            'Threads' => 'threads',
            default => null,
        };
    }

    public static function socialPlatformForUrl(string $url): ?string
    {
        $url = trim($url);
        $host = mb_strtolower((string) parse_url($url, PHP_URL_HOST));
        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');
        $query = [];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        foreach (self::SOCIAL_DOMAINS as $domain => $platform) {
            if ($host !== $domain && ! str_ends_with($host, '.'.$domain)) {
                continue;
            }

            if ($platform === 'Facebook' && self::isFacebookPostUrl($domain, $path, $query)) {
                return $platform;
            }

            if ($platform === 'Instagram' && self::isInstagramPostUrl($path)) {
                return $platform;
            }

            if ($platform === 'Threads' && preg_match('/\A@[^\/]+\/post\/[^\/]+\z|\At\/[^\/]+\z/i', $path) === 1) {
                return $platform;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $query */
    private static function isFacebookPostUrl(string $domain, string $path, array $query): bool
    {
        if ($domain === 'fb.watch') {
            return $path !== '';
        }

        if (ctype_digit($path)) {
            return true;
        }

        if (preg_match('/(?:\A|\/)(?:post|posts|permalink|videos|reel)\/[^\/]+\z|\Ashare\/[prv]\/[^\/]+\z/i', $path) === 1) {
            return true;
        }

        if (preg_match('/\A(?:story|permalink)\.php\z/i', $path) === 1) {
            return ! empty($query['story_fbid']) || ! empty($query['fbid']);
        }

        if (preg_match('/\Awatch\z/i', $path) === 1) {
            return ! empty($query['v']);
        }

        if (preg_match('/\Aphoto\z/i', $path) === 1) {
            return ! empty($query['fbid']);
        }

        return preg_match('/\A(?:[^\/]+\/)?photos\/[^\/]+\/[^\/]+\z/i', $path) === 1;
    }

    private static function isInstagramPostUrl(string $path): bool
    {
        if (preg_match('/\A(?:p|reel|reels|tv)\/[^\/]+\z|\Astories\/[^\/]+\/[^\/]+\z/i', $path) === 1) {
            return true;
        }

        if (preg_match('/\Ashare\/(?:p|reel)\/[^\/]+\z/i', $path) === 1) {
            return true;
        }

        return preg_match('/\Ashare\/(?!p\z|reel\z)[^\/]+\z/i', $path) === 1;
    }

    public static function fingerprintUrl(string $url): string
    {
        $parts = parse_url(trim($url));
        $scheme = mb_strtolower((string) ($parts['scheme'] ?? ''));
        $host = mb_strtolower(trim((string) ($parts['host'] ?? ''), '[]'));
        $port = isset($parts['port']) ? ':'.(int) $parts['port'] : '';
        $path = (string) ($parts['path'] ?? '/');
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';

        return hash('sha256', $scheme.'://'.$host.$port.($path === '' ? '/' : $path).$query);
    }

    public function staleAt(): CarbonImmutable
    {
        return ($this->last_checked_at ?? $this->created_at->toImmutable())
            ->addSeconds($this->interval_seconds + (int) config('monitoring.stale_grace_seconds'));
    }

    public function acceptsHttpStatus(?int $statusCode): bool
    {
        if ($statusCode === null) {
            return false;
        }

        return ($statusCode >= 200 && $statusCode < 400)
            || in_array($statusCode, array_map('intval', $this->accepted_status_codes ?? []), true);
    }

    public function healthState(): string
    {
        if (! $this->enabled) {
            return 'paused';
        }

        if ($this->last_checked_at === null) {
            return $this->created_at !== null && $this->staleAt()->isPast() ? 'stale' : 'pending';
        }

        if ($this->staleAt()->isPast()) {
            return 'stale';
        }

        $currentIncidents = $this->activeIncidents->where('config_version', $this->config_version);

        if ($currentIncidents->contains(fn (Incident $incident): bool => $incident->state === Incident::STATE_OPEN && $incident->severity === 'critical')) {
            return 'issue';
        }

        if ($currentIncidents->contains(fn (Incident $incident): bool => $incident->severity === 'critical')) {
            return 'suspect';
        }

        if ($currentIncidents->contains(fn (Incident $incident): bool => $incident->state === Incident::STATE_OPEN && $incident->severity === 'warning')) {
            return 'warning';
        }

        if ($currentIncidents->isNotEmpty()) {
            return 'suspect';
        }

        $run = $this->latestCheckRun;

        if ($run?->status === 'failed') {
            return 'warning';
        }

        if ($run === null || $run->status !== 'completed') {
            return 'pending';
        }

        if ($run->normal_status === 429 && ! $this->acceptsHttpStatus($run->normal_status)) {
            return 'warning';
        }

        if ($run->hasIndeterminateContentMatch()) {
            return 'warning';
        }

        if ($run->hasIndeterminateResponseSizeCheck() || $run->failsResponseBodySizeCheck()) {
            return 'warning';
        }

        if ($run->error_type !== null || ! $this->acceptsHttpStatus($run->normal_status)) {
            return 'issue';
        }

        return $run->adsbot_error_type !== null
            || ($run->adsbot_status !== null && ! $this->acceptsHttpStatus($run->adsbot_status))
            ? 'warning'
            : 'healthy';
    }
}
