<?php

namespace App\Services;

use App\Models\ClientReportShare;
use App\Models\ClientReportSnapshot;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ClientReportSnapshotRecorder
{
    public function __construct(
        private SharedClientReportDataBuilder $reportDataBuilder,
        private SharedClientReportCsv $csv,
    ) {}

    /**
     * Freeze the share's current report data as an immutable CSV snapshot.
     */
    public function record(ClientReportShare $share, ?User $createdBy): ClientReportSnapshot
    {
        $reportData = $this->reportDataBuilder->buildForShare($share);
        $csvContent = $this->csv->build(
            $reportData['client'],
            $reportData['campaignRows'],
            $reportData['reportingCurrencyGroups'],
            $reportData['reportSections'],
            $reportData['reportPeriod'] === null
                ? null
                : $reportData['reportPeriod']['from'].' 至 '.$reportData['reportPeriod']['to'],
            $reportData['comparisonPeriod']['label'] ?? null,
        );

        return DB::transaction(fn (): ClientReportSnapshot => $share->snapshots()->create([
            'created_by_user_id' => $createdBy?->getKey(),
            'report_sections' => $reportData['reportSections'],
            'report_starts_on' => $reportData['reportPeriod']['from'] ?? null,
            'report_ends_on' => $reportData['reportPeriod']['to'] ?? null,
            'csv_content' => $csvContent,
            'csv_sha256' => hash('sha256', $csvContent),
        ]));
    }
}
