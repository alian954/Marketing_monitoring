<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientReportShare;
use App\Models\ClientReportSnapshot;
use App\Models\User;
use App\Services\SharedClientReportCsv;
use App\Services\SharedClientReportDataBuilder;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ClientReportSnapshotController extends Controller
{
    public function store(
        Request $request,
        Client $client,
        ClientReportShare $reportShare,
        SharedClientReportDataBuilder $reportDataBuilder,
        SharedClientReportCsv $csv,
    ): RedirectResponse {
        abort_unless((int) $reportShare->client_id === (int) $client->getKey(), 404);

        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $reportData = $reportDataBuilder->buildForShare($reportShare);
        $csvContent = $this->buildCsv($csv, $reportData);
        $snapshot = DB::transaction(fn (): ClientReportSnapshot => $reportShare->snapshots()->create([
            'created_by_user_id' => $user->getKey(),
            'report_sections' => $reportData['reportSections'],
            'report_starts_on' => $reportData['reportPeriod']['from'] ?? null,
            'report_ends_on' => $reportData['reportPeriod']['to'] ?? null,
            'csv_content' => $csvContent,
            'csv_sha256' => hash('sha256', $csvContent),
        ]));

        return redirect()
            ->route('clients.report-shares.index', $client)
            ->with('status', '已建立報表快照。')
            ->with('createdSnapshotId', $snapshot->getKey());
    }

    public function download(
        Client $client,
        ClientReportShare $reportShare,
        ClientReportSnapshot $snapshot,
    ): StreamedResponse {
        abort_unless((int) $reportShare->client_id === (int) $client->getKey(), 404);
        abort_unless((int) $snapshot->client_report_share_id === (int) $reportShare->getKey(), 404);

        return $this->csvResponse($snapshot, false);
    }

    public function downloadPublic(
        string $token,
        int $snapshot,
        SharedClientReportDataBuilder $reportDataBuilder,
    ): StreamedResponse {
        $share = $reportDataBuilder->activeShareForToken($token);
        $reportSnapshot = $share->snapshots()->findOrFail($snapshot);

        return $this->csvResponse($reportSnapshot, true);
    }

    /** @param array<string, mixed> $reportData */
    private function buildCsv(SharedClientReportCsv $csv, array $reportData): string
    {
        return $csv->build(
            $reportData['client'],
            $reportData['campaignRows'],
            $reportData['reportingCurrencyGroups'],
            $reportData['reportSections'],
            $reportData['reportPeriod'] === null
                ? null
                : $reportData['reportPeriod']['from'].' 至 '.$reportData['reportPeriod']['to'],
            $reportData['comparisonPeriod']['label'] ?? null,
        );
    }

    private function csvResponse(ClientReportSnapshot $snapshot, bool $public): StreamedResponse
    {
        try {
            $csvContent = $snapshot->csv_content;
        } catch (DecryptException) {
            abort(404);
        }

        abort_unless(
            is_string($csvContent) && hash_equals($snapshot->csv_sha256, hash('sha256', $csvContent)),
            404,
        );

        $response = response()->streamDownload(
            static function () use ($csvContent): void {
                echo $csvContent;
            },
            $this->filename($snapshot),
            ['Content-Type' => 'text/csv; charset=UTF-8'],
        );
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        if ($public) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }

        return $response;
    }

    private function filename(ClientReportSnapshot $snapshot): string
    {
        $period = $snapshot->report_starts_on !== null && $snapshot->report_ends_on !== null
            ? '-'.$snapshot->report_starts_on->toDateString().'-to-'.$snapshot->report_ends_on->toDateString()
            : '-'.$snapshot->created_at->toDateString();

        return 'client-report-snapshot-'.$snapshot->getKey().$period.'.csv';
    }
}
