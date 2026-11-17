<?php

namespace App\Http\Controllers;

use App\Models\Incident;
use App\Models\Monitor;
use App\Models\SchedulerHeartbeat;
use App\Services\UrlMasker;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(UrlMasker $urlMasker): View
    {
        $monitors = Monitor::query()
            ->with(['latestCheckRun', 'activeIncidents'])
            ->orderBy('name')
            ->get();

        foreach ($monitors as $monitor) {
            $monitor->setAttribute('health_state', $monitor->healthState());
            $monitor->setAttribute('masked_url', $urlMasker->mask($monitor->url));
        }

        $incidents = Incident::query()
            ->with('monitor:id,name')
            ->whereIn('state', [Incident::STATE_SUSPECT, Incident::STATE_OPEN])
            ->latest('last_seen_at')
            ->limit(20)
            ->get();
        $heartbeat = SchedulerHeartbeat::query()->where('job_key', 'monitor-scheduler')->first();
        $heartbeatFresh = $heartbeat?->last_succeeded_at !== null && $heartbeat->last_succeeded_at->gte(now()->subMinutes(2));

        return view('dashboard', [
            'monitors' => $monitors,
            'incidents' => $incidents,
            'heartbeat' => $heartbeat,
            'heartbeatFresh' => $heartbeatFresh,
            'counts' => [
                'healthy' => $monitors->where('health_state', 'healthy')->count(),
                'issue' => $monitors->whereIn('health_state', ['issue', 'suspect'])->count(),
                'stale' => $monitors->where('health_state', 'stale')->count(),
                'paused' => $monitors->where('health_state', 'paused')->count(),
            ],
        ]);
    }
}
