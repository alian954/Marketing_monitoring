<?php

namespace App\Http\Controllers;

use App\Models\DnsChangeEvent;
use App\Models\DomainRegistrationObservation;
use App\Models\Incident;
use App\Models\MaintenanceWindow;
use App\Models\Monitor;
use App\Models\SchedulerHeartbeat;
use App\Models\TlsObservation;
use App\Services\FleetAvailabilityReport;
use App\Services\UrlMasker;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\View\View;
use Throwable;

class DashboardController extends Controller
{
    private const MAINTENANCE_LOOKAHEAD_DAYS = 30;

    private const MAX_DASHBOARD_MAINTENANCE_OCCURRENCES = 10;

    private const MAX_DASHBOARD_MONITORS = 8;

    private const HISTORY_TIMEZONE = 'Asia/Taipei';

    private const TLS_OBSERVATION_STALE_AFTER_HOURS = 48;

    public function index(UrlMasker $urlMasker, FleetAvailabilityReport $availabilityReport): View
    {
        $asOf = now()->toImmutable();
        $fleetAvailability = $availabilityReport->build($asOf);
        $maintenanceSummary = $this->maintenanceSummary($asOf);
        $monitorCount = 0;
        $dashboardMonitorCandidates = [];
        $counts = ['healthy' => 0, 'issue' => 0, 'warning' => 0, 'stale' => 0, 'paused' => 0];
        $platformCounts = [];

        foreach (['facebook' => 'Facebook', 'instagram' => 'Instagram', 'threads' => 'Threads'] as $platform => $label) {
            $platformCounts[$platform] = [
                'label' => $label,
                'total' => 0,
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
            'queueSize' => $queueSize,
            'queueStateCounts' => $queueStateCounts,
            'failedQueueJobStorageSupported' => $failedQueueJobStorageSupported,
            'failedQueueJobCount' => $failedQueueJobCount,
            'counts' => $counts,
            'platformCounts' => $platformCounts,
            'fleetAvailability' => $fleetAvailability,
            'maintenanceSummary' => $maintenanceSummary,
        ]);
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
