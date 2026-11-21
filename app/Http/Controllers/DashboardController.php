<?php

namespace App\Http\Controllers;

use App\Models\CheckRun;
use App\Models\DnsChangeEvent;
use App\Models\DomainRegistrationObservation;
use App\Models\Incident;
use App\Models\MaintenanceWindow;
use App\Models\Monitor;
use App\Models\SchedulerHeartbeat;
use App\Models\TlsObservation;
use App\Services\CsvFormulaSanitizer;
use App\Services\FleetAvailabilityReport;
use App\Services\UrlMasker;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class DashboardController extends Controller
{
    private const QUEUED_CHECK_WARNING_AFTER_SECONDS = 120;

    private const RUNNING_CHECK_WARNING_AFTER_SECONDS = 180;

    private const MAINTENANCE_LOOKAHEAD_DAYS = 30;

    private const MAX_DASHBOARD_MAINTENANCE_OCCURRENCES = 10;

    private const MAX_DASHBOARD_MONITORS = 8;

    private const MAX_DASHBOARD_SOCIAL_ENGAGEMENT_MONITORS = 50;

    private const MAX_DASHBOARD_SOCIAL_TOP_POSTS = 5;

    /** @var array<string, string> */
    private const SOCIAL_ENGAGEMENT_TAG_SORT_OPTIONS = [
        'tag' => '依標籤名稱',
        'average_interactions' => '平均互動／篇由高到低',
        'engagement_rate' => '觀看互動率由高到低',
    ];

    /** @var array<string, string> */
    private const SOCIAL_ENGAGEMENT_TOP_POST_SORT_OPTIONS = [
        'interactions' => '總互動由高到低',
        'engagement_rate' => '觀看互動率由高到低',
    ];

    /** @var array<string, string> */
    private const SOCIAL_ENGAGEMENT_GROWTH_POST_SORT_OPTIONS = [
        'change' => '依互動淨變化',
        'growth_rate' => '依互動成長率',
    ];

    private const HISTORY_TIMEZONE = 'Asia/Taipei';

    private const TLS_OBSERVATION_STALE_AFTER_HOURS = 48;

    public function index(Request $request, UrlMasker $urlMasker, FleetAvailabilityReport $availabilityReport): View
    {
        $asOf = now()->toImmutable();
        $fleetAvailability = $availabilityReport->build($asOf);
        $maintenanceSummary = $this->maintenanceSummary($asOf);
        $monitorCount = 0;
        $dashboardMonitorCandidates = [];
        $counts = ['healthy' => 0, 'issue' => 0, 'warning' => 0, 'stale' => 0, 'paused' => 0];
        $platformCounts = [];

        foreach (['facebook' => 'Facebook', 'instagram' => 'Instagram', 'threads' => 'Threads', 'dcard' => 'Dcard'] as $platform => $label) {
            $platformCounts[$platform] = [
                'label' => $label,
                'total' => 0,
                'enabled_total' => 0,
                'engagement_captured' => 0,
                'states' => array_fill_keys(['healthy', 'issue', 'warning', 'stale', 'paused', 'pending'], 0),
            ];
        }

        $expiringCertificateCount = 0;
        $expiringCertificates = [];
        $expiringDomainCount = 0;
        $expiringDomains = [];

        Monitor::query()
            ->with(['latestCheckRun', 'latestTlsObservation', 'latestDomainRegistrationObservation', 'activeIncidents'])
            ->chunkById(500, function (EloquentCollection $monitorBatch) use (
                $asOf,
                &$monitorCount,
                &$dashboardMonitorCandidates,
                &$counts,
                &$platformCounts,
                &$expiringCertificates,
                &$expiringCertificateCount,
                &$expiringDomains,
                &$expiringDomainCount,
            ): void {
                foreach ($monitorBatch as $monitor) {
                    $monitorCount++;
                    $healthState = $monitor->healthState();
                    $monitor->setAttribute('health_state', $healthState);

                    $dashboardMonitorCandidates[] = [
                        'monitor' => $monitor,
                        ...$this->dashboardMonitorPriority($monitor, $healthState),
                    ];

                    usort($dashboardMonitorCandidates, static function (array $first, array $second): int {
                        return ($second['enabled_priority'] <=> $first['enabled_priority'])
                            ?: ($second['critical_incident_count'] <=> $first['critical_incident_count'])
                            ?: ($second['warning_incident_count'] <=> $first['warning_incident_count'])
                            ?: ($first['health_state_priority'] <=> $second['health_state_priority'])
                            ?: strcasecmp($first['monitor']->name, $second['monitor']->name)
                            ?: ($first['monitor']->id <=> $second['monitor']->id);
                    });

                    if (count($dashboardMonitorCandidates) > self::MAX_DASHBOARD_MONITORS) {
                        array_pop($dashboardMonitorCandidates);
                    }

                    if (in_array($healthState, ['issue', 'suspect'], true)) {
                        $counts['issue']++;
                    } elseif (array_key_exists($healthState, $counts)) {
                        $counts[$healthState]++;
                    }

                    $platform = $monitor->social_platform;
                    if (isset($platformCounts[$platform])) {
                        $platformCounts[$platform]['total']++;

                        if ($monitor->enabled) {
                            $platformCounts[$platform]['enabled_total']++;
                            $latestCheckRun = $monitor->latestCheckRun;
                            $latestMetrics = $latestCheckRun?->response_public_engagement_metrics;
                            $hasPublicEngagementMetric = false;

                            if ($latestCheckRun?->status === 'completed' && is_array($latestMetrics)) {
                                foreach (['views', 'likes', 'comments', 'shares'] as $metric) {
                                    if (is_numeric($latestMetrics[$metric] ?? null)) {
                                        $hasPublicEngagementMetric = true;

                                        break;
                                    }
                                }
                            }

                            if ($hasPublicEngagementMetric) {
                                $platformCounts[$platform]['engagement_captured']++;
                            }
                        }

                        $platformState = $healthState === 'suspect' ? 'issue' : $healthState;

                        if (array_key_exists($platformState, $platformCounts[$platform]['states'])) {
                            $platformCounts[$platform]['states'][$platformState]++;
                        }
                    }

                    $certificateObservation = $monitor->latestTlsObservation;
                    $certificateExpiresAt = $certificateObservation?->expires_at;

                    if ($certificateExpiresAt !== null && $certificateExpiresAt->lessThanOrEqualTo($asOf->addDays(30))) {
                        $expiringCertificateCount++;
                        $expiringCertificates[] = $this->expirySummary($monitor, $certificateObservation, $asOf);
                        $this->sortExpirySummaries($expiringCertificates);
                    }

                    $domainObservation = $monitor->latestDomainRegistrationObservation;
                    $domainExpiresAt = $domainObservation?->expires_at;

                    if ($monitor->social_platform === null
                        && $domainExpiresAt !== null
                        && $domainExpiresAt->lessThanOrEqualTo($asOf->addDays(30))) {
                        $expiringDomainCount++;
                        $expiringDomains[] = [
                            ...$this->expirySummary($monitor, $domainObservation, $asOf),
                            'domain_name' => $domainObservation->domain_name,
                        ];
                        $this->sortExpirySummaries($expiringDomains);
                    }
                }
            });

        $monitors = collect(array_map(
            static fn (array $candidate): Monitor => $candidate['monitor'],
            $dashboardMonitorCandidates,
        ));
        $monitors->each(function (Monitor $monitor) use ($urlMasker): void {
            $monitor->setAttribute('masked_url', $urlMasker->mask($monitor->url));
        });
        $socialEngagementPlatformOptions = [
            'all' => '全部平台',
            'facebook' => 'Facebook',
            'instagram' => 'Instagram',
            'threads' => 'Threads',
            'dcard' => 'Dcard',
        ];
        $requestedSocialEngagementPlatform = $request->query('engagement_platform');
        $socialEngagementSelectedPlatform = is_string($requestedSocialEngagementPlatform)
            && array_key_exists($requestedSocialEngagementPlatform, $socialEngagementPlatformOptions)
                ? $requestedSocialEngagementPlatform
                : 'all';
        $socialEngagementPlatform = $socialEngagementSelectedPlatform === 'all'
            ? null
            : $socialEngagementSelectedPlatform;
        $socialEngagementTopPostSortOptions = self::SOCIAL_ENGAGEMENT_TOP_POST_SORT_OPTIONS;

        if ($socialEngagementPlatform === 'dcard') {
            $socialEngagementTopPostSortOptions['interactions'] = '讚＋留言合計由高到低';
        }

        $requestedSocialEngagementTagSort = $request->query('engagement_tag_sort');
        $socialEngagementTagSort = is_string($requestedSocialEngagementTagSort)
            && array_key_exists($requestedSocialEngagementTagSort, self::SOCIAL_ENGAGEMENT_TAG_SORT_OPTIONS)
                ? $requestedSocialEngagementTagSort
                : 'tag';
        $requestedSocialEngagementTopPostSort = $request->query('engagement_post_sort');
        $socialEngagementTopPostSort = is_string($requestedSocialEngagementTopPostSort)
            && array_key_exists($requestedSocialEngagementTopPostSort, self::SOCIAL_ENGAGEMENT_TOP_POST_SORT_OPTIONS)
                ? $requestedSocialEngagementTopPostSort
                : 'interactions';
        $requestedSocialEngagementGrowthPostSort = $request->query('engagement_growth_sort');
        $socialEngagementGrowthPostSort = is_string($requestedSocialEngagementGrowthPostSort)
            && array_key_exists($requestedSocialEngagementGrowthPostSort, self::SOCIAL_ENGAGEMENT_GROWTH_POST_SORT_OPTIONS)
                ? $requestedSocialEngagementGrowthPostSort
                : 'change';
        $allSocialEngagementSummaries = $this->socialEngagementSummaries($socialEngagementPlatform, $socialEngagementTagSort);
        $socialEngagementTagFilterOptions = ['all' => '所有標籤'];
        $availableSocialEngagementTags = [];
        $hasUntaggedSocialEngagementPosts = false;

        foreach ($allSocialEngagementSummaries as $summary) {
            if ($summary['filter_label'] === null) {
                $hasUntaggedSocialEngagementPosts = true;

                continue;
            }

            $availableSocialEngagementTags[$summary['filter_label']] = $summary['filter_label'];
        }

        asort($availableSocialEngagementTags, SORT_NATURAL | SORT_FLAG_CASE);

        foreach ($availableSocialEngagementTags as $tag => $tagLabel) {
            $socialEngagementTagFilterOptions['tag:'.$tag] = $tagLabel;
        }

        if ($hasUntaggedSocialEngagementPosts) {
            $socialEngagementTagFilterOptions['unlabeled'] = '未標籤';
        }

        $requestedSocialEngagementTag = $request->query('engagement_tag');
        $socialEngagementSelectedTag = is_string($requestedSocialEngagementTag)
            && array_key_exists($requestedSocialEngagementTag, $socialEngagementTagFilterOptions)
                ? $requestedSocialEngagementTag
                : 'all';
        $socialEngagementSelectedTagValue = str_starts_with($socialEngagementSelectedTag, 'tag:')
            ? substr($socialEngagementSelectedTag, 4)
            : null;
        $socialEngagementMonitorsQuery = Monitor::query()
            ->whereNotNull('social_platform');

        if ($socialEngagementPlatform !== null) {
            $socialEngagementMonitorsQuery->where('social_platform', $socialEngagementPlatform);
        }

        if ($socialEngagementSelectedTagValue !== null) {
            $socialEngagementMonitorsQuery->where(function (Builder $query) use ($socialEngagementSelectedTagValue): void {
                $query->whereJsonContains('tags', $socialEngagementSelectedTagValue)
                    ->orWhere('label', $socialEngagementSelectedTagValue);
            });
        } elseif ($socialEngagementSelectedTag === 'unlabeled') {
            $socialEngagementMonitorsQuery->whereNull('label');
        }

        $socialEngagementMonitors = $socialEngagementMonitorsQuery
            ->with(['latestCheckRun', 'latestPublicEngagementCheckRun'])
            ->orderByDesc('last_checked_at')
            ->orderBy('name')
            ->paginate(
                self::MAX_DASHBOARD_SOCIAL_ENGAGEMENT_MONITORS,
                ['id', 'name', 'url', 'config_version', 'social_platform'],
                'engagement_page',
            )
            ->withQueryString()
            ->through(function (Monitor $monitor): Monitor {
                $latestCheckRun = $monitor->latestCheckRun;
                $engagementRun = $monitor->latestPublicEngagementCheckRun;

                if ($latestCheckRun?->config_version !== $monitor->config_version) {
                    $latestCheckRun = null;
                }

                if ($engagementRun?->config_version !== $monitor->config_version) {
                    $engagementRun = null;
                }

                $engagementMetrics = $engagementRun?->response_public_engagement_metrics ?? [];
                $interactionTotal = Monitor::publicEngagementInteractionTotal(
                    $engagementMetrics,
                    $monitor->social_platform,
                );
                $views = $engagementMetrics['views'] ?? null;
                $engagementRate = $interactionTotal !== null && is_numeric($views) && (int) $views > 0
                    ? round($interactionTotal / (int) $views * 100, 2)
                    : null;

                $monitor->setAttribute(
                    'social_engagement_capture_status',
                    $this->socialEngagementCaptureStatus($latestCheckRun, $engagementRun),
                );
                $monitor->setAttribute('social_engagement_interaction_total', $interactionTotal);
                $monitor->setAttribute('social_engagement_rate_percent', $engagementRate);

                return $monitor;
            });
        $socialEngagementSummaries = array_values(array_filter(
            $allSocialEngagementSummaries,
            static fn (array $summary): bool => match (true) {
                $socialEngagementSelectedTag === 'unlabeled' => $summary['filter_label'] === null,
                $socialEngagementSelectedTagValue !== null => $summary['filter_label'] === $socialEngagementSelectedTagValue,
                default => true,
            },
        ));
        $socialEngagementDashboardData = $this->socialEngagementDashboardData(
            $socialEngagementPlatform,
            $socialEngagementSelectedTag,
            $socialEngagementSelectedTagValue,
            $socialEngagementTopPostSort,
        );
        $socialEngagementComparisonSummaries = array_values(array_filter(
            $socialEngagementDashboardData['summaries'],
            static fn (array $summary): bool => match (true) {
                $socialEngagementSelectedTag === 'unlabeled' => $summary['filter_label'] === null,
                $socialEngagementSelectedTagValue !== null => $summary['filter_label'] === $socialEngagementSelectedTagValue,
                default => true,
            },
        ));
        $socialEngagementTopPosts = $socialEngagementDashboardData['top_posts'];
        $socialEngagementRetentionDays = max(1, (int) config('monitoring.retention_days'));
        $socialEngagementChangePeriodOptions = $this->socialEngagementChangePeriodOptions($socialEngagementRetentionDays);
        $requestedSocialEngagementPeriod = $request->query('engagement_period');
        $socialEngagementChangePeriodDays = is_string($requestedSocialEngagementPeriod)
            && ctype_digit($requestedSocialEngagementPeriod)
            && array_key_exists((int) $requestedSocialEngagementPeriod, $socialEngagementChangePeriodOptions)
                ? (int) $requestedSocialEngagementPeriod
                : (int) array_key_first($socialEngagementChangePeriodOptions);
        $socialEngagementChangeComparisonAvailable = $socialEngagementRetentionDays >= $socialEngagementChangePeriodDays * 2;
        $socialEngagementChangeData = $this->socialEngagementChangeSummaries(
            $asOf,
            $socialEngagementSummaries,
            $socialEngagementChangePeriodDays,
            $socialEngagementChangeComparisonAvailable,
            $socialEngagementPlatform,
            $socialEngagementGrowthPostSort,
        );
        $socialEngagementChangeSummaries = $socialEngagementChangeData['summaries'];
        $socialEngagementGrowthPosts = $socialEngagementChangeData['growth_posts'];

        $incidents = Incident::query()
            ->with(['monitor:id,name', 'acknowledgedBy:id,name'])
            ->whereIn('state', [Incident::STATE_SUSPECT, Incident::STATE_OPEN])
            ->latest('last_seen_at')
            ->limit(20)
            ->get();
        $dnsChanges = DnsChangeEvent::query()
            ->with('monitor:id,name')
            ->latest('detected_at')
            ->limit(10)
            ->get();
        $heartbeat = SchedulerHeartbeat::query()->where('job_key', 'monitor-scheduler')->first();
        $heartbeatFresh = $heartbeat?->last_succeeded_at !== null && $heartbeat->last_succeeded_at->gte(now()->subMinutes(2));
        $workerHeartbeat = SchedulerHeartbeat::query()->where('job_key', 'monitor-worker')->first();
        $workerHeartbeatFresh = $workerHeartbeat?->last_succeeded_at !== null && $workerHeartbeat->last_succeeded_at->gte(now()->subMinutes(2));
        $queueWorkerEnabled = config('queue.default') !== 'sync';
        $checkRunQueueHealth = $queueWorkerEnabled ? $this->checkRunQueueHealth($asOf) : null;
        $queueSize = null;
        $queueStateCounts = null;
        $failedQueueJobStorageSupported = in_array(config('queue.failed.driver'), ['database', 'database-uuids'], true);
        $failedQueueJobCount = null;

        if ($queueWorkerEnabled) {
            try {
                $queueSize = Queue::size();
            } catch (Throwable $exception) {
                report($exception);
            }

            try {
                $queueConnection = Queue::connection();

                if (method_exists($queueConnection, 'pendingSize')
                    && method_exists($queueConnection, 'delayedSize')
                    && method_exists($queueConnection, 'reservedSize')) {
                    $queueStateCounts = [
                        'pending' => $queueConnection->pendingSize(),
                        'delayed' => $queueConnection->delayedSize(),
                        'reserved' => $queueConnection->reservedSize(),
                    ];
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        if ($failedQueueJobStorageSupported) {
            try {
                $failedQueueJobCount = DB::connection(config('queue.failed.database'))
                    ->table(config('queue.failed.table'))
                    ->count();
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return view('dashboard', [
            'monitors' => $monitors,
            'monitorCount' => $monitorCount,
            'expiringCertificates' => collect($expiringCertificates)->values(),
            'expiringCertificateCount' => $expiringCertificateCount,
            'expiringDomains' => collect($expiringDomains)->values(),
            'expiringDomainCount' => $expiringDomainCount,
            'incidents' => $incidents,
            'dnsChanges' => $dnsChanges,
            'heartbeat' => $heartbeat,
            'heartbeatFresh' => $heartbeatFresh,
            'workerHeartbeat' => $workerHeartbeat,
            'workerHeartbeatFresh' => $workerHeartbeatFresh,
            'queueWorkerEnabled' => $queueWorkerEnabled,
            'checkRunQueueHealth' => $checkRunQueueHealth,
            'queueSize' => $queueSize,
            'queueStateCounts' => $queueStateCounts,
            'failedQueueJobStorageSupported' => $failedQueueJobStorageSupported,
            'failedQueueJobCount' => $failedQueueJobCount,
            'counts' => $counts,
            'platformCounts' => $platformCounts,
            'socialEngagementMonitors' => $socialEngagementMonitors,
            'socialEngagementPlatformOptions' => $socialEngagementPlatformOptions,
            'socialEngagementSelectedPlatform' => $socialEngagementSelectedPlatform,
            'socialEngagementTagFilterOptions' => $socialEngagementTagFilterOptions,
            'socialEngagementSelectedTag' => $socialEngagementSelectedTag,
            'socialEngagementSummaries' => $socialEngagementSummaries,
            'socialEngagementComparisonSummaries' => $socialEngagementComparisonSummaries,
            'socialEngagementTopPosts' => $socialEngagementTopPosts,
            'socialEngagementTopPostSortOptions' => $socialEngagementTopPostSortOptions,
            'socialEngagementTopPostSort' => $socialEngagementTopPostSort,
            'socialEngagementComparisonPlatformOptions' => [
                'facebook' => 'Facebook',
                'instagram' => 'Instagram',
                'threads' => 'Threads',
                'dcard' => 'Dcard',
            ],
            'socialEngagementTagSortOptions' => self::SOCIAL_ENGAGEMENT_TAG_SORT_OPTIONS,
            'socialEngagementTagSort' => $socialEngagementTagSort,
            'socialEngagementChangeSummaries' => $socialEngagementChangeSummaries,
            'socialEngagementGrowthPosts' => $socialEngagementGrowthPosts,
            'socialEngagementGrowthPostSortOptions' => self::SOCIAL_ENGAGEMENT_GROWTH_POST_SORT_OPTIONS,
            'socialEngagementGrowthPostSort' => $socialEngagementGrowthPostSort,
            'socialEngagementTrend' => $socialEngagementChangeData['trend'],
            'socialEngagementTrendScale' => $socialEngagementChangeData['trend_scale'],
            'socialEngagementTrendAvailable' => $socialEngagementChangeData['trend_available'],
            'socialEngagementChangePeriodDays' => $socialEngagementChangePeriodDays,
            'socialEngagementChangePeriodOptions' => $socialEngagementChangePeriodOptions,
            'socialEngagementChangeComparisonAvailable' => $socialEngagementChangeComparisonAvailable,
            'socialEngagementRetentionDays' => $socialEngagementRetentionDays,
            'fleetAvailability' => $fleetAvailability,
            'maintenanceSummary' => $maintenanceSummary,
        ]);
    }

    public function exportSocialEngagementGrowth(Request $request, UrlMasker $urlMasker): StreamedResponse
    {
        $asOf = now()->toImmutable();
        $requestedPlatform = $request->query('engagement_platform');
        $platform = is_string($requestedPlatform) && in_array($requestedPlatform, ['facebook', 'instagram', 'threads', 'dcard'], true)
            ? $requestedPlatform
            : null;
        $allTagSummaries = $this->socialEngagementSummaries($platform, 'tag');
        $availableTags = array_column($allTagSummaries, 'filter_label');
        $requestedTag = $request->query('engagement_tag');
        $selectedTag = 'all';
        $selectedTagValue = null;

        if ($requestedTag === 'unlabeled'
            && in_array(null, $availableTags, true)) {
            $selectedTag = 'unlabeled';
        } elseif (is_string($requestedTag) && str_starts_with($requestedTag, 'tag:')) {
            $candidateTag = substr($requestedTag, 4);

            if ($candidateTag !== ''
                && in_array($candidateTag, $availableTags, true)) {
                $selectedTag = $requestedTag;
                $selectedTagValue = $candidateTag;
            }
        }

        $tagSummaries = array_values(array_filter(
            $allTagSummaries,
            static fn (array $summary): bool => match (true) {
                $selectedTag === 'unlabeled' => $summary['filter_label'] === null,
                $selectedTagValue !== null => $summary['filter_label'] === $selectedTagValue,
                default => true,
            },
        ));
        $retentionDays = max(1, (int) config('monitoring.retention_days'));
        $periodOptions = $this->socialEngagementChangePeriodOptions($retentionDays);
        $requestedPeriod = $request->query('engagement_period');
        $periodDays = is_string($requestedPeriod)
            && ctype_digit($requestedPeriod)
            && array_key_exists((int) $requestedPeriod, $periodOptions)
                ? (int) $requestedPeriod
                : (int) array_key_first($periodOptions);
        $requestedGrowthSort = $request->query('engagement_growth_sort');
        $growthSort = is_string($requestedGrowthSort)
            && array_key_exists($requestedGrowthSort, self::SOCIAL_ENGAGEMENT_GROWTH_POST_SORT_OPTIONS)
                ? $requestedGrowthSort
                : 'change';
        $changeData = $this->socialEngagementChangeSummaries(
            $asOf,
            $tagSummaries,
            $periodDays,
            $retentionDays >= $periodDays * 2,
            $platform,
            $growthSort,
        );
        $growthPosts = $changeData['growth_posts'];
        $monitors = $growthPosts === []
            ? []
            : Monitor::query()
                ->whereKey(array_column($growthPosts, 'id'))
                ->get(['id', 'name', 'url', 'tags', 'label'])
                ->keyBy('id')
                ->all();
        $filename = 'social-engagement-growth-'.$periodDays.'-days-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($growthPosts, $monitors, $periodDays, $urlMasker): void {
            $stream = fopen('php://output', 'w');
            fputcsv($stream, [
                'monitor_id',
                'monitor_name',
                'platform',
                'url',
                'tags',
                'period_days',
                'starting_interactions',
                'ending_interactions',
                'interaction_net_change',
                'interaction_growth_rate_percent',
                'likes_change',
                'comments_change',
                'shares_change',
                'available_metrics',
                'comparable_current_interaction_change',
                'previous_period_interaction_change',
                'period_interaction_change_difference',
                'period_comparable_metrics',
                'last_observed_at_utc',
            ], ',', '"', '');

            foreach ($growthPosts as $growthPost) {
                $monitor = $monitors[$growthPost['id']] ?? null;
                $metricChanges = $growthPost['metric_changes'];
                $fields = [
                    (string) $growthPost['id'],
                    $monitor?->name ?? $growthPost['name'],
                    $growthPost['platform'],
                    $monitor === null ? '' : $urlMasker->mask($monitor->url),
                    $monitor?->tagInputValue() ?? '',
                    (string) $periodDays,
                    (string) $growthPost['starting_interactions'],
                    (string) ($growthPost['starting_interactions'] + $growthPost['interaction_change']),
                    (string) $growthPost['interaction_change'],
                    $growthPost['interaction_growth_rate'] === null ? '' : number_format($growthPost['interaction_growth_rate'], 1, '.', ''),
                    ...array_map(
                        static fn (string $metric): string => $metricChanges[$metric] === null ? '' : (string) $metricChanges[$metric],
                        ['likes', 'comments', 'shares'],
                    ),
                    (string) $growthPost['available_metrics'],
                    $growthPost['comparable_current_interaction_change'] === null ? '' : (string) $growthPost['comparable_current_interaction_change'],
                    $growthPost['previous_period_interaction_change'] === null ? '' : (string) $growthPost['previous_period_interaction_change'],
                    $growthPost['period_interaction_change_difference'] === null ? '' : (string) $growthPost['period_interaction_change_difference'],
                    (string) $growthPost['period_comparable_metrics'],
                    $growthPost['finished_at']->utc()->toIso8601String(),
                ];

                fputcsv($stream, array_map(CsvFormulaSanitizer::escape(...), $fields), ',', '"', '');
            }

            fclose($stream);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @return list<array{
     *     tag: string,
     *     filter_label: string|null,
     *     monitor_count: int,
     *     metrics: array<string, array{total: int, available: int}>,
     *     interaction_total: int,
     *     interaction_count: int,
     *     average_interactions_per_post: float|null,
     *     engagement_rate: array{interactions: int, views: int, available: int},
     *     interaction_scope_label: string,
     *     capture_counts: array{current: int, previous: int},
     *     latest_at: CarbonImmutable|null
     * }>
     */
    private function socialEngagementSummaries(?string $platform, string $sort): array
    {
        /**
         * @var array<string, array{
         *     tag: string,
         *     filter_label: string|null,
         *     monitor_count: int,
         *     metrics: array<string, array{total: int, available: int}>,
         *     interaction_total: int,
         *     interaction_count: int,
         *     average_interactions_per_post: float|null,
         *     engagement_rate: array{interactions: int, views: int, available: int},
         *     interaction_scope_label: string,
         *     capture_counts: array{current: int, previous: int},
         *     latest_at: CarbonImmutable|null
         * }> $summaries
         */
        $summaries = [];

        $monitorsQuery = Monitor::query()->whereNotNull('social_platform');

        if ($platform !== null) {
            $monitorsQuery->where('social_platform', $platform);
        }

        $monitorsQuery
            ->select(['id', 'social_platform', 'config_version', 'tags', 'label'])
            ->with(['latestCheckRun', 'latestPublicEngagementCheckRun'])
            ->chunkById(250, function (EloquentCollection $monitorBatch) use (&$summaries, $platform): void {
                foreach ($monitorBatch as $monitor) {
                    $engagementRun = $monitor->latestPublicEngagementCheckRun;

                    if ($engagementRun?->config_version !== $monitor->config_version) {
                        $engagementRun = null;
                    }

                    $latestCheckRun = $monitor->latestCheckRun;
                    $metrics = $engagementRun?->response_public_engagement_metrics ?? [];
                    $hasEngagementMetrics = $this->hasPublicEngagementMetrics($metrics);
                    $captureIsCurrent = $engagementRun !== null && $latestCheckRun?->id === $engagementRun->id;
                    $tags = $monitor->tagNames();
                    $tagEntries = $tags === [] ? [null] : array_unique($tags);

                    foreach ($tagEntries as $tag) {
                        $tagKey = $tag ?? "\0untagged";

                        if (! isset($summaries[$tagKey])) {
                            $summaries[$tagKey] = [
                                'tag' => $tag ?? '未標籤',
                                'filter_label' => $tag,
                                'monitor_count' => 0,
                                'metrics' => array_fill_keys(['views', 'likes', 'comments', 'shares'], ['total' => 0, 'available' => 0]),
                                'interaction_total' => 0,
                                'interaction_count' => 0,
                                'average_interactions_per_post' => null,
                                'engagement_rate' => ['interactions' => 0, 'views' => 0, 'available' => 0],
                                'interaction_scope_label' => $platform === 'dcard' ? '讚＋留言' : '讚＋留言＋分享',
                                'capture_counts' => ['current' => 0, 'previous' => 0],
                                'latest_at' => null,
                            ];
                        }

                        $summaries[$tagKey]['monitor_count']++;

                        if ($hasEngagementMetrics) {
                            $captureKey = $captureIsCurrent ? 'current' : 'previous';
                            $summaries[$tagKey]['capture_counts'][$captureKey]++;
                        }

                        foreach (array_keys($summaries[$tagKey]['metrics']) as $metric) {
                            if (is_numeric($metrics[$metric] ?? null)) {
                                $summaries[$tagKey]['metrics'][$metric]['total'] += (int) $metrics[$metric];
                                $summaries[$tagKey]['metrics'][$metric]['available']++;
                            }
                        }

                        $interactionTotal = Monitor::publicEngagementInteractionTotal($metrics, $platform);

                        if ($interactionTotal !== null) {
                            $summaries[$tagKey]['interaction_total'] += $interactionTotal;
                            $summaries[$tagKey]['interaction_count']++;
                            $summaries[$tagKey]['average_interactions_per_post'] = round(
                                $summaries[$tagKey]['interaction_total'] / $summaries[$tagKey]['interaction_count'],
                                1,
                            );
                        }

                        if ($interactionTotal !== null
                            && is_numeric($metrics['views'] ?? null)
                            && (int) $metrics['views'] > 0) {
                            $summaries[$tagKey]['engagement_rate']['views'] += (int) $metrics['views'];
                            $summaries[$tagKey]['engagement_rate']['interactions'] += $interactionTotal;
                            $summaries[$tagKey]['engagement_rate']['available']++;
                        }

                        $finishedAt = $engagementRun?->finished_at;

                        if ($finishedAt !== null
                            && ($summaries[$tagKey]['latest_at'] === null || $finishedAt->greaterThan($summaries[$tagKey]['latest_at']))) {
                            $summaries[$tagKey]['latest_at'] = $finishedAt;
                        }
                    }
                }
            });

        $results = array_values($summaries);
        usort($results, static function (array $first, array $second) use ($sort): int {
            if ($sort === 'average_interactions') {
                $firstAverage = $first['average_interactions_per_post'];
                $secondAverage = $second['average_interactions_per_post'];

                if ($firstAverage === null && $secondAverage !== null) {
                    return 1;
                }

                if ($firstAverage !== null && $secondAverage === null) {
                    return -1;
                }

                if ($firstAverage !== null && $secondAverage !== null) {
                    $averageComparison = $secondAverage <=> $firstAverage;

                    if ($averageComparison !== 0) {
                        return $averageComparison;
                    }
                }
            }

            if ($sort === 'engagement_rate') {
                $firstEngagementRate = $first['engagement_rate']['views'] > 0
                    ? $first['engagement_rate']['interactions'] / $first['engagement_rate']['views']
                    : null;
                $secondEngagementRate = $second['engagement_rate']['views'] > 0
                    ? $second['engagement_rate']['interactions'] / $second['engagement_rate']['views']
                    : null;

                if ($firstEngagementRate === null && $secondEngagementRate !== null) {
                    return 1;
                }

                if ($firstEngagementRate !== null && $secondEngagementRate === null) {
                    return -1;
                }

                if ($firstEngagementRate !== null && $secondEngagementRate !== null) {
                    $rateComparison = $secondEngagementRate <=> $firstEngagementRate;

                    if ($rateComparison !== 0) {
                        return $rateComparison;
                    }
                }
            }

            return strnatcasecmp($first['tag'], $second['tag']);
        });

        return $results;
    }

    /**
     * @return array{
     *     summaries: list<array{
     *         tag: string,
     *         filter_label: string|null,
     *         platforms: array<string, array{
     *             monitor_count: int,
     *             metrics: array<string, array{total: int, available: int}>,
     *             interaction_total: int,
     *             interaction_count: int,
     *             average_interactions_per_post: float|null,
     *             engagement_rate: array{interactions: int, views: int, available: int},
     *             interaction_scope_label: string,
     *             capture_counts: array{current: int, previous: int}
     *         }>
     *     }>,
     *     top_posts: list<array{
     *         id: int,
     *         name: string,
     *         platform: string,
     *         tags: list<string>,
     *         views: int|null,
     *         likes: int,
     *         comments: int,
     *         shares: int|null,
     *         interaction_total: int,
     *         interaction_metric_keys: list<string>,
     *         interaction_scope_label: string,
     *         metrics_freshness: string,
     *         engagement_rate_percent: float|null,
     *         finished_at: CarbonImmutable|null
     *     }>
     * }
     */
    private function socialEngagementDashboardData(?string $selectedPlatform, string $selectedTag, ?string $selectedTagValue, string $topPostSort): array
    {
        /** @var array<string, array{tag: string, filter_label: string|null, platforms: array<string, array{monitor_count: int, metrics: array<string, array{total: int, available: int}>, interaction_total: int, interaction_count: int, average_interactions_per_post: float|null, engagement_rate: array{interactions: int, views: int, available: int}, interaction_scope_label: string, capture_counts: array{current: int, previous: int}}>}> $summaries */
        $summaries = [];
        $topPosts = [];
        $supportedPlatforms = ['facebook', 'instagram', 'threads', 'dcard'];
        $platformLabels = ['facebook' => 'Facebook', 'instagram' => 'Instagram', 'threads' => 'Threads', 'dcard' => 'Dcard'];
        $sortTopPosts = static function (array $first, array $second) use ($topPostSort): int {
            if ($topPostSort === 'engagement_rate') {
                $firstRate = $first['engagement_rate_percent'];
                $secondRate = $second['engagement_rate_percent'];

                if ($firstRate === null && $secondRate !== null) {
                    return 1;
                }

                if ($firstRate !== null && $secondRate === null) {
                    return -1;
                }

                if ($firstRate !== null && $secondRate !== null) {
                    $rateComparison = $secondRate <=> $firstRate;

                    if ($rateComparison !== 0) {
                        return $rateComparison;
                    }
                }
            }

            $interactionComparison = $second['interaction_total'] <=> $first['interaction_total'];

            if ($interactionComparison !== 0) {
                return $interactionComparison;
            }

            if ($topPostSort !== 'engagement_rate') {
                $firstRate = $first['engagement_rate_percent'];
                $secondRate = $second['engagement_rate_percent'];

                if ($firstRate === null && $secondRate !== null) {
                    return 1;
                }

                if ($firstRate !== null && $secondRate === null) {
                    return -1;
                }

                if ($firstRate !== null && $secondRate !== null) {
                    $rateComparison = $secondRate <=> $firstRate;

                    if ($rateComparison !== 0) {
                        return $rateComparison;
                    }
                }
            }

            $finishedAtComparison = ($second['finished_at']?->getTimestamp() ?? 0)
                <=> ($first['finished_at']?->getTimestamp() ?? 0);

            return $finishedAtComparison !== 0
                ? $finishedAtComparison
                : (strnatcasecmp($first['name'], $second['name']) ?: ($first['id'] <=> $second['id']));
        };

        Monitor::query()
            ->whereNotNull('social_platform')
            ->select(['id', 'name', 'social_platform', 'config_version', 'tags', 'label'])
            ->with(['latestCheckRun', 'latestPublicEngagementCheckRun'])
            ->chunkById(250, function (EloquentCollection $monitorBatch) use (&$summaries, &$topPosts, $supportedPlatforms, $platformLabels, $selectedPlatform, $selectedTag, $selectedTagValue, $sortTopPosts): void {
                foreach ($monitorBatch as $monitor) {
                    $platform = $monitor->social_platform;

                    if (! in_array($platform, $supportedPlatforms, true)) {
                        continue;
                    }

                    $engagementRun = $monitor->latestPublicEngagementCheckRun;

                    if ($engagementRun?->config_version !== $monitor->config_version) {
                        $engagementRun = null;
                    }

                    $latestCheckRun = $monitor->latestCheckRun;
                    $metrics = $engagementRun?->response_public_engagement_metrics ?? [];
                    $hasEngagementMetrics = $this->hasPublicEngagementMetrics($metrics);
                    $captureIsCurrent = $engagementRun !== null && $latestCheckRun?->id === $engagementRun->id;
                    $tags = $monitor->tagNames();
                    $tagEntries = $tags === [] ? [null] : array_unique($tags);
                    $matchesSelectedPlatform = $selectedPlatform === null || $platform === $selectedPlatform;
                    $matchesSelectedTag = match (true) {
                        $selectedTag === 'unlabeled' => $tags === [],
                        $selectedTagValue !== null => in_array($selectedTagValue, $tags, true),
                        default => true,
                    };
                    $interactionScopePlatform = $selectedPlatform;
                    $interactionTotal = Monitor::publicEngagementInteractionTotal($metrics, $interactionScopePlatform);

                    if ($matchesSelectedPlatform && $matchesSelectedTag && $interactionTotal !== null) {
                        $views = is_numeric($metrics['views'] ?? null) ? (int) $metrics['views'] : null;
                        $finishedAt = $engagementRun?->finished_at?->toImmutable();

                        $topPosts[] = [
                            'id' => (int) $monitor->id,
                            'name' => $monitor->name,
                            'platform' => $platformLabels[$platform],
                            'tags' => $tags,
                            'views' => $views,
                            'likes' => (int) $metrics['likes'],
                            'comments' => (int) $metrics['comments'],
                            'shares' => is_numeric($metrics['shares'] ?? null) ? (int) $metrics['shares'] : null,
                            'interaction_total' => $interactionTotal,
                            'interaction_metric_keys' => Monitor::publicEngagementInteractionMetricKeys($interactionScopePlatform),
                            'interaction_scope_label' => $interactionScopePlatform === 'dcard' ? '讚＋留言' : '讚＋留言＋分享',
                            'metrics_freshness' => $captureIsCurrent ? '最新檢查讀取' : '沿用前次數據',
                            'engagement_rate_percent' => $views !== null && $views > 0
                                ? round($interactionTotal / $views * 100, 2)
                                : null,
                            'finished_at' => $finishedAt,
                        ];

                        usort($topPosts, $sortTopPosts);

                        if (count($topPosts) > self::MAX_DASHBOARD_SOCIAL_TOP_POSTS) {
                            array_pop($topPosts);
                        }
                    }

                    foreach ($tagEntries as $tag) {
                        $tagKey = $tag ?? "\0untagged";

                        if (! isset($summaries[$tagKey])) {
                            $summaries[$tagKey] = [
                                'tag' => $tag ?? '未標籤',
                                'filter_label' => $tag,
                                'platforms' => [],
                            ];
                        }

                        if (! isset($summaries[$tagKey]['platforms'][$platform])) {
                            $summaries[$tagKey]['platforms'][$platform] = [
                                'monitor_count' => 0,
                                'metrics' => array_fill_keys(['views', 'likes', 'comments', 'shares'], ['total' => 0, 'available' => 0]),
                                'interaction_total' => 0,
                                'interaction_count' => 0,
                                'average_interactions_per_post' => null,
                                'engagement_rate' => ['interactions' => 0, 'views' => 0, 'available' => 0],
                                'interaction_scope_label' => $platform === 'dcard' ? '讚＋留言' : '讚＋留言＋分享',
                                'capture_counts' => ['current' => 0, 'previous' => 0],
                            ];
                        }

                        $platformSummary = $summaries[$tagKey]['platforms'][$platform];
                        $platformSummary['monitor_count']++;

                        if ($hasEngagementMetrics) {
                            $captureKey = $captureIsCurrent ? 'current' : 'previous';
                            $platformSummary['capture_counts'][$captureKey]++;
                        }

                        foreach (array_keys($platformSummary['metrics']) as $metric) {
                            if (is_numeric($metrics[$metric] ?? null)) {
                                $platformSummary['metrics'][$metric]['total'] += (int) $metrics[$metric];
                                $platformSummary['metrics'][$metric]['available']++;
                            }
                        }

                        $interactionTotal = Monitor::publicEngagementInteractionTotal($metrics, $platform);

                        if ($interactionTotal !== null) {
                            $platformSummary['interaction_total'] += $interactionTotal;
                            $platformSummary['interaction_count']++;
                            $platformSummary['average_interactions_per_post'] = round(
                                $platformSummary['interaction_total'] / $platformSummary['interaction_count'],
                                1,
                            );

                            if (is_numeric($metrics['views'] ?? null) && (int) $metrics['views'] > 0) {
                                $platformSummary['engagement_rate']['interactions'] += $interactionTotal;
                                $platformSummary['engagement_rate']['views'] += (int) $metrics['views'];
                                $platformSummary['engagement_rate']['available']++;
                            }
                        }

                        $summaries[$tagKey]['platforms'][$platform] = $platformSummary;
                    }
                }
            });

        $results = array_values($summaries);
        usort($results, static fn (array $first, array $second): int => strnatcasecmp($first['tag'], $second['tag']));

        return ['summaries' => $results, 'top_posts' => $topPosts];
    }

    /** @return array<int, string> */
    private function socialEngagementChangePeriodOptions(int $retentionDays): array
    {
        $standardPeriods = [7 => '近 7 天', 30 => '近 30 天', 90 => '近 90 天'];
        $availablePeriods = array_filter(
            $standardPeriods,
            static fn (string $label, int $days): bool => $days <= $retentionDays,
            ARRAY_FILTER_USE_BOTH,
        );

        return $availablePeriods !== []
            ? $availablePeriods
            : [$retentionDays => '近 '.$retentionDays.' 天'];
    }

    /**
     * @param  list<array{tag: string, filter_label: string|null}>  $tagSummaries
     * @return array{
     *     summaries: list<array{
     *         tag: string,
     *         filter_label: string|null,
     *         metrics: array<string, array{
     *             change: int,
     *             available: int,
     *             comparison: array{current_change: int, previous_change: int, difference: int, available: int}
     *         }>
     *     }>,
     *     trend: list<array{date: string, label: string, change: int|null, posts: int, x: float, y: float, width: float, height: float, axis_label: bool}>,
     *     trend_scale: int,
     *     trend_available: bool,
     *     growth_posts: list<array{
     *         id: int,
     *         name: string,
     *         platform: string,
     *         interaction_change: int,
     *         starting_interactions: int,
     *         interaction_growth_rate: float|null,
     *         comparable_current_interaction_change: int|null,
     *         previous_period_interaction_change: int|null,
     *         period_interaction_change_difference: int|null,
     *         period_comparable_metrics: int,
     *         metric_changes: array{likes: int|null, comments: int|null, shares: int|null},
     *         available_metrics: int,
     *         finished_at: CarbonImmutable
     *     }>
     * }
     */
    private function socialEngagementChangeSummaries(CarbonImmutable $asOf, array $tagSummaries, int $periodDays, bool $includePreviousPeriod, ?string $platform, string $growthSort): array
    {
        $changesByTag = [];

        foreach ($tagSummaries as $tagSummary) {
            $tagKey = $tagSummary['filter_label'] ?? "\0untagged";
            $changesByTag[$tagKey] = [
                'tag' => $tagSummary['tag'],
                'filter_label' => $tagSummary['filter_label'],
                'metrics' => array_fill_keys(['views', 'likes', 'comments', 'shares'], [
                    'change' => 0,
                    'available' => 0,
                    'comparison' => ['current_change' => 0, 'previous_change' => 0, 'difference' => 0, 'available' => 0],
                ]),
            ];
        }

        /**
         * @var array<int, array<string, array<string, array<string, array{
         *     first: array{id: int, finished_at: CarbonImmutable, value: int},
         *     latest: array{id: int, finished_at: CarbonImmutable, value: int}
         * }>>>> $observationsByMonitor
         */
        $observationsByMonitor = [];
        /** @var array<int, array{name: string, social_platform: string}> $monitorMetadata */
        $monitorMetadata = [];
        /** @var array<int, array<string, array<string, array{id: int, finished_at: CarbonImmutable, value: int}>>> $trendObservationsByMonitor */
        $trendObservationsByMonitor = [];
        $currentPeriodStartsAt = $asOf->subDays($periodDays);
        $observationsStartAt = $includePreviousPeriod
            ? $currentPeriodStartsAt->subDays($periodDays)
            : $currentPeriodStartsAt;

        CheckRun::query()
            ->select(['id', 'monitor_id', 'config_version', 'finished_at', 'response_public_engagement_metrics'])
            ->where('status', 'completed')
            ->whereNotNull('finished_at')
            ->whereBetween('finished_at', [$observationsStartAt, $asOf])
            ->whereNotNull('response_public_engagement_metrics')
            ->whereNotIn('source', ['tls_daily', 'domain_daily'])
            ->whereHas('monitor', static function (Builder $query) use ($platform): void {
                $query->whereNotNull('social_platform')
                    ->whereColumn('check_runs.config_version', 'monitors.config_version');

                if ($platform !== null) {
                    $query->where('social_platform', $platform);
                }
            })
            ->with('monitor:id,name,social_platform,config_version,tags,label')
            ->lazyById(500)
            ->each(function (CheckRun $run) use (&$observationsByMonitor, &$monitorMetadata, &$trendObservationsByMonitor, $changesByTag, $currentPeriodStartsAt, $includePreviousPeriod): void {
                $monitor = $run->monitor;
                $monitorMetadata[$monitor->id] = [
                    'name' => $monitor->name,
                    'social_platform' => $monitor->social_platform,
                ];
                $metrics = $run->response_public_engagement_metrics ?? [];
                $tags = $monitor->tagNames();
                $tagEntries = $tags === [] ? [null] : array_unique($tags);
                $period = $includePreviousPeriod && $run->finished_at->lessThan($currentPeriodStartsAt)
                    ? 'previous'
                    : 'current';
                $matchesSelectedTag = false;

                foreach ($tagEntries as $tag) {
                    if (isset($changesByTag[$tag ?? "\0untagged"])) {
                        $matchesSelectedTag = true;

                        break;
                    }
                }

                if ($run->finished_at->greaterThanOrEqualTo($currentPeriodStartsAt) && $matchesSelectedTag) {
                    $date = $run->finished_at->timezone(self::HISTORY_TIMEZONE)->toDateString();

                    foreach (['likes', 'comments', 'shares'] as $metric) {
                        if (! is_numeric($metrics[$metric] ?? null)) {
                            continue;
                        }

                        $sample = [
                            'id' => (int) $run->id,
                            'finished_at' => $run->finished_at,
                            'value' => (int) $metrics[$metric],
                        ];
                        $dailyObservation = $trendObservationsByMonitor[$monitor->id][$date][$metric] ?? null;

                        if ($dailyObservation === null
                            || $sample['finished_at']->greaterThan($dailyObservation['finished_at'])
                            || ($sample['finished_at']->equalTo($dailyObservation['finished_at']) && $sample['id'] > $dailyObservation['id'])) {
                            $trendObservationsByMonitor[$monitor->id][$date][$metric] = $sample;
                        }
                    }
                }

                foreach ($tagEntries as $tag) {
                    $tagKey = $tag ?? "\0untagged";

                    foreach (['views', 'likes', 'comments', 'shares'] as $metric) {
                        if (! is_numeric($metrics[$metric] ?? null)) {
                            continue;
                        }

                        $sample = [
                            'id' => (int) $run->id,
                            'finished_at' => $run->finished_at,
                            'value' => (int) $metrics[$metric],
                        ];
                        $observation = $observationsByMonitor[$monitor->id][$tagKey][$metric][$period] ?? null;

                        if ($observation === null) {
                            $observationsByMonitor[$monitor->id][$tagKey][$metric][$period] = [
                                'first' => $sample,
                                'latest' => $sample,
                            ];

                            continue;
                        }

                        $firstSample = $observation['first'];
                        $latestSample = $observation['latest'];

                        if ($sample['finished_at']->lessThan($firstSample['finished_at'])
                            || ($sample['finished_at']->equalTo($firstSample['finished_at']) && $sample['id'] < $firstSample['id'])) {
                            $observationsByMonitor[$monitor->id][$tagKey][$metric][$period]['first'] = $sample;
                        }

                        if ($sample['finished_at']->greaterThan($latestSample['finished_at'])
                            || ($sample['finished_at']->equalTo($latestSample['finished_at']) && $sample['id'] > $latestSample['id'])) {
                            $observationsByMonitor[$monitor->id][$tagKey][$metric][$period]['latest'] = $sample;
                        }
                    }
                }
            });

        foreach ($observationsByMonitor as $monitorObservations) {
            foreach ($monitorObservations as $tagKey => $metricObservations) {
                if (! isset($changesByTag[$tagKey])) {
                    continue;
                }

                foreach ($metricObservations as $metric => $periodObservations) {
                    $currentObservation = $periodObservations['current'] ?? null;
                    $previousObservation = $periodObservations['previous'] ?? null;
                    $currentChange = $currentObservation !== null && $currentObservation['first']['id'] !== $currentObservation['latest']['id']
                        ? $currentObservation['latest']['value'] - $currentObservation['first']['value']
                        : null;
                    $previousChange = $previousObservation !== null && $previousObservation['first']['id'] !== $previousObservation['latest']['id']
                        ? $previousObservation['latest']['value'] - $previousObservation['first']['value']
                        : null;

                    if ($currentChange !== null) {
                        $changesByTag[$tagKey]['metrics'][$metric]['change'] += $currentChange;
                        $changesByTag[$tagKey]['metrics'][$metric]['available']++;
                    }

                    if ($currentChange === null || $previousChange === null) {
                        continue;
                    }

                    $changesByTag[$tagKey]['metrics'][$metric]['comparison']['current_change'] += $currentChange;
                    $changesByTag[$tagKey]['metrics'][$metric]['comparison']['previous_change'] += $previousChange;
                    $changesByTag[$tagKey]['metrics'][$metric]['comparison']['difference'] += $currentChange - $previousChange;
                    $changesByTag[$tagKey]['metrics'][$metric]['comparison']['available']++;
                }
            }
        }

        $growthPosts = [];

        foreach ($observationsByMonitor as $monitorId => $monitorObservations) {
            $selectedMetricObservations = null;

            foreach ($monitorObservations as $tagKey => $metricObservations) {
                if (isset($changesByTag[$tagKey])) {
                    $selectedMetricObservations = $metricObservations;

                    break;
                }
            }

            if ($selectedMetricObservations === null) {
                continue;
            }

            $metricChanges = ['likes' => null, 'comments' => null, 'shares' => null];
            $interactionChange = 0;
            $startingInteractions = 0;
            $availableMetrics = 0;
            $comparableCurrentInteractionChange = 0;
            $previousPeriodInteractionChange = 0;
            $periodComparableMetrics = 0;
            $latestObservationAt = null;

            foreach (array_keys($metricChanges) as $metric) {
                $currentObservation = $selectedMetricObservations[$metric]['current'] ?? null;
                $previousObservation = $selectedMetricObservations[$metric]['previous'] ?? null;
                $previousMetricChange = $previousObservation !== null
                    && $previousObservation['first']['id'] !== $previousObservation['latest']['id']
                        ? $previousObservation['latest']['value'] - $previousObservation['first']['value']
                        : null;

                if ($currentObservation === null || $currentObservation['first']['id'] === $currentObservation['latest']['id']) {
                    continue;
                }

                $metricChange = $currentObservation['latest']['value'] - $currentObservation['first']['value'];
                $metricChanges[$metric] = $metricChange;
                $interactionChange += $metricChange;
                $startingInteractions += $currentObservation['first']['value'];
                $availableMetrics++;

                if ($previousMetricChange !== null) {
                    $comparableCurrentInteractionChange += $metricChange;
                    $previousPeriodInteractionChange += $previousMetricChange;
                    $periodComparableMetrics++;
                }

                if ($latestObservationAt === null || $currentObservation['latest']['finished_at']->greaterThan($latestObservationAt)) {
                    $latestObservationAt = $currentObservation['latest']['finished_at'];
                }
            }

            if ($availableMetrics === 0 || $latestObservationAt === null) {
                continue;
            }

            $growthPosts[] = [
                'id' => (int) $monitorId,
                'name' => $monitorMetadata[$monitorId]['name'],
                'platform' => match ($monitorMetadata[$monitorId]['social_platform']) {
                    'facebook' => 'Facebook',
                    'instagram' => 'Instagram',
                    'threads' => 'Threads',
                    'dcard' => 'Dcard',
                    default => $monitorMetadata[$monitorId]['social_platform'],
                },
                'interaction_change' => $interactionChange,
                'starting_interactions' => $startingInteractions,
                'interaction_growth_rate' => $startingInteractions > 0
                    ? round($interactionChange / $startingInteractions * 100, 1)
                    : null,
                'comparable_current_interaction_change' => $periodComparableMetrics > 0 ? $comparableCurrentInteractionChange : null,
                'previous_period_interaction_change' => $periodComparableMetrics > 0 ? $previousPeriodInteractionChange : null,
                'period_interaction_change_difference' => $periodComparableMetrics > 0
                    ? $comparableCurrentInteractionChange - $previousPeriodInteractionChange
                    : null,
                'period_comparable_metrics' => $periodComparableMetrics,
                'metric_changes' => $metricChanges,
                'available_metrics' => $availableMetrics,
                'finished_at' => $latestObservationAt,
            ];
        }

        usort($growthPosts, static function (array $first, array $second) use ($growthSort): int {
            if ($growthSort === 'growth_rate') {
                $firstGrowthRate = $first['starting_interactions'] > 0
                    ? $first['interaction_change'] / $first['starting_interactions']
                    : null;
                $secondGrowthRate = $second['starting_interactions'] > 0
                    ? $second['interaction_change'] / $second['starting_interactions']
                    : null;

                if ($firstGrowthRate === null && $secondGrowthRate !== null) {
                    return 1;
                }

                if ($secondGrowthRate === null && $firstGrowthRate !== null) {
                    return -1;
                }

                if ($firstGrowthRate !== null && $secondGrowthRate !== null) {
                    $growthRateComparison = $secondGrowthRate <=> $firstGrowthRate;

                    if ($growthRateComparison !== 0) {
                        return $growthRateComparison;
                    }
                }
            }

            $changeComparison = $second['interaction_change'] <=> $first['interaction_change'];

            if ($changeComparison !== 0) {
                return $changeComparison;
            }

            $timestampComparison = $second['finished_at']->getTimestamp() <=> $first['finished_at']->getTimestamp();

            return $timestampComparison !== 0
                ? $timestampComparison
                : (strnatcasecmp($first['name'], $second['name']) ?: ($first['id'] <=> $second['id']));
        });

        $results = array_values($changesByTag);
        usort($results, static fn (array $first, array $second): int => strnatcasecmp($first['tag'], $second['tag']));

        $trendChangesByDate = [];

        foreach ($trendObservationsByMonitor as $dailyObservations) {
            ksort($dailyObservations);
            $previousMetricValues = array_fill_keys(['likes', 'comments', 'shares'], null);

            foreach ($dailyObservations as $date => $metricObservations) {
                $dailyChange = 0;
                $hasMetricComparison = false;

                foreach (['likes', 'comments', 'shares'] as $metric) {
                    $observation = $metricObservations[$metric] ?? null;

                    if ($observation === null) {
                        continue;
                    }

                    if ($previousMetricValues[$metric] !== null) {
                        $dailyChange += $observation['value'] - $previousMetricValues[$metric];
                        $hasMetricComparison = true;
                    }

                    $previousMetricValues[$metric] = $observation['value'];
                }

                if ($hasMetricComparison) {
                    $trendChangesByDate[$date]['change'] = ($trendChangesByDate[$date]['change'] ?? 0) + $dailyChange;
                    $trendChangesByDate[$date]['posts'] = ($trendChangesByDate[$date]['posts'] ?? 0) + 1;
                }
            }
        }

        $firstTrendDay = $currentPeriodStartsAt->timezone(self::HISTORY_TIMEZONE)->startOfDay();
        $lastTrendDay = $asOf->timezone(self::HISTORY_TIMEZONE)->startOfDay();
        $trendDates = [];

        for ($day = $firstTrendDay; $day->lessThanOrEqualTo($lastTrendDay); $day = $day->addDay()) {
            $date = $day->toDateString();
            $trendDates[] = [
                'date' => $date,
                'label' => $day->format('n/j'),
                'change' => $trendChangesByDate[$date]['change'] ?? null,
                'posts' => $trendChangesByDate[$date]['posts'] ?? 0,
            ];
        }

        $observedChanges = array_values(array_filter(
            array_column($trendDates, 'change'),
            static fn (?int $change): bool => $change !== null,
        ));
        $trendScale = max([1, ...array_map(static fn (int $change): int => abs($change), $observedChanges)]);
        $trendDateCount = count($trendDates);
        $trendPlotStartX = 54.0;
        $trendPlotWidth = 516.0;
        $trendBaselineY = 95.0;
        $trendPlotHeight = 64.0;

        foreach ($trendDates as $index => &$trendDate) {
            $slotWidth = $trendDateCount > 0 ? $trendPlotWidth / $trendDateCount : $trendPlotWidth;
            $change = $trendDate['change'];
            $height = $change === null ? 0.0 : max(1.0, abs($change) / $trendScale * $trendPlotHeight);
            $trendDate['x'] = round($trendPlotStartX + ($slotWidth * $index) + ($slotWidth / 2), 2);
            $trendDate['y'] = round($change !== null && $change < 0 ? $trendBaselineY : $trendBaselineY - $height, 2);
            $trendDate['width'] = round(max(2.0, min(14.0, $slotWidth * 0.68)), 2);
            $trendDate['height'] = round($height, 2);
            $trendDate['axis_label'] = in_array($index, [0, intdiv(max(0, $trendDateCount - 1), 2), max(0, $trendDateCount - 1)], true);
        }
        unset($trendDate);

        return [
            'summaries' => $results,
            'trend' => $trendDates,
            'trend_scale' => $trendScale,
            'trend_available' => $observedChanges !== [],
            'growth_posts' => array_slice($growthPosts, 0, self::MAX_DASHBOARD_SOCIAL_TOP_POSTS),
        ];
    }

    /**
     * @return array{
     *     due_queued_count: int,
     *     oldest_due_wait_seconds: int|null,
     *     queued_warning: bool,
     *     running_count: int,
     *     longest_running_seconds: int|null,
     *     running_warning: bool
     * }
     */
    private function checkRunQueueHealth(CarbonImmutable $asOf): array
    {
        $queuedSummary = CheckRun::query()
            ->where('status', 'queued')
            ->where('scheduled_at', '<=', $asOf)
            ->selectRaw('COUNT(*) AS run_count, MIN(scheduled_at) AS oldest_at')
            ->first();
        $runningSummary = CheckRun::query()
            ->where('status', 'running')
            ->whereNotNull('started_at')
            ->selectRaw('COUNT(*) AS run_count, MIN(started_at) AS oldest_at')
            ->first();
        $oldestDueAt = $queuedSummary?->oldest_at === null
            ? null
            : CarbonImmutable::parse((string) $queuedSummary->oldest_at, (string) config('app.timezone', 'UTC'));
        $oldestRunningAt = $runningSummary?->oldest_at === null
            ? null
            : CarbonImmutable::parse((string) $runningSummary->oldest_at, (string) config('app.timezone', 'UTC'));
        $oldestDueWaitSeconds = $oldestDueAt === null
            ? null
            : max(0, $asOf->getTimestamp() - $oldestDueAt->getTimestamp());
        $longestRunningSeconds = $oldestRunningAt === null
            ? null
            : max(0, $asOf->getTimestamp() - $oldestRunningAt->getTimestamp());

        return [
            'due_queued_count' => (int) ($queuedSummary?->run_count ?? 0),
            'oldest_due_wait_seconds' => $oldestDueWaitSeconds,
            'queued_warning' => $oldestDueWaitSeconds !== null && $oldestDueWaitSeconds >= self::QUEUED_CHECK_WARNING_AFTER_SECONDS,
            'running_count' => (int) ($runningSummary?->run_count ?? 0),
            'longest_running_seconds' => $longestRunningSeconds,
            'running_warning' => $longestRunningSeconds !== null && $longestRunningSeconds >= self::RUNNING_CHECK_WARNING_AFTER_SECONDS,
        ];
    }

    /**
     * @return array{enabled_priority: int, critical_incident_count: int, warning_incident_count: int, health_state_priority: int}
     */
    private function dashboardMonitorPriority(Monitor $monitor, string $healthState): array
    {
        $currentIncidents = $monitor->activeIncidents->where('config_version', $monitor->config_version);

        return [
            'enabled_priority' => $monitor->enabled ? 1 : 0,
            'critical_incident_count' => $currentIncidents->where('severity', 'critical')->count(),
            'warning_incident_count' => $currentIncidents->where('severity', 'warning')->count(),
            'health_state_priority' => match ($healthState) {
                'issue' => 0,
                'suspect' => 1,
                'warning' => 2,
                'stale' => 3,
                'healthy' => 4,
                'pending' => 5,
                'paused' => 6,
                default => 7,
            },
        ];
    }

    /**
     * @return array{count: int, items: list<array{maintenance_window_id: int, monitor_id: int, monitor_name: string, reason: string, state: 'active'|'upcoming', starts_at: CarbonImmutable, ends_at: CarbonImmutable}>}
     */
    private function maintenanceSummary(CarbonImmutable $asOf): array
    {
        $repeatLookupDate = $asOf->timezone(self::HISTORY_TIMEZONE)->subDays(7)->toDateString();
        $lookaheadEndsAt = $asOf->addDays(self::MAINTENANCE_LOOKAHEAD_DAYS);
        $occurrenceCount = 0;
        $items = [];

        MaintenanceWindow::query()
            ->select(['id', 'monitor_id', 'starts_at', 'ends_at', 'reason', 'repeat_weekly', 'repeat_until'])
            ->with('monitor:id,name')
            ->where('starts_at', '<=', $lookaheadEndsAt->utc())
            ->where(function (Builder $query) use ($asOf, $repeatLookupDate): void {
                $query->where(function (Builder $query) use ($asOf): void {
                    $query->where(function (Builder $query): void {
                        $query->where('repeat_weekly', false)->orWhereNull('repeat_until');
                    })->where('ends_at', '>', $asOf->utc());
                })->orWhere(function (Builder $query) use ($repeatLookupDate): void {
                    $query->where('repeat_weekly', true)
                        ->whereNotNull('repeat_until')
                        ->whereDate('repeat_until', '>=', $repeatLookupDate);
                });
            })
            ->chunkById(500, function (EloquentCollection $windows) use (&$items, &$occurrenceCount, $asOf, $lookaheadEndsAt): void {
                foreach ($windows as $window) {
                    foreach ($window->occurrenceIntervals() as $occurrence) {
                        if ($occurrence['ends_at']->lessThanOrEqualTo($asOf)
                            || $occurrence['starts_at']->greaterThan($lookaheadEndsAt)) {
                            continue;
                        }

                        $occurrenceCount++;
                        $items[] = [
                            'maintenance_window_id' => (int) $window->id,
                            'monitor_id' => (int) $window->monitor_id,
                            'monitor_name' => $window->monitor?->name ?? '已刪除的監測項目',
                            'reason' => $window->reason,
                            'state' => $occurrence['starts_at']->lessThanOrEqualTo($asOf) ? 'active' : 'upcoming',
                            'starts_at' => $occurrence['starts_at'],
                            'ends_at' => $occurrence['ends_at'],
                        ];

                        usort($items, static fn (array $first, array $second): int => ($first['starts_at']->getTimestamp() <=> $second['starts_at']->getTimestamp())
                            ?: ($first['monitor_id'] <=> $second['monitor_id'])
                            ?: ($first['maintenance_window_id'] <=> $second['maintenance_window_id'])
                        );

                        if (count($items) > self::MAX_DASHBOARD_MAINTENANCE_OCCURRENCES) {
                            array_pop($items);
                        }
                    }
                }
            });

        return ['count' => $occurrenceCount, 'items' => $items];
    }

    /** @return array{monitor: Monitor, expires_at: CarbonImmutable, observed_at: \DateTimeInterface|null, is_stale: bool, is_expired: bool, days_until_expiry: int|null} */
    private function expirySummary(Monitor $monitor, TlsObservation|DomainRegistrationObservation $observation, CarbonImmutable $asOf): array
    {
        $expiresAt = $observation->expires_at;
        $secondsUntilExpiry = $expiresAt->getTimestamp() - $asOf->getTimestamp();
        $observedAt = $observation->updated_at ?? $observation->created_at;
        $isStale = $observedAt === null
            || $observedAt->lessThan($asOf->subHours(self::TLS_OBSERVATION_STALE_AFTER_HOURS));

        return [
            'monitor' => $monitor,
            'expires_at' => $expiresAt,
            'observed_at' => $observedAt,
            'is_stale' => $isStale,
            'is_expired' => ! $isStale && $secondsUntilExpiry <= 0,
            'days_until_expiry' => $isStale || $secondsUntilExpiry <= 0
                ? null
                : (int) ceil($secondsUntilExpiry / 86_400),
        ];
    }

    private function socialEngagementCaptureStatus(?CheckRun $latestCheckRun, ?CheckRun $engagementRun): string
    {
        if ($latestCheckRun === null) {
            return '等待首次檢查';
        }

        if ($latestCheckRun->error_type === 'social_access_required') {
            return '平台限制存取'.($latestCheckRun->normal_status === null ? '' : ' · HTTP '.$latestCheckRun->normal_status);
        }

        if ($latestCheckRun->error_type === 'body_too_large') {
            return '回應過大，無法完整讀取';
        }

        if ($latestCheckRun->error_type !== null) {
            return '檢查失敗 · '.$latestCheckRun->error_type;
        }

        if ($latestCheckRun->normal_status === null) {
            return '尚無 HTTP 回應';
        }

        if ($latestCheckRun->normal_status < 200 || $latestCheckRun->normal_status >= 300) {
            return 'HTTP '.$latestCheckRun->normal_status.' · 未擷取互動數';
        }

        if ($engagementRun === null) {
            return 'HTTP '.$latestCheckRun->normal_status.' · 未辨識互動數';
        }

        if ($engagementRun->id !== $latestCheckRun->id) {
            return '本次未讀到互動數，顯示前次資料';
        }

        return '已擷取';
    }

    /** @param array<string, mixed> $metrics */
    private function hasPublicEngagementMetrics(array $metrics): bool
    {
        foreach (['views', 'likes', 'comments', 'shares'] as $metric) {
            if (is_numeric($metrics[$metric] ?? null)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<array{monitor: Monitor, expires_at: CarbonImmutable}> $summaries */
    private function sortExpirySummaries(array &$summaries): void
    {
        usort($summaries, static function (array $first, array $second): int {
            return ($first['expires_at']->getTimestamp() <=> $second['expires_at']->getTimestamp())
                ?: strcasecmp($first['monitor']->name, $second['monitor']->name)
                ?: ($first['monitor']->id <=> $second['monitor']->id);
        });

        if (count($summaries) > 10) {
            array_pop($summaries);
        }
    }
}
