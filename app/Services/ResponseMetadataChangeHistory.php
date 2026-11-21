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
     *     changed_fields: list<'title'|'description'|'image_reference'|'open_graph_type'|'canonical_reference'|'published_at'|'modified_at'>,
     *     previous_title: ?string,
     *     current_title: ?string,
     *     previous_description: ?string,
     *     current_description: ?string,
     *     previous_image_reference_present: bool,
     *     current_image_reference_present: bool,
     *     previous_open_graph_type: ?string,
     *     current_open_graph_type: ?string,
     *     previous_canonical_reference_present: bool,
     *     current_canonical_reference_present: bool,
     *     previous_published_at: ?CarbonImmutable,
     *     current_published_at: ?CarbonImmutable,
     *     previous_modified_at: ?CarbonImmutable,
     *     current_modified_at: ?CarbonImmutable
     * }>
     */
    public function build(Monitor $monitor): array
    {
        $changes = [];
        $newerRun = null;

        foreach ($monitor->checkRuns()
            ->where('status', 'completed')
            ->whereNull('error_type')
            ->whereNotIn('source', ['tls_daily', 'domain_daily'])
            ->orderByDesc('scheduled_at')
            ->orderByDesc('id')
            ->select(['id', 'config_version', 'scheduled_at', 'response_title', 'response_description', 'response_image_reference_hash', 'response_open_graph_type', 'response_canonical_reference_hash', 'response_published_at', 'response_modified_at'])
            ->cursor() as $run) {
            if ($newerRun instanceof CheckRun && $newerRun->config_version === $run->config_version) {
                $changedFields = self::changedFields($run, $newerRun);

                if ($changedFields !== []) {
                    $changes[] = [
                        'changed_at' => $newerRun->scheduled_at,
                        'changed_fields' => $changedFields,
                        'previous_title' => $run->response_title,
                        'current_title' => $newerRun->response_title,
                        'previous_description' => $run->response_description,
                        'current_description' => $newerRun->response_description,
                        'previous_image_reference_present' => $run->response_image_reference_hash !== null,
                        'current_image_reference_present' => $newerRun->response_image_reference_hash !== null,
                        'previous_open_graph_type' => $run->response_open_graph_type,
                        'current_open_graph_type' => $newerRun->response_open_graph_type,
                        'previous_canonical_reference_present' => $run->response_canonical_reference_hash !== null,
                        'current_canonical_reference_present' => $newerRun->response_canonical_reference_hash !== null,
                        'previous_published_at' => $run->response_published_at,
                        'current_published_at' => $newerRun->response_published_at,
                        'previous_modified_at' => $run->response_modified_at,
                        'current_modified_at' => $newerRun->response_modified_at,
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

    /**
     * @return list<'title'|'description'|'image_reference'|'open_graph_type'|'canonical_reference'|'published_at'|'modified_at'>
     */
    public static function changedFields(CheckRun $previousRun, CheckRun $currentRun): array
    {
        $changedFields = [];

        if ($previousRun->response_title !== $currentRun->response_title) {
            $changedFields[] = 'title';
        }

        if ($previousRun->response_description !== $currentRun->response_description) {
            $changedFields[] = 'description';
        }

        if ($previousRun->response_image_reference_hash !== $currentRun->response_image_reference_hash) {
            $changedFields[] = 'image_reference';
        }

        if ($previousRun->response_open_graph_type !== $currentRun->response_open_graph_type) {
            $changedFields[] = 'open_graph_type';
        }

        if ($previousRun->response_canonical_reference_hash !== $currentRun->response_canonical_reference_hash) {
            $changedFields[] = 'canonical_reference';
        }

        if ($previousRun->response_published_at?->utc()->format('U.u') !== $currentRun->response_published_at?->utc()->format('U.u')) {
            $changedFields[] = 'published_at';
        }

        if ($previousRun->response_modified_at?->utc()->format('U.u') !== $currentRun->response_modified_at?->utc()->format('U.u')) {
            $changedFields[] = 'modified_at';
        }

        return $changedFields;
    }
}
