<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\MonitorFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['name', 'url', 'url_hash', 'label', 'enabled', 'allowed_redirect_hosts', 'accepted_status_codes', 'expected_text', 'expected_text_mode', 'response_time_threshold_ms', 'public_status', 'interval_seconds', 'next_check_at', 'last_checked_at'])]
class Monitor extends Model
{
    /** @use HasFactory<MonitorFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'url' => 'encrypted',
            'allowed_redirect_hosts' => 'array',
            'accepted_status_codes' => 'array',
            'enabled' => 'boolean',
            'config_version' => 'integer',
            'response_time_threshold_ms' => 'integer',
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

    public function maintenanceWindows(): HasMany
    {
        return $this->hasMany(MaintenanceWindow::class);
    }

    public function activeIncidents(): HasMany
    {
        return $this->hasMany(Incident::class)->whereIn('state', [Incident::STATE_SUSPECT, Incident::STATE_OPEN]);
    }

    public function latestCheckRun(): HasOne
    {
        return $this->hasOne(CheckRun::class)->latestOfMany('scheduled_at');
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

        if ($run === null || $run->status !== 'completed') {
            return 'pending';
        }

        if ($run->normal_status === 429 && ! $this->acceptsHttpStatus($run->normal_status)) {
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
