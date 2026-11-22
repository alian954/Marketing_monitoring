<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class GoogleAnalyticsDataApi
{
    private const CAMPAIGN_BREAKDOWN_ROW_LIMIT = 10000;

    /**
     * @return array{access_token: string, refresh_token: ?string}
     */
    public function exchangeAuthorizationCode(string $code, string $redirectUri): array
    {
        $response = Http::asForm()
            ->acceptJson()
            ->timeout(10)
            ->post('https://oauth2.googleapis.com/token', [
                'client_id' => (string) config('services.google_analytics.client_id'),
                'client_secret' => (string) config('services.google_analytics.client_secret'),
                'code' => $code,
                'grant_type' => 'authorization_code',
                'redirect_uri' => $redirectUri,
            ]);

        $payload = $this->successfulPayload($response, 'Google OAuth');
        $accessToken = $payload['access_token'] ?? null;
        $refreshToken = $payload['refresh_token'] ?? null;

        if (! is_string($accessToken) || $accessToken === '') {
            throw new GoogleAnalyticsApiException('Google 授權未回傳存取權杖，請重新連結。');
        }

        return [
            'access_token' => $accessToken,
            'refresh_token' => is_string($refreshToken) && $refreshToken !== '' ? $refreshToken : null,
        ];
    }

    public function refreshAccessToken(string $refreshToken): string
    {
        $response = Http::asForm()
            ->acceptJson()
            ->timeout(10)
            ->post('https://oauth2.googleapis.com/token', [
                'client_id' => (string) config('services.google_analytics.client_id'),
                'client_secret' => (string) config('services.google_analytics.client_secret'),
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken,
            ]);

        $payload = $this->successfulPayload($response, 'Google OAuth');
        $accessToken = $payload['access_token'] ?? null;

        if (! is_string($accessToken) || $accessToken === '') {
            throw new GoogleAnalyticsApiException('Google 未回傳存取權杖，請重新連結帳戶。');
        }

        return $accessToken;
    }

    public function revokeRefreshToken(string $refreshToken): void
    {
        $response = Http::asForm()
            ->timeout(10)
            ->post('https://oauth2.googleapis.com/revoke', [
                'token' => $refreshToken,
            ]);

        $tokenWasAlreadyRevoked = $response->status() === 400
            && $response->json('error') === 'invalid_token';

        if (! $response->successful() && ! $tokenWasAlreadyRevoked) {
            throw new GoogleAnalyticsApiException('Google 尚未確認授權撤銷，連線仍保留，請稍後再試。');
        }
    }

    /**
     * @return array{
     *     sessions: int,
     *     engaged_sessions: int,
     *     key_events: string,
     *     total_revenue: string,
     *     currency_code: ?string,
     *     source_medium_breakdown: list<array{
     *         source_name: string,
     *         medium_name: string,
     *         sessions: int,
     *         engaged_sessions: int,
     *         key_events: string,
     *         total_revenue: string
     *     }>,
     *     source_medium_breakdown_truncated: bool
     * }
     */
    public function campaignReport(
        string $accessToken,
        string $propertyId,
        string $campaignName,
        string $startsOn,
        string $endsOn,
        ?string $sourceName = null,
        ?string $mediumName = null,
    ): array {
        $dimensionFilters = [[
            'filter' => [
                'fieldName' => 'sessionManualCampaignName',
                'stringFilter' => [
                    'matchType' => 'EXACT',
                    'value' => $campaignName,
                    'caseSensitive' => true,
                ],
            ],
        ]];

        if (is_string($sourceName) && trim($sourceName) !== '') {
            $dimensionFilters[] = [
                'filter' => [
                    'fieldName' => 'sessionManualSource',
                    'stringFilter' => [
                        'matchType' => 'EXACT',
                        'value' => trim($sourceName),
                        'caseSensitive' => true,
                    ],
                ],
            ];
        }

        if (is_string($mediumName) && trim($mediumName) !== '') {
            $dimensionFilters[] = [
                'filter' => [
                    'fieldName' => 'sessionManualMedium',
                    'stringFilter' => [
                        'matchType' => 'EXACT',
                        'value' => trim($mediumName),
                        'caseSensitive' => true,
                    ],
                ],
            ];
        }

        $dimensionFilter = count($dimensionFilters) === 1
            ? $dimensionFilters[0]
            : ['andGroup' => ['expressions' => $dimensionFilters]];

        $response = Http::withToken($accessToken)
            ->acceptJson()
            ->timeout(15)
            ->post('https://analyticsdata.googleapis.com/v1beta/properties/'.$propertyId.':runReport', [
                'dateRanges' => [
                    ['startDate' => $startsOn, 'endDate' => $endsOn],
                ],
                'dimensions' => [
                    ['name' => 'sessionManualCampaignName'],
                    ['name' => 'sessionManualSource'],
                    ['name' => 'sessionManualMedium'],
                ],
                'metrics' => [
                    ['name' => 'sessions'],
                    ['name' => 'engagedSessions'],
                    ['name' => 'keyEvents'],
                    ['name' => 'totalRevenue'],
                ],
                'dimensionFilter' => $dimensionFilter,
                'metricAggregations' => ['TOTAL'],
                'limit' => self::CAMPAIGN_BREAKDOWN_ROW_LIMIT + 1,
            ]);

        $payload = $this->successfulPayload($response, 'Google Analytics Data API');

        if (($payload['metadata']['subjectToThresholding'] ?? false) === true) {
            throw new GoogleAnalyticsApiException('GA4 因資料門檻未回傳這項活動的數值，請直接在 GA4 查看。');
        }

        $rows = $payload['rows'] ?? [];

        if (! is_array($rows)) {
            throw new GoogleAnalyticsApiException('Google Analytics 回傳的報表格式無效，請稍後再試。');
        }

        $totalRow = $this->totalRow($payload, $rows);
        $totalMetrics = $totalRow === null
            ? [
                'sessions' => 0,
                'engaged_sessions' => 0,
                'key_events' => '0.0000',
                'total_revenue' => '0.0000',
            ]
            : $this->metricsForRow($totalRow);
        $channelRows = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                throw new GoogleAnalyticsApiException('Google Analytics 回傳的報表格式無效，請稍後再試。');
            }

            if ($this->isMetricAggregationRow($row)) {
                continue;
            }

            $dimensionValues = $row['dimensionValues'] ?? null;

            if (! is_array($dimensionValues) || count($dimensionValues) !== 3) {
                throw new GoogleAnalyticsApiException('Google Analytics 回傳的來源／媒介格式無效，請稍後再試。');
            }

            $channelMetrics = $this->metricsForRow($row);
            $channelRows[] = [
                'source_name' => $this->dimensionValue($dimensionValues[1] ?? null),
                'medium_name' => $this->dimensionValue($dimensionValues[2] ?? null),
                ...$channelMetrics,
            ];
        }

        $rowCount = array_key_exists('rowCount', $payload)
            ? $this->nonNegativeInteger($payload['rowCount'])
            : count($rows);

        return [
            ...$totalMetrics,
            'currency_code' => $this->currencyCode($payload['metadata']['currencyCode'] ?? null),
            'source_medium_breakdown' => array_slice($channelRows, 0, self::CAMPAIGN_BREAKDOWN_ROW_LIMIT),
            'source_medium_breakdown_truncated' => $rowCount > count($rows)
                || count($channelRows) > self::CAMPAIGN_BREAKDOWN_ROW_LIMIT,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, mixed>  $rows
     * @return array<string, mixed>|null
     */
    private function totalRow(array $payload, array $rows): ?array
    {
        $totals = is_array($payload['totals'] ?? null) ? $payload['totals'] : [];

        foreach ($totals as $row) {
            if (is_array($row)) {
                return $row;
            }
        }

        foreach ($rows as $row) {
            if (is_array($row) && $this->isMetricAggregationRow($row)) {
                return $row;
            }
        }

        return $rows === [] ? null : throw new GoogleAnalyticsApiException('Google Analytics 未回傳來源／媒介總計，請稍後再同步。');
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{sessions: int, engaged_sessions: int, key_events: string, total_revenue: string}
     */
    private function metricsForRow(array $row): array
    {
        $values = is_array($row['metricValues'] ?? null)
            ? array_column($row['metricValues'], 'value')
            : [];

        if (count($values) !== 4) {
            throw new GoogleAnalyticsApiException('Google Analytics 回傳的指標格式無效，請稍後再試。');
        }

        return [
            'sessions' => $this->nonNegativeInteger($values[0]),
            'engaged_sessions' => $this->nonNegativeInteger($values[1]),
            'key_events' => $this->decimalString($values[2], false),
            'total_revenue' => $this->decimalString($values[3], true),
        ];
    }

    /** @param array<string, mixed> $row */
    private function isMetricAggregationRow(array $row): bool
    {
        foreach ($row['dimensionValues'] ?? [] as $dimensionValue) {
            $value = is_array($dimensionValue) ? ($dimensionValue['value'] ?? null) : null;

            if (is_string($value) && str_starts_with($value, 'RESERVED_')) {
                return true;
            }
        }

        return false;
    }

    private function dimensionValue(mixed $dimensionValue): string
    {
        $value = is_array($dimensionValue) ? ($dimensionValue['value'] ?? null) : null;

        if (! is_string($value)) {
            throw new GoogleAnalyticsApiException('Google Analytics 回傳的來源／媒介格式無效，請稍後再試。');
        }

        return $value;
    }

    /** @return array<string, mixed> */
    private function successfulPayload(Response $response, string $serviceName): array
    {
        if (! $response->successful()) {
            $message = match ($response->status()) {
                400, 401, 403 => 'Google 授權或 GA4 資源讀取權限有問題，請重新連結並確認已授予資料讀取權限。',
                429 => 'Google Analytics API 配額已用盡，請稍後再同步。',
                default => $serviceName.' 暫時無法讀取，請稍後再試。',
            };

            throw new GoogleAnalyticsApiException($message);
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            throw new GoogleAnalyticsApiException($serviceName.' 回傳格式無效，請稍後再試。');
        }

        return $payload;
    }

    private function nonNegativeInteger(mixed $value): int
    {
        if (! is_string($value) && ! is_int($value)) {
            throw new GoogleAnalyticsApiException('Google Analytics 回傳的數字格式無效。');
        }

        $validated = filter_var($value, FILTER_VALIDATE_INT);

        if ($validated === false || $validated < 0) {
            throw new GoogleAnalyticsApiException('Google Analytics 回傳的數字格式無效。');
        }

        return $validated;
    }

    private function decimalString(mixed $value, bool $allowNegative): string
    {
        if ((! is_string($value) && ! is_int($value) && ! is_float($value)) || ! is_numeric($value)) {
            throw new GoogleAnalyticsApiException('Google Analytics 回傳的數字格式無效。');
        }

        $number = (float) $value;

        if (! is_finite($number) || (! $allowNegative && $number < 0)) {
            throw new GoogleAnalyticsApiException('Google Analytics 回傳的數字格式無效。');
        }

        return PreciseDecimalFormatter::toFourDecimalPlaces($value);
    }

    private function currencyCode(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[A-Z]{3}$/', $value) === 1 ? $value : null;
    }
}
