<?php

namespace App\Models;

use App\Services\Probe\ExpectedTextMatcher;
use Database\Factories\CheckRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['monitor_id', 'run_key', 'config_version', 'config_snapshot', 'source', 'status', 'scheduled_at', 'started_at', 'finished_at', 'normal_status', 'adsbot_status', 'error_type', 'error_detail', 'adsbot_error_type', 'elapsed_ms', 'normal_elapsed_ms', 'final_url', 'redirects', 'resolved_public_ips', 'monitor_host_ips', 'expected_text_found', 'expected_text_term_matches', 'expected_response_header_found', 'response_title', 'response_description', 'response_image_reference_hash', 'response_open_graph_type', 'response_canonical_reference_hash', 'response_published_at', 'response_modified_at', 'tls_expires_at'])]
#[Hidden(['config_snapshot', 'expected_text_term_matches', 'response_image_reference_hash', 'response_canonical_reference_hash'])]
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
            'expected_text_term_matches' => 'encrypted:array',
            'expected_response_header_found' => 'boolean',
            'tls_expires_at' => 'immutable_datetime',
            'response_published_at' => 'immutable_datetime',
            'response_modified_at' => 'immutable_datetime',
        ];
    }

    public function passesMonitorChecks(): bool
    {
        if ($this->status !== 'completed' || $this->error_type !== null) {
            return false;
        }

        $snapshot = $this->configurationSnapshot();

        if (! $this->normalStatusIsAccepted($snapshot)) {
            return false;
        }

        $expectedText = $snapshot['expected_text'] ?? null;

        if (is_string($expectedText) && $expectedText !== '') {
            $contentConditionPassed = ($snapshot['expected_text_mode'] ?? ExpectedTextMatcher::MODE_PRESENT) === ExpectedTextMatcher::MODE_ABSENT
                ? $this->expectedTextMatchResult() === false
                : $this->expectedTextMatchResult() === true;

            if (! $contentConditionPassed) {
                return false;
            }
        }

        $expectedResponseHeader = $snapshot['expected_response_header'] ?? null;

        return ! is_string($expectedResponseHeader)
            || trim($expectedResponseHeader) === ''
            || $this->expectedResponseHeaderMatchResult() === true;
    }

    public function expectedTextMode(): string
    {
        $snapshot = $this->configurationSnapshot();

        $mode = $snapshot['expected_text_mode'] ?? ExpectedTextMatcher::MODE_PRESENT;

        return in_array($mode, [ExpectedTextMatcher::MODE_ABSENT, ExpectedTextMatcher::MODE_PRESENT_ANY], true)
            ? $mode
            : ExpectedTextMatcher::MODE_PRESENT;
    }

    public function hasIndeterminateContentMatch(): bool
    {
        if ($this->error_type !== 'body_too_large'
            || $this->expectedTextMatchResult() !== null
            || ! $this->normalStatusIsAccepted($this->configurationSnapshot())) {
            return false;
        }

        $snapshot = $this->configurationSnapshot();
        $expectedText = $snapshot['expected_text'] ?? null;

        return is_string($expectedText) && $expectedText !== '';
    }

    public function expectedTextMatchResult(): ?bool
    {
        return $this->normalStatusIsAccepted($this->configurationSnapshot())
            ? $this->expected_text_found
            : null;
    }

    public function expectedResponseHeaderMatchResult(): ?bool
    {
        return $this->normalStatusIsAccepted($this->configurationSnapshot())
            ? $this->expected_response_header_found
            : null;
    }

    /**
     * @return list<array{term: string, matched: ?bool}>|null
     */
    public function expectedTextMatchDetails(): ?array
    {
        $snapshot = $this->configurationSnapshot();

        if (! $this->normalStatusIsAccepted($snapshot)) {
            return null;
        }

        $matchedTerms = $this->expected_text_term_matches;

        if (! is_array($matchedTerms)) {
            return null;
        }

        $terms = ExpectedTextMatcher::terms($snapshot['expected_text'] ?? null);

        if ($terms === [] || count($terms) !== count($matchedTerms)) {
            return null;
        }

        return array_map(
            static fn (string $term, mixed $matched): array => [
                'term' => $term,
                'matched' => $matched === null ? null : (bool) $matched,
            ],
            $terms,
            $matchedTerms,
        );
    }

    public function monitor(): BelongsTo
    {
        return $this->belongsTo(Monitor::class);
    }

    public function incidentEvents(): HasMany
    {
        return $this->hasMany(IncidentEvent::class);
    }

    /** @return array<string, mixed> */
    private function configurationSnapshot(): array
    {
        $snapshot = json_decode($this->config_snapshot, true, flags: JSON_THROW_ON_ERROR);

        return is_array($snapshot) ? $snapshot : [];
    }

    /** @param array<string, mixed> $snapshot */
    private function normalStatusIsAccepted(array $snapshot): bool
    {
        $statusCode = $this->normal_status;

        if ($statusCode === null) {
            return false;
        }

        $acceptedStatusCodes = array_map('intval', $snapshot['accepted_status_codes'] ?? []);

        return ($statusCode >= 200 && $statusCode < 400)
            || in_array($statusCode, $acceptedStatusCodes, true);
    }
}
