<?php

namespace App\Http\Controllers;

use App\Models\Monitor;
use Illuminate\View\View;

class RobotsReviewController extends Controller
{
    public function show(Monitor $monitor): View
    {
        $urlParts = parse_url($monitor->url);
        $scheme = is_array($urlParts) ? mb_strtolower((string) ($urlParts['scheme'] ?? '')) : '';
        $host = is_array($urlParts) ? trim((string) ($urlParts['host'] ?? ''), '[]') : '';
        $port = is_array($urlParts) && isset($urlParts['port']) ? ':'.(int) $urlParts['port'] : '';
        $origin = in_array($scheme, ['http', 'https'], true) && $host !== ''
            ? $scheme.'://'.(str_contains($host, ':') ? '['.$host.']' : $host).$port
            : null;
        $robotsUrl = $origin === null ? null : $origin.'/robots.txt';
        $isWebsiteMonitor = $monitor->socialPlatform() === null;
        $observations = $isWebsiteMonitor
            ? $monitor->robotsObservations()->orderByDesc('observed_on')->limit(30)->get()
            : collect();
        $latestObservation = $observations->first();

        return view('monitors.robots-review', compact('monitor', 'origin', 'robotsUrl', 'isWebsiteMonitor', 'observations', 'latestObservation'));
    }
}
