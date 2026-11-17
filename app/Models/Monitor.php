<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\MonitorFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['name', 'url', 'url_hash', 'label', 'enabled', 'allowed_redirect_hosts', 'expected_text', 'interval_seconds', 'next_check_at', 'last_checked_at'])]
class Monitor extends Model
{
    /** @use HasFactory<MonitorFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'url' => 'encrypted',
            'allowed_redirect_hosts' => 'array',
            'enabled' => 'boolean',
            'config_version' => 'integer',
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

        if ($currentIncidents->contains(fn (Incident $incident): bool => $incident->state === Incident::STATE_OPEN)) {
            return 'issue';
        }

        if ($currentIncidents->isNotEmpty()) {
            return 'suspect';
        }

        $run = $this->latestCheckRun;

        if ($run === null || $run->status !== 'completed') {
            return 'pending';
        }

        if ($run->normal_status === 429) {
            return 'warning';
        }

        if ($run->error_type !== null || $run->normal_status === null || $run->normal_status >= 400) {
            return 'issue';
        }

        return $run->adsbot_error_type !== null || ($run->adsbot_status !== null && $run->adsbot_status >= 400) ? 'warning' : 'healthy';
    }
}
