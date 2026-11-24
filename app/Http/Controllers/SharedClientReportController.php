<?php

namespace App\Http\Controllers;

use App\Services\SharedClientReportCsv;
use App\Services\SharedClientReportDataBuilder;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SharedClientReportController extends Controller
{
    public function __invoke(string $token, SharedClientReportDataBuilder $reportDataBuilder): Response
    {
        $reportData = $reportDataBuilder->buildForToken($token);
        $reportData['share']->load([
            'snapshots:id,client_report_share_id,created_by_user_id,report_sections,report_starts_on,report_ends_on,csv_sha256,created_at,updated_at',
        ]);

        return response()
            ->view('shared-client-reports.show', $reportData + ['token' => $token])
            ->header('Cache-Control', 'private, no-store')
            ->header('X-Robots-Tag', 'noindex, nofollow')
            ->header('Referrer-Policy', 'no-referrer');
    }

    public function export(
        string $token,
        SharedClientReportDataBuilder $reportDataBuilder,
        SharedClientReportCsv $csv,
    ): StreamedResponse {
        $reportData = $reportDataBuilder->buildForToken($token);
        $response = response()->streamDownload(function () use ($csv, $reportData): void {
            $stream = fopen('php://output', 'wb');
            $csv->write(
                $reportData['client'],
                $reportData['campaignRows'],
                $reportData['reportingCurrencyGroups'],
                $stream,
                $reportData['reportSections'],
                $reportData['reportPeriod'] === null
                    ? null
                    : $reportData['reportPeriod']['from'].' 至 '.$reportData['reportPeriod']['to'],
                $reportData['comparisonPeriod']['label'] ?? null,
            );
            fclose($stream);
        }, $reportData['reportPeriod'] === null
            ? 'marketing-report-'.now()->format('Y-m-d').'.csv'
            : 'marketing-report-'.$reportData['reportPeriod']['from'].'-to-'.$reportData['reportPeriod']['to']
                .($reportData['comparisonPeriod'] === null ? '' : '-comparison-previous').'.csv', [
                    'Content-Type' => 'text/csv; charset=UTF-8',
                ]);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
