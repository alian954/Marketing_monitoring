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

#[Fillable(['monitor_id', 'run_key', 'config_version', 'config_snapshot', 'source', 'status', 'scheduled_at', 'started_at', 'finished_at', 'normal_status', 'adsbot_status', 'error_type', 'error_detail', 'adsbot_error_type', 'elapsed_ms', 'normal_elapsed_ms', 'final_url', 'redirects', 'resolved_public_ips', 'monitor_host_ips', 'monitor_host_cnames', 'monitor_host_dns_records', 'expected_text_found', 'expected_text_term_matches', 'expected_response_header_found', 'response_title', 'response_description', 'response_public_engagement_metrics', 'response_image_reference_hash', 'response_open_graph_type', 'response_canonical_reference_hash', 'response_published_at', 'response_modified_at', 'response_visible_text_hash', 'response_visible_text_changed', 'response_robots_directives', 'response_content_type', 'response_body_bytes', 'tls_expires_at'])]
#[Hidden(['config_snapshot', 'expected_text_term_matches', 'response_image_reference_hash', 'response_canonical_reference_hash', 'response_visible_text_hash'])]
class CheckRun extends Model
{
    /** @use HasFactory<CheckRunFactory> */
    use HasFactory;

    use ScopesThroughAuthenticatedMonitor;

    protected function casts(): array
    {
        return [
            'config_snapshot' => 'encrypted',
            'config_version' => 'integer',
            'normal_status' => 'integer',
            'adsbot_status' => 'integer',
            'elapsed_ms' => 'integer',
            'normal_elapsed_ms' => 'integer',
            'response_body_bytes' => 'integer',
            'scheduled_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'redirects' => 'array',
            'resolved_public_ips' => 'array',
            'monitor_host_ips' => 'array',
            'monitor_host_cnames' => 'array',
            'monitor_host_dns_records' => 'array',
            'expected_text_found' => 'boolean',
            'response_visible_text_changed' => 'boolean',
            'expected_text_term_matches' => 'encrypted:array',
            'expected_response_header_found' => 'boolean',
            'tls_expires_at' => 'immutable_datetime',
            'response_published_at' => 'immutable_datetime',
            'response_modified_at' => 'immutable_datetime',
            'response_robots_directives' => 'array',
            'response_public_engagement_metrics' => 'array',
        ];
    }

