<?php

namespace App\Http\Controllers;

use App\Models\DnsChangeEvent;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\SchedulerHeartbeat;
use App\Services\UrlMasker;
use Illuminate\View\View;

class DashboardController extends Controller
{
    private const TLS_OBSERVATION_STALE_AFTER_HOURS = 48;

    public function index(UrlMasker $urlMasker): View
    {
        $monitors = Monitor::query()
            ->with(['latestCheckRun', 'latestTlsObservation', 'activeIncidents.acknowledgedBy:id,name'])
            ->orderBy('name')
            ->get();

        foreach ($monitors as $monitor) {
            $monitor->setAttribute('health_state', $monitor->healthState());
            $monitor->setAttribute('masked_url', $urlMasker->mask($monitor->url));
        }

        $asOf = now()->toImmutable();
        $expiringCertificates = $monitors
            ->filter(function (Monitor $monitor) use ($asOf): bool {
                $expiresAt = $monitor->latestTlsObservation?->expires_at;

                return $expiresAt !== null && $expiresAt->lessThanOrEqualTo($asOf->addDays(30));
            })
            ->sortBy(static fn (Monitor $monitor): int => $monitor->latestTlsObservation->expires_at->getTimestamp())
            ->map(static function (Monitor $monitor) use ($asOf): array {
                $observation = $monitor->latestTlsObservation;
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
            })
            ->values();
        $expiringCertificateCount = $expiringCertificates->count();
        $expiringCertificates = $expiringCertificates->take(10)->values();

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

        return view('dashboard', [
            'monitors' => $monitors,
            'expiringCertificates' => $expiringCertificates,
            'expiringCertificateCount' => $expiringCertificateCount,
            'incidents' => $incidents,
            'dnsChanges' => $dnsChanges,
            'heartbeat' => $heartbeat,
            'heartbeatFresh' => $heartbeatFresh,
            'workerHeartbeat' => $workerHeartbeat,
            'workerHeartbeatFresh' => $workerHeartbeatFresh,
            'queueWorkerEnabled' => config('queue.default') !== 'sync',
            'counts' => [
                'healthy' => $monitors->where('health_state', 'healthy')->count(),
                'issue' => $monitors->whereIn('health_state', ['issue', 'suspect'])->count(),
                'warning' => $monitors->where('health_state', 'warning')->count(),
                'stale' => $monitors->where('health_state', 'stale')->count(),
                'paused' => $monitors->where('health_state', 'paused')->count(),
            ],
        ]);
    }
}
