<?php

namespace App\Services;

use App\Models\CheckRun;
use App\Models\Monitor;
use Carbon\CarbonImmutable;

class ResponseContentChangeHistory
{
    private const MAX_CHANGES = 10;

    /** @return list<array{changed_at: CarbonImmutable}> */
    public function build(Monitor $monitor): array
    {
        $changes = [];
        $newerRun = null;

        foreach ($monitor->checkRuns()
            ->where('status', 'completed')
            ->whereNotIn('source', ['tls_daily', 'domain_daily'])
            ->orderByDesc('scheduled_at')
            ->orderByDesc('id')
            ->select(['id', 'config_version', 'scheduled_at', 'response_visible_text_hash'])
            ->cursor() as $run) {
            if ($newerRun instanceof CheckRun
                && $newerRun->config_version === $run->config_version
                && is_string($newerRun->response_visible_text_hash)
                && is_string($run->response_visible_text_hash)
                && $newerRun->response_visible_text_hash !== $run->response_visible_text_hash) {
                $changes[] = ['changed_at' => $newerRun->scheduled_at];

                if (count($changes) >= self::MAX_CHANGES) {
                    break;
                }
            }

            $newerRun = $run;
        }

        return $changes;
    }
}