    public function passesMonitorChecks(): bool
    {
        if ($this->status !== 'completed'
            || $this->error_type !== null
            || $this->hasUnconfiguredSocialContentExpectation()
            || ($this->usesSocialContentBaseline() && $this->response_visible_text_changed !== false)) {
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

        if (is_string($expectedResponseHeader)
            && trim($expectedResponseHeader) !== ''
            && $this->expectedResponseHeaderMatchResult() !== true) {
            return false;
        }

        return ! $this->hasIndeterminateResponseSizeCheck()
            && $this->responseBodySizeCheckPasses() !== false;
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
        if ($this->error_type === 'social_access_required') {
            return true;
        }

        $snapshot = $this->configurationSnapshot();

        if ($this->usesSocialContentBaseline()) {
            return $this->response_visible_text_changed === null
                && $this->normalStatusIsAccepted($snapshot)
                && in_array($this->error_type, [null, 'body_too_large', 'social_access_required'], true);
        }

        if ($this->hasUnconfiguredSocialContentExpectation()) {
            return $this->normalStatusIsAccepted($snapshot)
                && in_array($this->error_type, [null, 'body_too_large', 'social_access_required'], true);
        }

        if (! in_array($this->error_type, ['body_too_large', 'social_access_required'], true)
            || $this->expectedTextMatchResult() !== null
            || ! $this->normalStatusIsAccepted($snapshot)) {
            return false;
        }

        $snapshot = $this->configurationSnapshot();
        $expectedText = $snapshot['expected_text'] ?? null;

        return is_string($expectedText) && $expectedText !== '';
    }

    public function hasUnconfiguredSocialContentExpectation(): bool
    {
        $snapshot = $this->configurationSnapshot();
        $url = $snapshot['url'] ?? null;
        $expectedText = $snapshot['expected_text'] ?? null;

        return is_string($url)
            && Monitor::socialPlatformKeyForUrl($url) !== null
            && (! is_string($expectedText) || trim($expectedText) === '')
            && ! (bool) ($snapshot['alert_on_visible_text_change'] ?? false);
    }

    public function usesSocialContentBaseline(): bool
    {
        $snapshot = $this->configurationSnapshot();
        $url = $snapshot['url'] ?? null;
        $expectedText = $snapshot['expected_text'] ?? null;

        return is_string($url)
            && Monitor::socialPlatformKeyForUrl($url) !== null
            && (! is_string($expectedText) || trim($expectedText) === '')
            && (bool) ($snapshot['alert_on_visible_text_change'] ?? false);
    }

    public function hasIndeterminateResponseSizeCheck(): bool
    {
        $snapshot = $this->configurationSnapshot();
        $hasThreshold = isset($snapshot['min_response_body_bytes']) || isset($snapshot['max_response_body_bytes']);

        return $hasThreshold
            && $this->response_body_bytes === null
            && $this->normalStatusIsAccepted($snapshot)
            && in_array($this->error_type, [null, 'body_too_large'], true);
    }

    public function responseBodySizeCheckPasses(): ?bool
    {
        $snapshot = $this->configurationSnapshot();
        $minimumBytes = $snapshot['min_response_body_bytes'] ?? null;
        $maximumBytes = $snapshot['max_response_body_bytes'] ?? null;

        if ($minimumBytes === null && $maximumBytes === null) {
            return null;
        }

        if ($this->response_body_bytes === null) {
            return null;
        }

        return ($minimumBytes === null || $this->response_body_bytes >= (int) $minimumBytes)
            && ($maximumBytes === null || $this->response_body_bytes <= (int) $maximumBytes);
    }

    /**
     * @return list<array{source: string, agent: string, directives: list<string>}>
     */
    public function responseRobotsDirectiveRows(): array
    {
        $snapshot = $this->response_robots_directives;

        if (! is_array($snapshot)) {
            return [];
        }

        $sourceLabels = [
            'meta' => 'HTML meta',
            'x_robots_tag' => 'HTTP X-Robots-Tag',
        ];
        $agentLabels = [
            '*' => '全部爬蟲',
            'googlebot' => 'Google 搜尋（Googlebot）',
            'googlebot-news' => 'Google 新聞（Googlebot-News）',
            'adsbot-google' => 'Google Ads（AdsBot-Google）',
            'facebookexternalhit' => 'Facebook 分享預覽',
            'twitterbot' => 'Twitter 分享預覽',
            'bingbot' => 'Bing 搜尋（Bingbot）',
        ];
        $rows = [];

        foreach ($sourceLabels as $source => $sourceLabel) {
            $groups = $snapshot[$source] ?? null;

            if (! is_array($groups)) {
                continue;
            }

            foreach ($groups as $agent => $directives) {
                if (! is_string($agent) || ! is_array($directives)) {
                    continue;
                }

                $directives = array_values(array_filter($directives, 'is_string'));

                if ($directives === []) {
                    continue;
                }

                $rows[] = [
                    'source' => $sourceLabel,
                    'agent' => $agentLabels[$agent] ?? $agent,
                    'directives' => $directives,
                ];
            }
        }

        return $rows;
    }

    public function responseRobotsMetaComplete(): ?bool
    {
        $snapshot = $this->response_robots_directives;
        $complete = is_array($snapshot) ? ($snapshot['meta_complete'] ?? null) : null;

        return is_bool($complete) ? $complete : null;
    }

    public function hasResponseNoIndexDirective(): bool
    {
        if (! is_array($this->response_robots_directives)) {
            return false;
        }

        $searchAgents = ['*', 'googlebot', 'googlebot-news', 'bingbot', 'duckduckbot', 'baiduspider', 'slurp', 'yandexbot'];

        foreach (['meta', 'x_robots_tag'] as $source) {
            $groups = $this->response_robots_directives[$source] ?? null;

            if (! is_array($groups)) {
                continue;
            }

            foreach ($groups as $agent => $directives) {
                if (in_array($agent, $searchAgents, true)
                    && is_array($directives)
                    && (in_array('noindex', $directives, true) || in_array('none', $directives, true))) {
                    return true;
                }
            }
        }

        return false;
    }

    public function failsResponseBodySizeCheck(): bool
    {
        return $this->error_type === null
            && $this->normalStatusIsAccepted($this->configurationSnapshot())
            && $this->responseBodySizeCheckPasses() === false;
    }

    public function expectedTextMatchResult(): ?bool
    {
        return $this->normalStatusIsAccepted($this->configurationSnapshot())
            ? $this->expected_text_found
            : null;
    }

    public function hasRejectedNormalHttpStatus(): bool
    {
        return $this->normal_status !== null
            && ! $this->normalStatusIsAccepted($this->configurationSnapshot());
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
