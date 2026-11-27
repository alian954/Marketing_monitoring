<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ClientResource;
use App\Models\Client;
use App\Models\User;
use App\Services\ClientCrossPlatformOverview;
use App\Services\ClientCrossPlatformReport;
use App\Services\CsvFormulaSanitizer;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Read-only marketing data for BI tools (Looker Studio, Google Sheets, Power BI).
 */
class ClientDataController extends Controller
{
    private const MAX_PERIOD_DAYS = 92;

    public function index(): AnonymousResourceCollection
    {
        return ClientResource::collection(Client::query()->orderBy('name')->get());
    }

    public function summary(Request $request, Client $client, ClientCrossPlatformReport $crossPlatformReport): JsonResponse
    {
        $period = $this->period($request);
        $report = $crossPlatformReport->build($client, $this->user($request), $period);
        $labels = array_map(static fn (array $definition): string => $definition['label'], ClientCrossPlatformOverview::allPaidSources());
        $currencyGroups = [];

        foreach ($report['overview']['currency_groups'] as $group) {
            $platforms = [];

            foreach ($group['platforms'] as $source => $metrics) {
                if ($metrics['campaign_count'] > 0) {
                    $platforms[] = ['platform' => $source, 'label' => $labels[$source] ?? $source] + $metrics;
                }
            }

            $currencyGroups[] = [
                'currency_code' => $group['currency_code'],
                'total' => $group['total'],
                'change_vs_previous_period' => $report['comparison']['currency_groups'][$group['currency_code']] ?? null,
                'platforms' => $platforms,
            ];
        }

        $storeRevenue = $report['store_revenue'];
        unset($storeRevenue['daily_revenue']);

        return response()->json([
            'data' => [
                'client' => (new ClientResource($client))->toArray($request),
                'period' => $period,
                'previous_period' => $report['previous_period'],
                'paid_media' => $currencyGroups,
                'ga4' => $report['overview']['ga4'],
                'attribution' => $report['overview']['attribution'],
                'store_revenue' => $storeRevenue,
                'budget_pacing' => $report['budget_pacing'],
            ],
        ]);
    }

    public function dailyPerformance(Request $request, Client $client, ClientCrossPlatformReport $crossPlatformReport): JsonResponse|StreamedResponse
    {
        $period = $this->period($request);
        $report = $crossPlatformReport->build($client, $this->user($request), $period);
        $storeDaily = $report['store_revenue']['currency_code'] === $report['overview']['primary_currency_code']
            ? $report['store_revenue']['daily_revenue']
            : [];
        $rows = array_map(static fn (array $day): array => $day + ['store_revenue' => $storeDaily[$day['date']] ?? 0.0], $report['overview']['daily']);
        $currencyCode = $report['overview']['primary_currency_code'];

        if ($request->query('format') === 'csv') {
            $columns = $rows === [] ? ['date'] : array_keys($rows[0]);

            return response()->streamDownload(function () use ($rows, $columns, $currencyCode): void {
                $stream = fopen('php://output', 'w');
                fwrite($stream, "\xEF\xBB\xBF");
                fputcsv($stream, [...$columns, 'currency_code'], ',', '"', '');

                foreach ($rows as $row) {
                    fputcsv($stream, [...array_map(static fn (mixed $value): string => CsvFormulaSanitizer::escape((string) $value), array_values($row)), (string) $currencyCode], ',', '"', '');
                }

                fclose($stream);
            }, 'client-'.$client->getKey().'-daily-'.$period['from'].'-'.$period['to'].'.csv', [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Cache-Control' => 'private, no-store',
            ]);
        }

        return response()->json([
            'data' => $rows,
            'meta' => [
                'client_id' => $client->getKey(),
                'currency_code' => $currencyCode,
                'period' => $period,
                'columns' => [
                    'store_revenue' => '商店後台匯入的實際營收（與廣告花費同幣別）',
                    'conversions' => '各廣告平台回報的轉換合計',
                ],
            ],
        ])->header('Cache-Control', 'private, no-store');
    }

    /** @return array{from: string, to: string} */
    private function period(Request $request): array
    {
        $latestCompleteDate = CarbonImmutable::now('Asia/Taipei')->startOfDay()->subDay();
        $input = [
            'from' => $request->query('from', $latestCompleteDate->subDays(29)->toDateString()),
            'to' => $request->query('to', $latestCompleteDate->toDateString()),
        ];
        $validator = Validator::make($input, [
            'from' => ['required', 'string', 'date_format:Y-m-d'],
            'to' => ['required', 'string', 'date_format:Y-m-d', 'after_or_equal:from', 'before_or_equal:'.$latestCompleteDate->toDateString()],
        ]);
        $validator->after(function ($validator) use ($input): void {
            if ($validator->errors()->isEmpty()
                && CarbonImmutable::createFromFormat('!Y-m-d', $input['from'])->addDays(self::MAX_PERIOD_DAYS - 1)->lessThan(CarbonImmutable::createFromFormat('!Y-m-d', $input['to']))) {
                $validator->errors()->add('from', 'The period may not be longer than '.self::MAX_PERIOD_DAYS.' days.');
            }
        });

        /** @var array{from: string, to: string} */
        return $validator->validate();
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
