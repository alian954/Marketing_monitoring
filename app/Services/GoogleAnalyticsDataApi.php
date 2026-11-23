<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class GoogleAnalyticsDataApi
{
    private const CAMPAIGN_BREAKDOWN_ROW_LIMIT = 10000;

    private const CAMPAIGN_DAILY_ROW_LIMIT = 10000;

    private const CAMPAIGN_LANDING_PAGE_ROW_LIMIT = 200;

    private const CAMPAIGN_EVENT_NAME_ROW_LIMIT = 200;

    private const CAMPAIGN_ITEM_ROW_LIMIT = 200;

    private const CAMPAIGN_AD_CONTENT_ROW_LIMIT = 200;

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
     *         channel_group: string,
     *         source_name: string,
     *         medium_name: string,
     *         sessions: int,
     *         engaged_sessions: int,
     *         key_events: string,
     *         total_revenue: string
     *     }>,
     *     source_medium_breakdown_truncated: bool,
     *     landing_page_report: array{
     *         status: 'complete'|'thresholded'|'unavailable',
     *         window_start_on: string,
     *         window_end_on: string,
     *         currency_code: ?string,
     *         truncated: bool,
     *         rows: list<array{landing_page: string, sessions: int, engaged_sessions: int, engagement_rate: string, bounce_rate: string, key_events: string, total_revenue: string}>
     *     },
     *     event_name_report: array{
     *         status: 'complete'|'thresholded'|'unavailable',
     *         window_start_on: string,
     *         window_end_on: string,
     *         currency_code: ?string,
     *         truncated: bool,
     *         rows: list<array{event_name: string, event_count: int, key_events: string, total_revenue: string}>
     *     },
     *     item_report: array{
     *         status: 'complete'|'thresholded'|'unavailable',
     *         window_start_on: ?string,
     *         window_end_on: ?string,
     *         currency_code: ?string,
     *         truncated: bool,
     *         rows: list<array{item_name: string, items_viewed: int, items_added_to_cart: int, items_purchased: int, item_revenue: string}>
     *     },
     *     ad_content_report: array{
     *         status: 'complete'|'thresholded'|'unavailable'|'not_applicable',
     *         window_start_on: ?string,
     *         window_end_on: ?string,
     *         currency_code: ?string,
     *         truncated: bool,
     *         rows: list<array{ad_content: string, sessions: int, engaged_sessions: int, engagement_rate: string, key_events: string, total_revenue: string}>
     *     },
     *     daily_metrics: list<array{
     *         date: string,
     *         sessions: int,
     *         engaged_sessions: int,
     *         key_events: string,
     *         total_revenue: string
     *     }>
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
        ?string $googleAdsCampaignId = null,
    ): array {
        if (! $this->isCalendarDate($startsOn) || ! $this->isCalendarDate($endsOn) || $startsOn > $endsOn) {
            throw new GoogleAnalyticsApiException('GA4 活動日期區間無效，請重新設定開始日與結束日。');
        }

        $googleAdsCampaignId = is_string($googleAdsCampaignId) ? trim($googleAdsCampaignId) : '';
        $usesGoogleAdsAutoTagging = $googleAdsCampaignId !== '';
        $campaignDimension = $usesGoogleAdsAutoTagging
            ? 'sessionGoogleAdsCampaignId'
            : 'sessionManualCampaignName';
        $sourceDimension = $usesGoogleAdsAutoTagging ? 'sessionSource' : 'sessionManualSource';
        $mediumDimension = $usesGoogleAdsAutoTagging ? 'sessionMedium' : 'sessionManualMedium';
        $campaignValue = $usesGoogleAdsAutoTagging ? $googleAdsCampaignId : $campaignName;
        $dimensionFilters = [[
            'filter' => [
                'fieldName' => $campaignDimension,
                'stringFilter' => [
                    'matchType' => 'EXACT',
                    'value' => $campaignValue,
                    'caseSensitive' => true,
                ],
            ],
        ]];

        if (! $usesGoogleAdsAutoTagging && is_string($sourceName) && trim($sourceName) !== '') {
            $dimensionFilters[] = [
                'filter' => [
                    'fieldName' => $sourceDimension,
                    'stringFilter' => [
                        'matchType' => 'EXACT',
                        'value' => trim($sourceName),
                        'caseSensitive' => true,
                    ],
                ],
            ];
        }

        if (! $usesGoogleAdsAutoTagging && is_string($mediumName) && trim($mediumName) !== '') {
            $dimensionFilters[] = [
                'filter' => [
                    'fieldName' => $mediumDimension,
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
                    ['name' => $campaignDimension],
                    ['name' => 'sessionDefaultChannelGroup'],
                    ['name' => $sourceDimension],
                    ['name' => $mediumDimension],
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

            if (! is_array($dimensionValues) || count($dimensionValues) !== 4) {
                throw new GoogleAnalyticsApiException('Google Analytics 回傳的來源／媒介格式無效，請稍後再試。');
            }

            $channelMetrics = $this->metricsForRow($row);
            $channelRows[] = [
                'channel_group' => $this->dimensionValue($dimensionValues[1] ?? null),
                'source_name' => $this->dimensionValue($dimensionValues[2] ?? null),
                'medium_name' => $this->dimensionValue($dimensionValues[3] ?? null),
                ...$channelMetrics,
            ];
        }

        $rowCount = array_key_exists('rowCount', $payload)
            ? $this->nonNegativeInteger($payload['rowCount'])
            : count($rows);
        $dailyMetrics = $this->dailyCampaignMetrics(
            $accessToken,
            $propertyId,
            $startsOn,
            $endsOn,
            $dimensionFilter,
        );
        try {
            $landingPageReport = $this->landingPageBreakdown(
                $accessToken,
                $propertyId,
                $startsOn,
                $endsOn,
                $dimensionFilter,
            );
        } catch (GoogleAnalyticsApiException|ConnectionException) {
            $landingPageReport = $this->emptyLandingPageReport('unavailable', $startsOn, $endsOn);
        }
        try {
            $eventNameReport = $this->eventNameBreakdown(
                $accessToken,
                $propertyId,
                $startsOn,
                $endsOn,
                $dimensionFilter,
            );
        } catch (GoogleAnalyticsApiException|ConnectionException) {
            $eventNameReport = $this->emptyEventNameReport('unavailable', $startsOn, $endsOn);
        }
        try {
            $itemReport = $this->campaignItemBreakdown(
                $accessToken,
                $propertyId,
                $startsOn,
                $endsOn,
                $dimensionFilter,
            );
        } catch (GoogleAnalyticsApiException|ConnectionException) {
            $itemReport = $this->emptyItemReport(
                'unavailable',
                $startsOn,
                min($endsOn, now('Asia/Taipei')->subDay()->toDateString()),
            );
        }
        if ($usesGoogleAdsAutoTagging) {
            $adContentReport = $this->emptyAdContentReport('not_applicable', null, null);
        } else {
            try {
                $adContentReport = $this->campaignAdContentBreakdown(
                    $accessToken,
                    $propertyId,
                    $startsOn,
                    $endsOn,
                    $dimensionFilter,
                );
            } catch (GoogleAnalyticsApiException|ConnectionException) {
                $adContentReport = $this->emptyAdContentReport(
                    'unavailable',
                    $startsOn,
                    min($endsOn, now('Asia/Taipei')->subDay()->toDateString()),
                );
            }
        }

        return [
            ...$totalMetrics,
            'currency_code' => $this->currencyCode($payload['metadata']['currencyCode'] ?? null),
            'source_medium_breakdown' => array_slice($channelRows, 0, self::CAMPAIGN_BREAKDOWN_ROW_LIMIT),
            'source_medium_breakdown_truncated' => $rowCount > count($rows)
                || count($channelRows) > self::CAMPAIGN_BREAKDOWN_ROW_LIMIT,
            'landing_page_report' => $landingPageReport,
            'event_name_report' => $eventNameReport,
            'item_report' => $itemReport,
            'ad_content_report' => $adContentReport,
            'daily_metrics' => $dailyMetrics,
        ];
    }

    /**
     * @param  array<string, mixed>  $dimensionFilter
     * @return array{
     *     status: 'complete'|'thresholded'|'unavailable',
     *     window_start_on: ?string,
     *     window_end_on: ?string,
     *     currency_code: ?string,
     *     truncated: bool,
     *     rows: list<array{ad_content: string, sessions: int, engaged_sessions: int, engagement_rate: string, key_events: string, total_revenue: string}>
     * }
     */
    private function campaignAdContentBreakdown(
        string $accessToken,
        string $propertyId,
        string $startsOn,
        string $endsOn,
        array $dimensionFilter,
    ): array {
        $windowEndOn = min($endsOn, now('Asia/Taipei')->subDay()->toDateString());

        if ($startsOn > $windowEndOn) {
            return $this->emptyAdContentReport('unavailable', null, null);
        }

        $response = Http::withToken($accessToken)
            ->acceptJson()
            ->timeout(15)
            ->post('https://analyticsdata.googleapis.com/v1beta/properties/'.$propertyId.':runReport', [
                'dateRanges' => [
                    ['startDate' => $startsOn, 'endDate' => $windowEndOn],
                ],
                'dimensions' => [
                    ['name' => 'sessionManualAdContent'],
                ],
                'metrics' => [
                    ['name' => 'sessions'],
                    ['name' => 'engagedSessions'],
                    ['name' => 'engagementRate'],
                    ['name' => 'keyEvents'],
                    ['name' => 'totalRevenue'],
                ],
                'dimensionFilter' => $dimensionFilter,
                'orderBys' => [
                    ['metric' => ['metricName' => 'sessions'], 'desc' => true],
                    ['metric' => ['metricName' => 'totalRevenue'], 'desc' => true],
                    ['dimension' => ['dimensionName' => 'sessionManualAdContent']],
                ],
                'limit' => self::CAMPAIGN_AD_CONTENT_ROW_LIMIT + 1,
            ]);

        $payload = $this->successfulPayload($response, 'Google Analytics Data API');
        $metadata = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [];
        $currencyCode = $this->currencyCode($metadata['currencyCode'] ?? null);

        if (($metadata['subjectToThresholding'] ?? false) === true) {
            return [
                ...$this->emptyAdContentReport('thresholded', $startsOn, $windowEndOn),
                'currency_code' => $currencyCode,
            ];
        }

        $rows = $payload['rows'] ?? [];

        if (! is_array($rows) || ! array_is_list($rows)) {
            throw new GoogleAnalyticsApiException('Google Analytics 回傳的素材內容報表格式無效，請稍後再試。');
        }

        $adContentRows = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                throw new GoogleAnalyticsApiException('Google Analytics 回傳的素材內容報表格式無效，請稍後再試。');
            }

            $dimensionValues = $row['dimensionValues'] ?? null;
            $metricValues = $row['metricValues'] ?? null;

            if (! is_array($dimensionValues)
                || ! array_is_list($dimensionValues)
                || count($dimensionValues) !== 1
                || ! is_array($metricValues)
                || ! array_is_list($metricValues)
                || count($metricValues) !== 5) {
                throw new GoogleAnalyticsApiException('Google Analytics 回傳的素材內容欄位數量無效，請稍後再試。');
            }

            foreach ($metricValues as $metricValue) {
                if (! is_array($metricValue) || ! array_key_exists('value', $metricValue)) {
                    throw new GoogleAnalyticsApiException('Google Analytics 回傳的素材內容指標格式無效，請稍後再試。');
                }
            }

            $adContent = $this->dimensionValue($dimensionValues[0]);

            if (mb_strlen($adContent) > 4096) {
                throw new GoogleAnalyticsApiException('Google Analytics 回傳的素材內容過長，請稍後再試。');
            }

            $engagementRate = $this->decimalString($metricValues[2]['value'], false);

            if ((float) $engagementRate > 1) {
                throw new GoogleAnalyticsApiException('Google Analytics 回傳的素材參與率格式無效，請稍後再試。');
            }

            $adContentRows[] = [
                'ad_content' => $adContent,
                'sessions' => $this->nonNegativeInteger($metricValues[0]['value']),
                'engaged_sessions' => $this->nonNegativeInteger($metricValues[1]['value']),
                'engagement_rate' => $engagementRate,
                'key_events' => $this->decimalString($metricValues[3]['value'], false),
                'total_revenue' => $this->decimalString($metricValues[4]['value'], true),
            ];
        }

        $rowCount = array_key_exists('rowCount', $payload)
            ? $this->nonNegativeInteger($payload['rowCount'])
            : count($adContentRows);

        return [
            'status' => 'complete',
            'window_start_on' => $startsOn,
            'window_end_on' => $windowEndOn,
            'currency_code' => $currencyCode,
            'truncated' => $rowCount > count($adContentRows)
                || count($adContentRows) > self::CAMPAIGN_AD_CONTENT_ROW_LIMIT
                || ($metadata['dataLossFromOtherRow'] ?? false) === true,
            'rows' => array_slice($adContentRows, 0, self::CAMPAIGN_AD_CONTENT_ROW_LIMIT),
        ];
    }

    /** @return array{status: 'unavailable'|'thresholded'|'not_applicable', window_start_on: ?string, window_end_on: ?string, currency_code: null, truncated: false, rows: list<array<string, mixed>>} */
    private function emptyAdContentReport(string $status, ?string $startsOn, ?string $endsOn): array
    {
        return [
            'status' => $status,
            'window_start_on' => $startsOn,
            'window_end_on' => $endsOn,
            'currency_code' => null,
            'truncated' => false,
            'rows' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $dimensionFilter
     * @return array{
     *     status: 'complete'|'thresholded'|'unavailable',
     *     window_start_on: ?string,
     *     window_end_on: ?string,
     *     currency_code: ?string,
     *     truncated: bool,
     *     rows: list<array{item_name: string, items_viewed: int, items_added_to_cart: int, items_purchased: int, item_revenue: string}>
     * }
     */
    private function campaignItemBreakdown(
        string $accessToken,
        string $propertyId,
        string $startsOn,
        string $endsOn,
        array $dimensionFilter,
    ): array {
        $windowEndOn = min($endsOn, now('Asia/Taipei')->subDay()->toDateString());

        if ($startsOn > $windowEndOn) {
            return $this->emptyItemReport('unavailable', null, null);
        }

        $response = Http::withToken($accessToken)
            ->acceptJson()
            ->timeout(15)
            ->post('https://analyticsdata.googleapis.com/v1beta/properties/'.$propertyId.':runReport', [
                'dateRanges' => [
                    ['startDate' => $startsOn, 'endDate' => $windowEndOn],
                ],
                'dimensions' => [
                    ['name' => 'itemName'],
                ],
                'metrics' => [
                    ['name' => 'itemsViewed'],
                    ['name' => 'itemsAddedToCart'],
                    ['name' => 'itemsPurchased'],
                    ['name' => 'itemRevenue'],
                ],
                'dimensionFilter' => $dimensionFilter,
                'orderBys' => [
                    ['metric' => ['metricName' => 'itemRevenue'], 'desc' => true],
                    ['metric' => ['metricName' => 'itemsPurchased'], 'desc' => true],
                    ['metric' => ['metricName' => 'itemsViewed'], 'desc' => true],
                    ['dimension' => ['dimensionName' => 'itemName']],
                ],
                'limit' => self::CAMPAIGN_ITEM_ROW_LIMIT + 1,
            ]);

        $payload = $this->successfulPayload($response, 'Google Analytics Data API');
        $metadata = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [];
        $currencyCode = $this->currencyCode($metadata['currencyCode'] ?? null);

        if (($metadata['subjectToThresholding'] ?? false) === true) {
            return [
                ...$this->emptyItemReport('thresholded', $startsOn, $windowEndOn),
                'currency_code' => $currencyCode,
            ];
        }

        $rows = $payload['rows'] ?? [];

        if (! is_array($rows) || ! array_is_list($rows)) {
            throw new GoogleAnalyticsApiException('Google Analytics 回傳的商品報表格式無效，請稍後再試。');
        }

        $itemRows = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                throw new GoogleAnalyticsApiException('Google Analytics 回傳的商品報表格式無效，請稍後再試。');
            }

            $dimensionValues = $row['dimensionValues'] ?? null;
            $metricValues = $row['metricValues'] ?? null;

            if (! is_array($dimensionValues)
                || ! array_is_list($dimensionValues)
                || count($dimensionValues) !== 1
                || ! is_array($metricValues)
                || ! array_is_list($metricValues)
                || count($metricValues) !== 4) {
                throw new GoogleAnalyticsApiException('Google Analytics 回傳的商品欄位數量無效，請稍後再試。');
            }

            foreach ($metricValues as $metricValue) {
                if (! is_array($metricValue) || ! array_key_exists('value', $metricValue)) {
                    throw new GoogleAnalyticsApiException('Google Analytics 回傳的商品指標格式無效，請稍後再試。');
                }
            }

            $itemName = $this->dimensionValue($dimensionValues[0]);

            if (mb_strlen($itemName) > 4096) {
                throw new GoogleAnalyticsApiException('Google Analytics 回傳的商品名稱過長，請稍後再試。');
            }

            $itemRows[] = [
                'item_name' => $itemName,
                'items_viewed' => $this->nonNegativeInteger($metricValues[0]['value']),
                'items_added_to_cart' => $this->nonNegativeInteger($metricValues[1]['value']),
                'items_purchased' => $this->nonNegativeInteger($metricValues[2]['value']),
                'item_revenue' => $this->decimalString($metricValues[3]['value'], true),
            ];
        }

        $rowCount = array_key_exists('rowCount', $payload)
            ? $this->nonNegativeInteger($payload['rowCount'])
            : count($itemRows);

        return [
            'status' => 'complete',
            'window_start_on' => $startsOn,
            'window_end_on' => $windowEndOn,
            'currency_code' => $currencyCode,
            'truncated' => $rowCount > count($itemRows)
                || count($itemRows) > self::CAMPAIGN_ITEM_ROW_LIMIT
                || ($metadata['dataLossFromOtherRow'] ?? false) === true,
            'rows' => array_slice($itemRows, 0, self::CAMPAIGN_ITEM_ROW_LIMIT),
        ];
    }

    /** @return array{status: 'unavailable'|'thresholded', window_start_on: ?string, window_end_on: ?string, currency_code: null, truncated: false, rows: list<array<string, mixed>>} */
    private function emptyItemReport(string $status, ?string $startsOn, ?string $endsOn): array
    {
        return [
            'status' => $status,
            'window_start_on' => $startsOn,
            'window_end_on' => $endsOn,
            'currency_code' => null,
            'truncated' => false,
            'rows' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $dimensionFilter
     * @return array{
     *     status: 'complete'|'thresholded',
     *     window_start_on: string,
     *     window_end_on: string,
     *     currency_code: ?string,
     *     truncated: bool,
     *     rows: list<array{event_name: string, event_count: int, key_events: string, total_revenue: string}>
     * }
     */
    private function eventNameBreakdown(
        string $accessToken,
        string $propertyId,
        string $startsOn,
        string $endsOn,
        array $dimensionFilter,
    ): array {
        $response = Http::withToken($accessToken)
            ->acceptJson()
            ->timeout(15)
            ->post('https://analyticsdata.googleapis.com/v1beta/properties/'.$propertyId.':runReport', [
                'dateRanges' => [
                    ['startDate' => $startsOn, 'endDate' => $endsOn],
                ],
                'dimensions' => [
                    ['name' => 'eventName'],
                ],
                'metrics' => [
                    ['name' => 'eventCount'],
                    ['name' => 'keyEvents'],
                    ['name' => 'totalRevenue'],
                ],
                'dimensionFilter' => $dimensionFilter,
                'orderBys' => [
                    ['metric' => ['metricName' => 'keyEvents'], 'desc' => true],
                    ['metric' => ['metricName' => 'totalRevenue'], 'desc' => true],
                    ['metric' => ['metricName' => 'eventCount'], 'desc' => true],
                    ['dimension' => ['dimensionName' => 'eventName']],
                ],
                'limit' => self::CAMPAIGN_EVENT_NAME_ROW_LIMIT + 1,
            ]);

        $payload = $this->successfulPayload($response, 'Google Analytics Data API');
        $metadata = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [];
        $currencyCode = $this->currencyCode($metadata['currencyCode'] ?? null);

        if (($metadata['subjectToThresholding'] ?? false) === true) {
            return [
                ...$this->emptyEventNameReport('thresholded', $startsOn, $endsOn),
                'currency_code' => $currencyCode,
            ];
        }

        $rows = $payload['rows'] ?? [];

        if (! is_array($rows) || ! array_is_list($rows)) {
            throw new GoogleAnalyticsApiException('Google Analytics 回傳的事件名稱報表格式無效，請稍後再試。');
        }

        $eventRows = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                throw new GoogleAnalyticsApiException('Google Analytics 回傳的事件名稱報表格式無效，請稍後再試。');
            }

            $dimensionValues = $row['dimensionValues'] ?? null;
            $metricValues = $row['metricValues'] ?? null;

            if (! is_array($dimensionValues)
                || ! array_is_list($dimensionValues)
                || count($dimensionValues) !== 1
                || ! is_array($metricValues)
                || ! array_is_list($metricValues)
                || count($metricValues) !== 3) {
                throw new GoogleAnalyticsApiException('Google Analytics 回傳的事件名稱欄位數量無效，請稍後再試。');
            }

            foreach ($metricValues as $metricValue) {
                if (! is_array($metricValue) || ! array_key_exists('value', $metricValue)) {
                    throw new GoogleAnalyticsApiException('Google Analytics 回傳的事件指標格式無效，請稍後再試。');
                }
            }

            $eventName = $this->dimensionValue($dimensionValues[0]);

            if (mb_strlen($eventName) > 256) {
                throw new GoogleAnalyticsApiException('Google Analytics 回傳的事件名稱過長，請稍後再試。');
            }

            $eventRows[] = [
                'event_name' => $eventName,
                'event_count' => $this->nonNegativeInteger($metricValues[0]['value']),
                'key_events' => $this->decimalString($metricValues[1]['value'], false),
                'total_revenue' => $this->decimalString($metricValues[2]['value'], true),
            ];
        }

        $rowCount = array_key_exists('rowCount', $payload)
            ? $this->nonNegativeInteger($payload['rowCount'])
            : count($eventRows);

        return [
            'status' => 'complete',
            'window_start_on' => $startsOn,
            'window_end_on' => $endsOn,
            'currency_code' => $currencyCode,
            'truncated' => $rowCount > count($eventRows)
                || count($eventRows) > self::CAMPAIGN_EVENT_NAME_ROW_LIMIT
                || ($metadata['dataLossFromOtherRow'] ?? false) === true,
            'rows' => array_slice($eventRows, 0, self::CAMPAIGN_EVENT_NAME_ROW_LIMIT),
        ];
    }

    /** @return array{status: 'unavailable'|'thresholded', window_start_on: string, window_end_on: string, currency_code: null, truncated: false, rows: list<array<string, mixed>>} */
    private function emptyEventNameReport(string $status, string $startsOn, string $endsOn): array
    {
        return [
            'status' => $status,
            'window_start_on' => $startsOn,
            'window_end_on' => $endsOn,
            'currency_code' => null,
            'truncated' => false,
            'rows' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $dimensionFilter
     * @return array{
     *     status: 'complete'|'thresholded',
     *     window_start_on: string,
     *     window_end_on: string,
     *     currency_code: ?string,
     *     truncated: bool,
     *     rows: list<array{landing_page: string, sessions: int, engaged_sessions: int, engagement_rate: string, bounce_rate: string, key_events: string, total_revenue: string}>
     * }
     */
    private function landingPageBreakdown(
        string $accessToken,
        string $propertyId,
        string $startsOn,
        string $endsOn,
        array $dimensionFilter,
    ): array {
        $response = Http::withToken($accessToken)
            ->acceptJson()
            ->timeout(15)
            ->post('https://analyticsdata.googleapis.com/v1beta/properties/'.$propertyId.':runReport', [
                'dateRanges' => [
                    ['startDate' => $startsOn, 'endDate' => $endsOn],
                ],
                'dimensions' => [
                    ['name' => 'landingPage'],
                ],
                'metrics' => [
                    ['name' => 'sessions'],
                    ['name' => 'engagedSessions'],
                    ['name' => 'engagementRate'],
                    ['name' => 'bounceRate'],
                    ['name' => 'keyEvents'],
                    ['name' => 'totalRevenue'],
                ],
                'dimensionFilter' => $dimensionFilter,
                'orderBys' => [
                    ['metric' => ['metricName' => 'sessions'], 'desc' => true],
                    ['dimension' => ['dimensionName' => 'landingPage']],
                ],
                'limit' => self::CAMPAIGN_LANDING_PAGE_ROW_LIMIT + 1,
            ]);

        $payload = $this->successfulPayload($response, 'Google Analytics Data API');
        $metadata = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [];
        $currencyCode = $this->currencyCode($metadata['currencyCode'] ?? null);

        if (($metadata['subjectToThresholding'] ?? false) === true) {
            return [
                ...$this->emptyLandingPageReport('thresholded', $startsOn, $endsOn),
                'currency_code' => $currencyCode,
            ];
        }

        $rows = $payload['rows'] ?? [];

        if (! is_array($rows) || ! array_is_list($rows)) {
            throw new GoogleAnalyticsApiException('Google Analytics 回傳的登陸頁報表格式無效，請稍後再試。');
        }

        $landingPageRows = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                throw new GoogleAnalyticsApiException('Google Analytics 回傳的登陸頁報表格式無效，請稍後再試。');
            }

            $dimensionValues = $row['dimensionValues'] ?? null;
            $metricValues = $row['metricValues'] ?? null;

            if (! is_array($dimensionValues)
                || ! array_is_list($dimensionValues)
                || count($dimensionValues) !== 1
                || ! is_array($metricValues)
                || ! array_is_list($metricValues)
                || count($metricValues) !== 6) {
                throw new GoogleAnalyticsApiException('Google Analytics 回傳的登陸頁欄位數量無效，請稍後再試。');
            }

            foreach ($metricValues as $metricValue) {
                if (! is_array($metricValue) || ! array_key_exists('value', $metricValue)) {
                    throw new GoogleAnalyticsApiException('Google Analytics 回傳的登陸頁指標格式無效，請稍後再試。');
                }
            }

            $landingPage = $this->landingPagePath($dimensionValues[0]);
            $engagementRate = $this->decimalString($metricValues[2]['value'], false);
            $bounceRate = $this->decimalString($metricValues[3]['value'], false);

            if ((float) $engagementRate > 1 || (float) $bounceRate > 1) {
                throw new GoogleAnalyticsApiException('Google Analytics 回傳的登陸頁比率格式無效，請稍後再試。');
            }

            $landingPageRows[] = [
                'landing_page' => $landingPage,
                'sessions' => $this->nonNegativeInteger($metricValues[0]['value']),
                'engaged_sessions' => $this->nonNegativeInteger($metricValues[1]['value']),
                'engagement_rate' => $engagementRate,
                'bounce_rate' => $bounceRate,
                'key_events' => $this->decimalString($metricValues[4]['value'], false),
                'total_revenue' => $this->decimalString($metricValues[5]['value'], true),
            ];
        }

        $rowCount = array_key_exists('rowCount', $payload)
            ? $this->nonNegativeInteger($payload['rowCount'])
            : count($landingPageRows);

        return [
            'status' => 'complete',
            'window_start_on' => $startsOn,
            'window_end_on' => $endsOn,
            'currency_code' => $currencyCode,
            'truncated' => $rowCount > count($landingPageRows)
                || count($landingPageRows) > self::CAMPAIGN_LANDING_PAGE_ROW_LIMIT
                || ($metadata['dataLossFromOtherRow'] ?? false) === true,
            'rows' => array_slice($landingPageRows, 0, self::CAMPAIGN_LANDING_PAGE_ROW_LIMIT),
        ];
    }

    private function landingPagePath(mixed $dimensionValue): string
    {
        $value = is_array($dimensionValue) ? ($dimensionValue['value'] ?? null) : null;

        if (! is_string($value) || strlen($value) > 4096) {
            throw new GoogleAnalyticsApiException('Google Analytics 回傳的登陸頁路徑格式無效，請稍後再試。');
        }

        return explode('?', $value, 2)[0];
    }

    /** @return array{status: 'unavailable'|'thresholded', window_start_on: string, window_end_on: string, currency_code: null, truncated: false, rows: list<array<string, mixed>>} */
    private function emptyLandingPageReport(string $status, string $startsOn, string $endsOn): array
    {
        return [
            'status' => $status,
            'window_start_on' => $startsOn,
            'window_end_on' => $endsOn,
            'currency_code' => null,
            'truncated' => false,
            'rows' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $dimensionFilter
     * @return list<array{date: string, sessions: int, engaged_sessions: int, key_events: string, total_revenue: string}>
     */
    private function dailyCampaignMetrics(
        string $accessToken,
        string $propertyId,
        string $startsOn,
        string $endsOn,
        array $dimensionFilter,
    ): array {
        $response = Http::withToken($accessToken)
            ->acceptJson()
            ->timeout(15)
            ->post('https://analyticsdata.googleapis.com/v1beta/properties/'.$propertyId.':runReport', [
                'dateRanges' => [
                    ['startDate' => $startsOn, 'endDate' => $endsOn],
                ],
                'dimensions' => [
                    ['name' => 'date'],
                ],
                'metrics' => [
                    ['name' => 'sessions'],
                    ['name' => 'engagedSessions'],
                    ['name' => 'keyEvents'],
                    ['name' => 'totalRevenue'],
                ],
                'dimensionFilter' => $dimensionFilter,
                'limit' => self::CAMPAIGN_DAILY_ROW_LIMIT + 1,
            ]);

        $payload = $this->successfulPayload($response, 'Google Analytics Data API');

        if (($payload['metadata']['subjectToThresholding'] ?? false) === true) {
            throw new GoogleAnalyticsApiException('GA4 因資料門檻未回傳這項活動的每日數值，請直接在 GA4 查看。');
        }

        $rows = $payload['rows'] ?? [];

        if (! is_array($rows) || ! array_is_list($rows)) {
            throw new GoogleAnalyticsApiException('Google Analytics 回傳的每日報表格式無效，請稍後再試。');
        }

        $rowCount = array_key_exists('rowCount', $payload)
            ? $this->nonNegativeInteger($payload['rowCount'])
            : count($rows);

        if ($rowCount > count($rows) || count($rows) > self::CAMPAIGN_DAILY_ROW_LIMIT) {
            throw new GoogleAnalyticsApiException('GA4 每日資料超過可讀取上限，請縮短活動日期區間後再同步。');
        }

        $dailyMetricsByDate = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                throw new GoogleAnalyticsApiException('Google Analytics 回傳的每日報表格式無效，請稍後再試。');
            }

            $dimensionValues = $row['dimensionValues'] ?? null;

            if (! is_array($dimensionValues) || ! array_is_list($dimensionValues) || count($dimensionValues) !== 1) {
                throw new GoogleAnalyticsApiException('Google Analytics 回傳的每日日期格式無效，請稍後再試。');
            }

            $dateDimension = $dimensionValues[0];

            if (! is_array($dateDimension) || ! is_string($dateDimension['value'] ?? null)) {
                throw new GoogleAnalyticsApiException('Google Analytics 回傳的每日日期格式無效，請稍後再試。');
            }

            $date = $this->dailyDate($dateDimension['value'], $startsOn, $endsOn);
            $metricValues = $row['metricValues'] ?? null;

            if (! is_array($metricValues) || ! array_is_list($metricValues) || count($metricValues) !== 4) {
                throw new GoogleAnalyticsApiException('Google Analytics 回傳的每日指標格式無效，請稍後再試。');
            }

            foreach ($metricValues as $metricValue) {
                if (! is_array($metricValue) || ! array_key_exists('value', $metricValue)) {
                    throw new GoogleAnalyticsApiException('Google Analytics 回傳的每日指標格式無效，請稍後再試。');
                }
            }

            if (array_key_exists($date, $dailyMetricsByDate)) {
                throw new GoogleAnalyticsApiException('Google Analytics 回傳了重複的每日資料，請稍後再同步。');
            }

            $dailyMetricsByDate[$date] = [
                'date' => $date,
                ...$this->metricsForRow($row),
            ];
        }

        ksort($dailyMetricsByDate);

        return array_values($dailyMetricsByDate);
    }

    private function dailyDate(string $value, string $startsOn, string $endsOn): string
    {
        if (preg_match('/^\d{8}$/D', $value) !== 1) {
            throw new GoogleAnalyticsApiException('Google Analytics 回傳的每日日期格式無效，請稍後再試。');
        }

        $year = (int) substr($value, 0, 4);
        $month = (int) substr($value, 4, 2);
        $day = (int) substr($value, 6, 2);

        if (! checkdate($month, $day, $year)) {
            throw new GoogleAnalyticsApiException('Google Analytics 回傳的每日日期格式無效，請稍後再試。');
        }

        $date = $year.'-'.str_pad((string) $month, 2, '0', STR_PAD_LEFT).'-'.str_pad((string) $day, 2, '0', STR_PAD_LEFT);

        if ($date < $startsOn || $date > $endsOn) {
            throw new GoogleAnalyticsApiException('Google Analytics 回傳了活動日期區間外的每日資料，請稍後再同步。');
        }

        return $date;
    }

    private function isCalendarDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date instanceof \DateTimeImmutable && $date->format('Y-m-d') === $value;
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
