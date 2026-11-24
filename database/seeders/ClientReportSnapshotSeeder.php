<?php

namespace Database\Seeders;

use App\Models\ClientReportShare;
use App\Services\SharedClientReportCsv;
use App\Services\SharedClientReportDataBuilder;
use Illuminate\Database\Seeder;

class ClientReportSnapshotSeeder extends Seeder
{
    public function run(SharedClientReportDataBuilder $reportDataBuilder, SharedClientReportCsv $csv): void
    {
        ClientReportShare::query()
            ->withCount('snapshots')
            ->orderBy('id')
            ->each(function (ClientReportShare $share) use ($reportDataBuilder, $csv): void {
                if ($share->snapshots_count > 0) {
                    return;
                }

                $reportData = $reportDataBuilder->buildForShare($share);
                $csvContent = $csv->build(
                    $reportData['client'],
                    $reportData['campaignRows'],
                    $reportData['reportingCurrencyGroups'],
                    $reportData['reportSections'],
                    $reportData['reportPeriod'] === null
                        ? null
                        : $reportData['reportPeriod']['from'].' 至 '.$reportData['reportPeriod']['to'],
                    $reportData['comparisonPeriod']['label'] ?? null,
                );

                $share->snapshots()->create([
                    'created_by_user_id' => null,
                    'report_sections' => $reportData['reportSections'],
                    'report_starts_on' => $reportData['reportPeriod']['from'] ?? null,
                    'report_ends_on' => $reportData['reportPeriod']['to'] ?? null,
                    'csv_content' => $csvContent,
                    'csv_sha256' => hash('sha256', $csvContent),
                ]);
            });
    }
}
