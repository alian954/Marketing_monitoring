<?php

namespace App\Models;

use App\Services\Probe\ExpectedTextMatcher;
use Database\Factories\CheckRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['monitor_id', 'run_key', 'config_version', 'config_snapshot', 'source', 'status', 'scheduled_at', 'started_at', 'finished_at', 'normal_status', 'adsbot_status', 'error_type', 'error_detail', 'adsbot_error_type', 'elapsed_ms', 'normal_elapsed_ms', 'final_url', 'redirects', 'resolved_public_ips', 'monitor_host_ips', 'expected_text_found', 'tls_expires_at'])]
class CheckRun extends Model
{
    /** @use HasFactory<CheckRunFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'config_snapshot' => 'encrypted',
            'config_version' => 'integer',
            'normal_status' => 'integer',
            'adsbot_status' => 'integer',
            'elapsed_ms' => 'integer',
            'normal_elapsed_ms' => 'integer',
            'scheduled_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'redirects' => 'array',
            'resolved_public_ips' => 'array',
            'monitor_host_ips' => 'array',
            'expected_text_found' => 'boolean',
            'tls_expires_at' => 'immutable_datetime',
        ];
    }

    public function passesMonitorChecks(): bool
    {
        if ($this->status !== 'completed' || $this->error_type !== null) {
            return false;
        }

        $snapshot = json_decode($this->config_snapshot, true, flags: JSON_THROW_ON_ERROR);
        $statusCode = $this->normal_status;
        $acceptedStatusCodes = array_map('intval', $snapshot['accepted_status_codes'] ?? []);
        $isAcceptedStatus = $statusCode !== null
            && (($statusCode >= 200 && $statusCode < 400) || in_array($statusCode, $acceptedStatusCodes, true));

        if (! $isAcceptedStatus) {
            return false;
        }

        $expectedText = $snapshot['expected_text'] ?? null;

        if (! is_string($expectedText) || $expectedText === '') {
            return true;
        }

        return ($snapshot['expected_text_mode'] ?? ExpectedTextMatcher::MODE_PRESENT) === ExpectedTextMatcher::MODE_ABSENT
            ? $this->expected_text_found === false
            : $this->expected_text_found === true;
    }

    public function expectedTextMode(): string
    {
        $snapshot = json_decode($this->config_snapshot, true, flags: JSON_THROW_ON_ERROR);

        $mode = $snapshot['expected_text_mode'] ?? ExpectedTextMatcher::MODE_PRESENT;

        return in_array($mode, [ExpectedTextMatcher::MODE_ABSENT, ExpectedTextMatcher::MODE_PRESENT_ANY], true)
            ? $mode
            : ExpectedTextMatcher::MODE_PRESENT;
    }

    public function hasIndeterminateContentMatch(): bool
    {
        if ($this->error_type !== 'body_too_large' || $this->expected_text_found !== null || $this->normal_status === null) {
            return false;
        }

        $snapshot = json_decode($this->config_snapshot, true, flags: JSON_THROW_ON_ERROR);
        $expectedText = $snapshot['expected_text'] ?? null;

        if (! is_string($expectedText) || $expectedText === '') {
            return false;
        }

        $acceptedStatusCodes = array_map('intval', $snapshot['accepted_status_codes'] ?? []);

        return ($this->normal_status >= 200 && $this->normal_status < 400)
            || in_array($this->normal_status, $acceptedStatusCodes, true);
    }

    public function monitor(): BelongsTo
    {
        return $this->belongsTo(Monitor::class);
    }

    public function incidentEvents(): HasMany
    {
        return $this->hasMany(IncidentEvent::class);
    }
}
