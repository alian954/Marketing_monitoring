<?php

namespace App\Services;

use App\Models\CheckRun;
use App\Models\Monitor;
use Carbon\CarbonImmutable;

class ResponseMetadataChangeHistory
{
    private const MAX_CHANGES = 10;

    /**
     * @return list<array{
     *     changed_at: CarbonImmutable,
     *     changed_fields: list<'title'|'description'>,
     *     previous_title: ?string,
     *     current_title: ?string,
     *     previous_description: ?string,
     *     current_description: ?string
     * }>
     */
    public function build(Monitor $monitor): array
    {
        $changes = [];
        $newerRun = null;

        foreach ($monitor->checkRuns()
            ->where('status', 'completed')
            ->whereNotIn('source', ['tls_daily', 'domain_daily'])
            ->orderByDesc('scheduled_at')
            ->orderByDesc('id')
            ->select(['id', 'config_version', 'scheduled_at', 'response_title', 'response_description'])
            ->lazy(500) as $run) {
            if ($newerRun instanceof CheckRun && $newerRun->config_version === $run->config_version) {
                $changedFields = [];

                if ($newerRun->response_title !== $run->response_title) {
                    $changedFields[] = 'title';
                }

                if ($newerRun->response_description !== $run->response_description) {
                    $changedFields[] = 'description';
                }

                if ($changedFields !== []) {
                    $changes[] = [
                        'changed_at' => $newerRun->scheduled_at,
                        'changed_fields' => $changedFields,
                        'previous_title' => $run->response_title,
                        'current_title' => $newerRun->response_title,
                        'previous_description' => $run->response_description,
                        'current_description' => $newerRun->response_description,
                    ];

                    if (count($changes) >= self::MAX_CHANGES) {
                        break;
                    }
                }
            }

            $newerRun = $run;
        }

        return $changes;
    }
}
