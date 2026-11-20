<?php

namespace App\Http\Controllers;

use App\Models\DnsChangeEvent;
use App\Models\DomainRegistrationObservation;
use App\Models\Incident;
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
    private const TLS_OBSERVATION_STALE_AFTER_HOURS = 48;

    public function index(UrlMasker $urlMasker, FleetAvailabilityReport $availabilityReport): View
    {
        $monitors = Monitor::query()
            ->with(['latestCheckRun', 'activeIncidents'])
            ->withCount([
                'activeIncidents as current_critical_incidents_count' => static fn (Builder $query): Builder => $query
                    ->whereColumn('incidents.config_version', 'monitors.config_version')
                    ->where('severity', 'critical'),
                'activeIncidents as current_warning_incidents_count' => static fn (Builder $query): Builder => $query
                    ->whereColumn('incidents.config_version', 'monitors.config_version')
                    ->where('severity', 'warning'),
            ])
            ->orderByDesc('enabled')
            ->orderByDesc('current_critical_incidents_count')
            ->orderByDesc('current_warning_incidents_count')
            ->orderBy('name')
            ->orderBy('id')
            ->limit(8)
            ->get();

        foreach ($monitors as $monitor) {
            $monitor->setAttribute('health_state', $monitor->healthState());
            $monitor->setAttribute('masked_url', $urlMasker->mask($monitor->url));
        }

        $asOf = now()->toImmutable();
        $fleetAvailability = $availabilityReport->build($asOf);
        $monitorCount = 0;
        $counts = ['healthy' => 0, 'issue' => 0, 'warning' => 0, 'stale' => 0, 'paused' => 0];
        $expiringCertificateCount = 0;
        $expiringCertificates = [];
        $expiringDomainCount = 0;
        $expiringDomains = [];

        Monitor::query()
            ->with(['latestCheckRun', 'latestTlsObservation', 'latestDomainRegistrationObservation', 'activeIncidents'])
            ->chunkById(500, function (EloquentCollection $monitorBatch) use (
                $asOf,
                &$monitorCount,
                &$counts,
                &$expiringCertificates,
                &$expiringCertificateCount,
                &$expiringDomains,
                &$expiringDomainCount,
            ): void {
                foreach ($monitorBatch as $monitor) {
                    $monitorCount++;
                    $healthState = $monitor->healthState();

                    if (in_array($healthState, ['issue', 'suspect'], true)) {
                        $counts['issue']++;
                    } elseif (array_key_exists($healthState, $counts)) {
                        $counts[$healthState]++;
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
        $failedQueueJobStorageSupported = in_array(config('queue.failed.driver'), ['database', 'database-uuids'], true);
        $failedQueueJobCount = null;

        if ($queueWorkerEnabled) {
            try {
                $queueSize = Queue::size();
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
            'failedQueueJobStorageSupported' => $failedQueueJobStorageSupported,
            'failedQueueJobCount' => $failedQueueJobCount,
            'counts' => $counts,
            'fleetAvailability' => $fleetAvailability,
        ]);
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
