<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class GoogleAdsDataApi
{
    /** @return array{access_token: string, refresh_token: ?string} */
    public function exchangeAuthorizationCode(string $code, string $redirectUri): array
    {
        $response = Http::asForm()
            ->acceptJson()
            ->timeout(10)
            ->post('https://oauth2.googleapis.com/token', [
                'client_id' => (string) config('services.google_ads.client_id'),
                'client_secret' => (string) config('services.google_ads.client_secret'),
                'code' => $code,
                'grant_type' => 'authorization_code',
                'redirect_uri' => $redirectUri,
            ]);
        $payload = $this->successfulPayload($response, 'Google OAuth');
        $accessToken = $payload['access_token'] ?? null;
        $refreshToken = $payload['refresh_token'] ?? null;

        if (! is_string($accessToken) || $accessToken === '') {
            throw new GoogleAdsApiException('Google 授權未回傳存取權杖，請重新連結。');
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
                'client_id' => (string) config('services.google_ads.client_id'),
                'client_secret' => (string) config('services.google_ads.client_secret'),
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken,
            ]);
        $payload = $this->successfulPayload($response, 'Google OAuth');
        $accessToken = $payload['access_token'] ?? null;

        if (! is_string($accessToken) || $accessToken === '') {
            throw new GoogleAdsApiException('Google 未回傳存取權杖，請重新連結帳戶。');
        }

        return $accessToken;
    }

    public function revokeRefreshToken(string $refreshToken): void
    {
        $response = Http::asForm()
            ->timeout(10)
            ->post('https://oauth2.googleapis.com/revoke', ['token' => $refreshToken]);
        $tokenWasAlreadyRevoked = $response->status() === 400
            && $response->json('error') === 'invalid_token';

        if (! $response->successful() && ! $tokenWasAlreadyRevoked) {
            throw new GoogleAdsApiException('Google 尚未確認授權撤銷，連線仍保留，請稍後再試。');
        }
    }

    /** @return array{customer_id: string, customer_name: string, currency_code: string} */
    public function customerDetails(
        string $accessToken,
        string $customerId,
        ?string $loginCustomerId = null,
    ): array {
        $this->validateCustomerIds($customerId, $loginCustomerId);
        $payload = $this->googleAdsRequest(
            $accessToken,
            $customerId,
            'googleAds:search',
            'SELECT customer.id, customer.descriptive_name, customer.currency_code FROM customer LIMIT 1',
            $loginCustomerId,
        );
        $row = $payload['results'][0] ?? null;
        $customer = is_array($row) ? ($row['customer'] ?? null) : null;

        if (! is_array($customer)
            || (string) ($customer['id'] ?? '') !== $customerId
            || ! is_string($customer['descriptiveName'] ?? null)
            || trim($customer['descriptiveName']) === ''
            || ! is_string($customer['currencyCode'] ?? null)
            || preg_match('/^[A-Z]{3}$/', $customer['currencyCode']) !== 1) {
            throw new GoogleAdsApiException('Google Ads 未回傳有效的客戶資料；請確認客戶 ID、管理員 ID 與帳戶讀取權限。');
        }

        return [
            'customer_id' => $customerId,
            'customer_name' => trim($customer['descriptiveName']),
            'currency_code' => $customer['currencyCode'],
        ];
    }

    /** @return list<array{campaign_id: string, campaign_name: string, status: string, channel_type: string}> */
    public function campaigns(
        string $accessToken,
        string $customerId,
        ?string $loginCustomerId = null,
    ): array {
        $this->validateCustomerIds($customerId, $loginCustomerId);
        $query = "SELECT customer.id, campaign.id, campaign.name, campaign.status, campaign.advertising_channel_type FROM campaign WHERE campaign.status != 'REMOVED' ORDER BY campaign.name LIMIT 500";
        $payload = $this->googleAdsRequest(
            $accessToken,
            $customerId,
            'googleAds:searchStream',
            $query,
            $loginCustomerId,
        );
        $campaigns = [];

        foreach ($this->rowsFromStream($payload) as $row) {
            $customer = $row['customer'] ?? null;
            $campaign = $row['campaign'] ?? null;
            $campaignId = is_array($campaign) ? ($campaign['id'] ?? null) : null;
            $campaignName = is_array($campaign) ? ($campaign['name'] ?? null) : null;
            $status = is_array($campaign) ? ($campaign['status'] ?? null) : null;
            $channelType = is_array($campaign) ? ($campaign['advertisingChannelType'] ?? null) : null;

            if (! is_array($customer)
                || (string) ($customer['id'] ?? '') !== $customerId
                || (! is_string($campaignId) && ! is_int($campaignId))
                || preg_match('/^\d{5,32}$/', (string) $campaignId) !== 1
                || ! is_string($campaignName)
                || trim($campaignName) === ''
                || ! is_string($status)
                || preg_match('/^[A-Z][A-Z0-9_]{0,49}$/', $status) !== 1
                || ! is_string($channelType)
                || preg_match('/^[A-Z][A-Z0-9_]{0,49}$/', $channelType) !== 1) {
                throw new GoogleAdsApiException('Google Ads API 回傳不同客戶或格式無效的活動資料。');
            }

            $campaigns[] = [
                'campaign_id' => (string) $campaignId,
                'campaign_name' => trim($campaignName),
                'status' => $status,
                'channel_type' => $channelType,
            ];
        }

        usort($campaigns, static function (array $first, array $second): int {
            $nameOrder = strcasecmp($first['campaign_name'], $second['campaign_name']);

            return $nameOrder !== 0
                ? $nameOrder
                : strcmp($first['campaign_id'], $second['campaign_id']);
        });

        return $campaigns;
    }

    /**
     * @return array{
     *     campaign_id: string,
     *     campaign_name: string,
     *     campaign_channel_type: string|null,
     *     spend_amount: string,
     *     impressions: int,
     *     clicks: int,
     *     conversions: string,
     *     conversions_value: string,
     *     conversion_actions: list<array{conversion_action_id: string, conversion_action_name: string, conversions: string, conversion_value: string}>,
     *     currency_code: string,
     *     daily_metrics: list<array{
     *         date: string,
     *         spend_amount: string,
     *         impressions: int,
     *         clicks: int,
     *         conversions: string,
     *         conversions_value: string,
     *         search_impression_share?: float|null,
     *         search_budget_lost_impression_share?: float|null,
     *         search_rank_lost_impression_share?: float|null
     *     }>,
     *     daily_metrics_status: 'complete'|'partial'|'unsupported',
     *     daily_metrics_coverage_start_on: string|null,
     *     daily_metrics_coverage_end_on: string|null
     * }
     */
    public function campaignInsights(
        string $accessToken,
        string $customerId,
        string $campaignId,
        string $startsOn,
        string $endsOn,
        string $accountCurrency,
        ?string $loginCustomerId = null,
    ): array {
        $this->validateCustomerIds($customerId, $loginCustomerId);

        if (preg_match('/^\d{5,32}$/', $campaignId) !== 1) {
            throw new GoogleAdsApiException('Google Ads 活動 ID 格式無效，請輸入 5 至 32 位數字。');
        }

        if (! $this->isCalendarDate($startsOn) || ! $this->isCalendarDate($endsOn) || $startsOn > $endsOn) {
            throw new GoogleAdsApiException('Google Ads 活動日期區間無效，請重新設定開始日與結束日。');
        }

        $minimumSupportedDate = $this->minimumSupportedDate();
        $hasHistoricalRange = $startsOn < $minimumSupportedDate;
        $returnedCurrency = $accountCurrency;
        $campaignChannelType = null;

        if ($hasHistoricalRange) {
            $cutoffMonth = CarbonImmutable::parse($minimumSupportedDate)->startOfMonth();
            $cutoffMonthEnd = $cutoffMonth->endOfMonth();
            $endsBeforeCutoff = $endsOn < $minimumSupportedDate;
            $endsBeforeCutoffMonthEnd = $endsOn < $cutoffMonthEnd->toDateString();

            if (substr($startsOn, 8, 2) !== '01'
                || ($endsBeforeCutoff && $endsOn !== CarbonImmutable::parse($endsOn)->endOfMonth()->toDateString())
                || (! $endsBeforeCutoff && $endsBeforeCutoffMonthEnd)) {
                throw new GoogleAdsApiException('Google Ads 較舊區間只能精確讀取完整月份，請將舊資料的開始日設為月初，結束日設為月底。');
            }

            $historicalEndMonth = $endsBeforeCutoff
                ? CarbonImmutable::parse($endsOn)->startOfMonth()
                : $cutoffMonth;
            $metricRows = $this->campaignMetricRows(
                $accessToken,
                $customerId,
                $campaignId,
                'month',
                CarbonImmutable::parse($startsOn)->startOfMonth()->toDateString(),
                $historicalEndMonth->toDateString(),
                $loginCustomerId,
                true,
            );

            if ($endsOn > $cutoffMonthEnd->toDateString()) {
                array_push($metricRows, ...$this->campaignMetricRows(
                    $accessToken,
                    $customerId,
                    $campaignId,
                    'date',
                    $cutoffMonthEnd->addDay()->toDateString(),
                    $endsOn,
                    $loginCustomerId,
                    true,
                ));
            }

            $aggregate = $this->aggregateCampaignMetricRows(
                $metricRows,
                $customerId,
                $campaignId,
            );
            $campaignName = $aggregate['campaign_name'] ?? $campaignId;
            $campaignChannelType = $aggregate['campaign_channel_type'];
            $metrics = $aggregate['metrics'];
            $returnedCurrency = $aggregate['currency_code'] ?? $accountCurrency;
        } else {
            $metricRows = $this->campaignMetricRows(
                $accessToken,
                $customerId,
                $campaignId,
                'date',
                $startsOn,
                $endsOn,
                $loginCustomerId,
                false,
            );
            $row = $metricRows[0] ?? null;

            if (! is_array($row)) {
                $campaignName = $campaignId;
                $metrics = [
                    'costMicros' => '0',
                    'impressions' => 0,
                    'clicks' => 0,
                    'conversions' => '0.0000',
                    'conversionsValue' => '0.0000',
                ];
            } else {
                $campaign = $row['campaign'] ?? null;
                $customer = $row['customer'] ?? null;
                $metrics = $row['metrics'] ?? null;
                $returnedCurrency = is_array($customer) ? ($customer['currencyCode'] ?? null) : null;

                if (! is_array($campaign)
                    || (string) ($campaign['id'] ?? '') !== $campaignId
                    || ! is_string($campaign['name'] ?? null)
                    || trim($campaign['name']) === ''
                    || ! is_array($customer)
                    || (string) ($customer['id'] ?? '') !== $customerId
                    || ! is_string($returnedCurrency)
                    || preg_match('/^[A-Z]{3}$/', $returnedCurrency) !== 1
                    || ! is_array($metrics)) {
                    throw new GoogleAdsApiException('Google Ads API 回傳不同帳戶或活動的資料，未儲存讀值。');
                }

                $campaignName = trim($campaign['name']);
                $campaignChannelType = $this->campaignChannelType($campaign['advertisingChannelType'] ?? null);
            }
        }

        $dailyMetrics = $this->dailyCampaignMetrics(
            $accessToken,
            $customerId,
            $campaignId,
            $startsOn,
            $endsOn,
            $loginCustomerId,
            $returnedCurrency,
            $campaignChannelType,
        );
        $conversionActions = $this->conversionActionInsights(
            $accessToken,
            $customerId,
            $campaignId,
            $startsOn,
            $endsOn,
            $loginCustomerId,
            $minimumSupportedDate,
        );

        return [
            'campaign_id' => $campaignId,
            'campaign_name' => $campaignName,
            'campaign_channel_type' => $campaignChannelType,
            'spend_amount' => $this->microsToDecimal($metrics['costMicros'] ?? null),
            'impressions' => $this->nonNegativeInteger($metrics['impressions'] ?? null),
            'clicks' => $this->nonNegativeInteger($metrics['clicks'] ?? null),
            'conversions' => $this->decimalString($metrics['conversions'] ?? null, false),
            'conversions_value' => $this->decimalString($metrics['conversionsValue'] ?? null, true),
            'conversion_actions' => $conversionActions,
            'currency_code' => $returnedCurrency,
            ...$dailyMetrics,
        ];
    }

    /**
     * @return array{
     *     daily_metrics: list<array{
     *         date: string,
     *         spend_amount: string,
     *         impressions: int,
     *         clicks: int,
     *         conversions: string,
     *         conversions_value: string,
     *         search_impression_share?: float|null,
     *         search_budget_lost_impression_share?: float|null,
     *         search_rank_lost_impression_share?: float|null
     *     }>,
     *     daily_metrics_status: 'complete'|'partial'|'unsupported',
     *     daily_metrics_coverage_start_on: string|null,
     *     daily_metrics_coverage_end_on: string|null
     * }
     */
    private function dailyCampaignMetrics(
        string $accessToken,
        string $customerId,
        string $campaignId,
        string $startsOn,
        string $endsOn,
        ?string $loginCustomerId,
        string $expectedCurrency,
        ?string $campaignChannelType,
    ): array {
        $minimumSupportedDate = $this->minimumSupportedDate();

        if ($endsOn < $minimumSupportedDate) {
            return [
                'daily_metrics' => [],
                'daily_metrics_status' => 'unsupported',
                'daily_metrics_coverage_start_on' => null,
                'daily_metrics_coverage_end_on' => null,
            ];
        }

        $coverageStartOn = $startsOn < $minimumSupportedDate ? $minimumSupportedDate : $startsOn;
        $status = $coverageStartOn === $startsOn ? 'complete' : 'partial';
        $searchMetricsFields = in_array($campaignChannelType, ['SEARCH', 'SHOPPING'], true)
            ? ', metrics.search_impression_share, metrics.search_budget_lost_impression_share, metrics.search_rank_lost_impression_share'
            : '';
        $query = "SELECT customer.id, customer.currency_code, campaign.id, segments.date, metrics.cost_micros, metrics.impressions, metrics.clicks, metrics.conversions, metrics.conversions_value{$searchMetricsFields} FROM campaign WHERE campaign.id = {$campaignId} AND segments.date BETWEEN '{$coverageStartOn}' AND '{$endsOn}' ORDER BY segments.date ASC";
        $payload = $this->googleAdsRequest(
            $accessToken,
            $customerId,
            'googleAds:searchStream',
            $query,
            $loginCustomerId,
        );
        $dailyMetricsByDate = [];

        foreach ($this->dailyRowsFromStream($payload) as $row) {
            $campaign = $row['campaign'] ?? null;
            $customer = $row['customer'] ?? null;
            $segments = $row['segments'] ?? null;
            $metrics = $row['metrics'] ?? null;
            $date = is_array($segments) ? ($segments['date'] ?? null) : null;
            $currencyCode = is_array($customer) ? ($customer['currencyCode'] ?? null) : null;

            if (! is_array($campaign)
                || (string) ($campaign['id'] ?? '') !== $campaignId
                || ! is_array($customer)
                || (string) ($customer['id'] ?? '') !== $customerId
                || ! is_string($currencyCode)
                || preg_match('/^[A-Z]{3}$/', $currencyCode) !== 1
                || $currencyCode !== $expectedCurrency
                || ! is_string($date)
                || ! $this->isCalendarDate($date)
                || $date < $coverageStartOn
                || $date > $endsOn
                || ! is_array($metrics)) {
                throw new GoogleAdsApiException('Google Ads API 回傳不同帳戶、活動或格式無效的逐日資料，未儲存讀值。');
            }

            if (isset($dailyMetricsByDate[$date])) {
                throw new GoogleAdsApiException('Google Ads API 回傳重複日期的逐日資料，未儲存讀值。');
            }

            $dailyMetric = [
                'date' => $date,
                'spend_amount' => $this->microsToDecimal($metrics['costMicros'] ?? null),
                'impressions' => $this->nonNegativeInteger($metrics['impressions'] ?? null),
                'clicks' => $this->nonNegativeInteger($metrics['clicks'] ?? null),
                'conversions' => $this->decimalString($metrics['conversions'] ?? null, false),
                'conversions_value' => $this->decimalString($metrics['conversionsValue'] ?? null, true),
            ];

            if (in_array($campaignChannelType, ['SEARCH', 'SHOPPING'], true)) {
                $dailyMetric += [
                    'search_impression_share' => $this->searchShareMetric(
                        $metrics['searchImpressionShare'] ?? null,
                        '搜尋曝光比重',
                        0.1,
                        1.0,
                        0.0999,
                        true,
                    ),
                    'search_budget_lost_impression_share' => $this->searchShareMetric(
                        $metrics['searchBudgetLostImpressionShare'] ?? null,
                        '預算流失曝光比重',
                        0.0,
                        0.9,
                        0.9001,
                        false,
                    ),
                    'search_rank_lost_impression_share' => $this->searchShareMetric(
                        $metrics['searchRankLostImpressionShare'] ?? null,
                        '排名流失曝光比重',
                        0.0,
                        0.9,
                        0.9001,
                        false,
                    ),
                ];
            }

            $dailyMetricsByDate[$date] = $dailyMetric;
        }

        ksort($dailyMetricsByDate);

        return [
            'daily_metrics' => array_values($dailyMetricsByDate),
            'daily_metrics_status' => $status,
            'daily_metrics_coverage_start_on' => $coverageStartOn,
            'daily_metrics_coverage_end_on' => $endsOn,
        ];
    }

    /** @return list<array{conversion_action_id: string, conversion_action_name: string, conversions: string, conversion_value: string}> */
    private function conversionActionInsights(
        string $accessToken,
        string $customerId,
        string $campaignId,
        string $startsOn,
        string $endsOn,
        ?string $loginCustomerId,
        string $minimumSupportedDate,
    ): array {
        $rows = [];

        if ($startsOn < $minimumSupportedDate) {
            $cutoffMonth = CarbonImmutable::parse($minimumSupportedDate)->startOfMonth();
            $cutoffMonthEnd = $cutoffMonth->endOfMonth();
            $endsBeforeCutoff = $endsOn < $minimumSupportedDate;
            $historicalEndMonth = $endsBeforeCutoff
                ? CarbonImmutable::parse($endsOn)->startOfMonth()
                : $cutoffMonth;
            array_push($rows, ...$this->conversionActionRows(
                $accessToken,
                $customerId,
                $campaignId,
                'month',
                CarbonImmutable::parse($startsOn)->startOfMonth()->toDateString(),
                $historicalEndMonth->toDateString(),
                $loginCustomerId,
                true,
            ));

            if ($endsOn > $cutoffMonthEnd->toDateString()) {
                array_push($rows, ...$this->conversionActionRows(
                    $accessToken,
                    $customerId,
                    $campaignId,
                    'date',
                    $cutoffMonthEnd->addDay()->toDateString(),
                    $endsOn,
                    $loginCustomerId,
                    true,
                ));
            }
        } else {
            $rows = $this->conversionActionRows(
                $accessToken,
                $customerId,
                $campaignId,
                'date',
                $startsOn,
                $endsOn,
                $loginCustomerId,
                false,
            );
        }

        $conversionActions = [];
        $conversionActionsById = [];

        foreach ($rows as $row) {
            $campaign = $row['campaign'] ?? null;
            $customer = $row['customer'] ?? null;
            $segments = $row['segments'] ?? null;
            $metrics = $row['metrics'] ?? null;
            $conversionActionResourceName = is_array($segments) ? ($segments['conversionAction'] ?? null) : null;
            $conversionActionName = is_array($segments) ? ($segments['conversionActionName'] ?? null) : null;
            $expectedResourcePrefix = 'customers/'.$customerId.'/conversionActions/';

            if (! is_array($campaign)
                || (string) ($campaign['id'] ?? '') !== $campaignId
                || ! is_array($customer)
                || (string) ($customer['id'] ?? '') !== $customerId
                || ! is_string($conversionActionResourceName)
                || preg_match('/^'.preg_quote($expectedResourcePrefix, '/').'\d{1,32}$/', $conversionActionResourceName) !== 1
                || ! is_string($conversionActionName)
                || trim($conversionActionName) === ''
                || ! is_array($metrics)) {
                throw new GoogleAdsApiException('Google Ads API 回傳不同帳戶、活動或格式無效的轉換動作資料，未儲存讀值。');
            }

            $conversionActionId = substr($conversionActionResourceName, strlen($expectedResourcePrefix));
            $normalizedActionName = trim($conversionActionName);

            if (isset($conversionActionsById[$conversionActionId])
                && $conversionActionsById[$conversionActionId]['conversion_action_name'] !== $normalizedActionName) {
                throw new GoogleAdsApiException('Google Ads API 回傳名稱不一致的轉換動作資料，未儲存讀值。');
            }

            $conversionActionsById[$conversionActionId] ??= [
                'conversion_action_id' => $conversionActionId,
                'conversion_action_name' => $normalizedActionName,
                'conversions_units' => '0',
                'conversion_value_units' => '0',
            ];
            $conversionActionsById[$conversionActionId]['conversions_units'] = $this->addSignedIntegerStrings(
                $conversionActionsById[$conversionActionId]['conversions_units'],
                $this->scaledDecimalUnits($metrics['conversions'] ?? null, false),
            );
            $conversionActionsById[$conversionActionId]['conversion_value_units'] = $this->addSignedIntegerStrings(
                $conversionActionsById[$conversionActionId]['conversion_value_units'],
                $this->scaledDecimalUnits($metrics['conversionsValue'] ?? null, true),
            );
        }

        foreach ($conversionActionsById as $conversionAction) {
            $conversionActions[] = [
                'conversion_action_id' => $conversionAction['conversion_action_id'],
                'conversion_action_name' => $conversionAction['conversion_action_name'],
                'conversions' => $this->scaledUnitsToDecimal($conversionAction['conversions_units']),
                'conversion_value' => $this->scaledUnitsToDecimal($conversionAction['conversion_value_units']),
            ];
        }

        usort($conversionActions, static function (array $first, array $second): int {
            $nameOrder = strcasecmp($first['conversion_action_name'], $second['conversion_action_name']);

            return $nameOrder !== 0
                ? $nameOrder
                : strcmp($first['conversion_action_id'], $second['conversion_action_id']);
        });

        return $conversionActions;
    }

    /** @return list<array<string, mixed>> */
    private function campaignMetricRows(
        string $accessToken,
        string $customerId,
        string $campaignId,
        string $segment,
        string $startsOn,
        string $endsOn,
        ?string $loginCustomerId,
        bool $selectSegment,
    ): array {
        $segmentField = $segment === 'month' ? 'segments.month' : 'segments.date';
        $selectedSegmentField = $selectSegment ? $segmentField.', ' : '';
        $orderBySegment = $selectSegment ? " ORDER BY {$segmentField} ASC" : '';
        $querySegmentField = $selectSegment ? $segmentField : 'segments.date';
        $query = "SELECT campaign.id, campaign.name, customer.id, customer.currency_code, campaign.advertising_channel_type, {$selectedSegmentField}metrics.cost_micros, metrics.impressions, metrics.clicks, metrics.conversions, metrics.conversions_value FROM campaign WHERE campaign.id = {$campaignId} AND {$querySegmentField} BETWEEN '{$startsOn}' AND '{$endsOn}'{$orderBySegment}";
        $payload = $this->googleAdsRequest(
            $accessToken,
            $customerId,
            'googleAds:searchStream',
            $query,
            $loginCustomerId,
        );

        return $selectSegment
            ? $this->strictRowsFromStream($payload)
            : $this->rowsFromStream($payload);
    }

    /** @return list<array<string, mixed>> */
    private function conversionActionRows(
        string $accessToken,
        string $customerId,
        string $campaignId,
        string $segment,
        string $startsOn,
        string $endsOn,
        ?string $loginCustomerId,
        bool $selectSegment,
    ): array {
        $segmentField = $segment === 'month' ? 'segments.month' : 'segments.date';
        $selectedSegmentField = $selectSegment ? $segmentField.', ' : '';
        $querySegmentField = $selectSegment ? $segmentField : 'segments.date';
        $query = "SELECT customer.id, campaign.id, {$selectedSegmentField}segments.conversion_action, segments.conversion_action_name, metrics.conversions, metrics.conversions_value FROM campaign WHERE campaign.id = {$campaignId} AND {$querySegmentField} BETWEEN '{$startsOn}' AND '{$endsOn}'";
        $payload = $this->googleAdsRequest(
            $accessToken,
            $customerId,
            'googleAds:searchStream',
            $query,
            $loginCustomerId,
        );

        return $selectSegment
            ? $this->strictRowsFromStream($payload)
            : $this->rowsFromStream($payload);
    }

    /** @return list<array<string, mixed>> */
    private function strictRowsFromStream(array $payload): array
    {
        if ($payload === []) {
            return [];
        }

        $batches = array_is_list($payload) ? $payload : [$payload];
        $rows = [];

        foreach ($batches as $batch) {
            if (! is_array($batch) || ! is_array($batch['results'] ?? null)) {
                throw new GoogleAdsApiException('Google Ads API 回傳格式無效的分段資料，未儲存讀值。');
            }

            foreach ($batch['results'] as $row) {
                if (! is_array($row)) {
                    throw new GoogleAdsApiException('Google Ads API 回傳格式無效的分段資料，未儲存讀值。');
                }

                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{campaign_name: string|null, campaign_channel_type: string|null, currency_code: string|null, metrics: array{costMicros: string, impressions: int, clicks: int, conversions: string, conversionsValue: string}}
     */
    private function aggregateCampaignMetricRows(
        array $rows,
        string $customerId,
        string $campaignId,
    ): array {
        $campaignName = null;
        $campaignChannelType = null;
        $currencyCode = null;
        $totals = [
            'cost_micros' => '0',
            'impressions' => 0,
            'clicks' => 0,
            'conversions_units' => '0',
            'conversions_value_units' => '0',
        ];

        foreach ($rows as $row) {
            $campaign = $row['campaign'] ?? null;
            $customer = $row['customer'] ?? null;
            $segments = $row['segments'] ?? null;
            $metrics = $row['metrics'] ?? null;
            $rowCurrencyCode = is_array($customer) ? ($customer['currencyCode'] ?? null) : null;
            $rowCampaignName = is_array($campaign) ? ($campaign['name'] ?? null) : null;
            $rowCampaignChannelType = is_array($campaign)
                ? $this->campaignChannelType($campaign['advertisingChannelType'] ?? null)
                : null;
            $month = is_array($segments) ? ($segments['month'] ?? null) : null;
            $date = is_array($segments) ? ($segments['date'] ?? null) : null;
            $hasValidSegment = (is_string($month)
                    && $this->isCalendarDate($month)
                    && substr($month, 8, 2) === '01')
                || (is_string($date) && $this->isCalendarDate($date));

            if (! is_array($campaign)
                || (string) ($campaign['id'] ?? '') !== $campaignId
                || ! $hasValidSegment
                || ! is_string($rowCampaignName)
                || trim($rowCampaignName) === ''
                || ! is_array($customer)
                || (string) ($customer['id'] ?? '') !== $customerId
                || ! is_string($rowCurrencyCode)
                || preg_match('/^[A-Z]{3}$/', $rowCurrencyCode) !== 1
                || (is_array($campaign)
                    && array_key_exists('advertisingChannelType', $campaign)
                    && $rowCampaignChannelType === null)
                || ! is_array($metrics)) {
                throw new GoogleAdsApiException('Google Ads API 回傳不同帳戶或活動的資料，未儲存讀值。');
            }

            if (($campaignName !== null && $campaignName !== trim($rowCampaignName))
                || ($currencyCode !== null && $currencyCode !== $rowCurrencyCode)
                || ($campaignChannelType !== null
                    && $rowCampaignChannelType !== null
                    && $campaignChannelType !== $rowCampaignChannelType)) {
                throw new GoogleAdsApiException('Google Ads API 回傳名稱或幣別不一致的活動資料，未儲存讀值。');
            }

            $campaignName = trim($rowCampaignName);
            $campaignChannelType ??= $rowCampaignChannelType;
            $currencyCode = $rowCurrencyCode;
            $totals['cost_micros'] = $this->addUnsignedIntegerStrings(
                $totals['cost_micros'],
                $this->unsignedIntegerString($metrics['costMicros'] ?? null, '花費'),
            );
            $totals['impressions'] = $this->addNonNegativeIntegers(
                $totals['impressions'],
                $this->nonNegativeInteger($metrics['impressions'] ?? null),
            );
            $totals['clicks'] = $this->addNonNegativeIntegers(
                $totals['clicks'],
                $this->nonNegativeInteger($metrics['clicks'] ?? null),
            );
            $totals['conversions_units'] = $this->addSignedIntegerStrings(
                $totals['conversions_units'],
                $this->scaledDecimalUnits($metrics['conversions'] ?? null, false),
            );
            $totals['conversions_value_units'] = $this->addSignedIntegerStrings(
                $totals['conversions_value_units'],
                $this->scaledDecimalUnits($metrics['conversionsValue'] ?? null, true),
            );
        }

        return [
            'campaign_name' => $campaignName,
            'campaign_channel_type' => $campaignChannelType,
            'currency_code' => $currencyCode,
            'metrics' => [
                'costMicros' => $totals['cost_micros'],
                'impressions' => $totals['impressions'],
                'clicks' => $totals['clicks'],
                'conversions' => $this->scaledUnitsToDecimal($totals['conversions_units']),
                'conversionsValue' => $this->scaledUnitsToDecimal($totals['conversions_value_units']),
            ],
        ];
    }

    private function minimumSupportedDate(): string
    {
        return CarbonImmutable::now(config('app.timezone'))->subMonthsNoOverflow(37)->toDateString();
    }

    private function unsignedIntegerString(mixed $value, string $metricName): string
    {
        if ((! is_string($value) && ! is_int($value)) || ! ctype_digit((string) $value)) {
            throw new GoogleAdsApiException('Google Ads API 回傳的'.$metricName.'格式無效。');
        }

        $normalizedValue = ltrim((string) $value, '0');

        return $normalizedValue === '' ? '0' : $normalizedValue;
    }

    private function addNonNegativeIntegers(int $first, int $second): int
    {
        if ($second > PHP_INT_MAX - $first) {
            throw new GoogleAdsApiException('Google Ads API 回傳超出可儲存範圍的整數指標。');
        }

        return $first + $second;
    }

    private function scaledDecimalUnits(mixed $value, bool $allowNegative): string
    {
        $decimal = $this->decimalString($value, $allowNegative);
        $isNegative = str_starts_with($decimal, '-');
        $unsignedDecimal = ltrim($decimal, '-');
        [$integerPart, $fractionalPart] = explode('.', $unsignedDecimal);
        $units = ltrim($integerPart.$fractionalPart, '0');
        $units = $units === '' ? '0' : $units;

        return $isNegative && $units !== '0' ? '-'.$units : $units;
    }

    private function scaledUnitsToDecimal(string $units): string
    {
        $isNegative = str_starts_with($units, '-');
        $unsignedUnits = ltrim($units, '-');
        $unsignedUnits = str_pad($unsignedUnits, 5, '0', STR_PAD_LEFT);
        $decimal = substr($unsignedUnits, 0, -4).'.'.substr($unsignedUnits, -4);

        return $isNegative && $unsignedUnits !== '00000' ? '-'.$decimal : $decimal;
    }

    private function addSignedIntegerStrings(string $first, string $second): string
    {
        $firstNegative = str_starts_with($first, '-');
        $secondNegative = str_starts_with($second, '-');
        $firstMagnitude = ltrim($first, '-');
        $secondMagnitude = ltrim($second, '-');

        if ($firstNegative === $secondNegative) {
            $sum = $this->addUnsignedIntegerStrings($firstMagnitude, $secondMagnitude);

            return $firstNegative && $sum !== '0' ? '-'.$sum : $sum;
        }

        $comparison = $this->compareUnsignedIntegerStrings($firstMagnitude, $secondMagnitude);

        if ($comparison === 0) {
            return '0';
        }

        if ($comparison > 0) {
            $difference = $this->subtractUnsignedIntegerStrings($firstMagnitude, $secondMagnitude);

            return $firstNegative ? '-'.$difference : $difference;
        }

        $difference = $this->subtractUnsignedIntegerStrings($secondMagnitude, $firstMagnitude);

        return $secondNegative ? '-'.$difference : $difference;
    }

    private function addUnsignedIntegerStrings(string $first, string $second): string
    {
        $maxLength = max(strlen($first), strlen($second));
        $position = $maxLength - 1;
        $carry = 0;
        $sum = '';

        for (; $position >= 0; $position--) {
            $firstIndex = $position - ($maxLength - strlen($first));
            $secondIndex = $position - ($maxLength - strlen($second));
            $firstDigit = $firstIndex >= 0 ? (int) $first[$firstIndex] : 0;
            $secondDigit = $secondIndex >= 0 ? (int) $second[$secondIndex] : 0;
            $digitSum = $firstDigit + $secondDigit + $carry;
            $sum = (string) ($digitSum % 10).$sum;
            $carry = intdiv($digitSum, 10);
        }

        if ($carry > 0) {
            $sum = (string) $carry.$sum;
        }

        $normalizedSum = ltrim($sum, '0');

        return $normalizedSum === '' ? '0' : $normalizedSum;
    }

    private function compareUnsignedIntegerStrings(string $first, string $second): int
    {
        $first = ltrim($first, '0') ?: '0';
        $second = ltrim($second, '0') ?: '0';

        return strlen($first) <=> strlen($second) ?: strcmp($first, $second);
    }

    private function subtractUnsignedIntegerStrings(string $larger, string $smaller): string
    {
        $borrow = 0;
        $difference = '';
        $largerLength = strlen($larger);
        $smallerLength = strlen($smaller);

        for ($position = 1; $position <= $largerLength; $position++) {
            $largerDigit = (int) $larger[$largerLength - $position] - $borrow;
            $smallerDigit = (int) ($smaller[$smallerLength - $position] ?? '0');

            if ($largerDigit < $smallerDigit) {
                $largerDigit += 10;
                $borrow = 1;
            } else {
                $borrow = 0;
            }

            $difference = (string) ($largerDigit - $smallerDigit).$difference;
        }

        $normalizedDifference = ltrim($difference, '0');

        return $normalizedDifference === '' ? '0' : $normalizedDifference;
    }

    private function validateCustomerIds(string $customerId, ?string $loginCustomerId): void
    {
        if (preg_match('/^\d{10}$/', $customerId) !== 1
            || ($loginCustomerId !== null && preg_match('/^\d{10}$/', $loginCustomerId) !== 1)) {
            throw new GoogleAdsApiException('Google Ads 客戶 ID 格式無效，請確認客戶 ID 與管理員客戶 ID。');
        }
    }

    private function isCalendarDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date instanceof \DateTimeImmutable && $date->format('Y-m-d') === $value;
    }

    /** @return array<string, mixed> */
    private function googleAdsRequest(
        string $accessToken,
        string $customerId,
        string $method,
        string $query,
        ?string $loginCustomerId,
    ): array {
        $request = Http::withToken($accessToken)
            ->acceptJson()
            ->timeout(20);

        if ($loginCustomerId !== null) {
            $request->withHeaders(['login-customer-id' => $loginCustomerId]);
        }

        $response = $request->post($this->apiUrl('customers/'.$customerId.'/'.$method), ['query' => $query]);

        return $this->successfulPayload($response, 'Google Ads API');
    }

    /** @return list<array<string, mixed>> */
    private function rowsFromStream(array $payload): array
    {
        $rows = [];

        if (is_array($payload['results'] ?? null)) {
            $rows = $payload['results'];
        } else {
            foreach ($payload as $batch) {
                if (is_array($batch) && is_array($batch['results'] ?? null)) {
                    array_push($rows, ...$batch['results']);
                }
            }
        }

        return array_values(array_filter($rows, 'is_array'));
    }

    /** @return list<array<string, mixed>> */
    private function dailyRowsFromStream(array $payload): array
    {
        if (array_is_list($payload)) {
            $batches = $payload;
        } elseif (array_key_exists('results', $payload)) {
            $batches = [$payload];
        } else {
            throw new GoogleAdsApiException('Google Ads API 回傳格式無效的逐日資料，未儲存讀值。');
        }

        $rows = [];

        foreach ($batches as $batch) {
            if (! is_array($batch) || ! is_array($batch['results'] ?? null)) {
                throw new GoogleAdsApiException('Google Ads API 回傳格式無效的逐日資料，未儲存讀值。');
            }

            foreach ($batch['results'] as $row) {
                if (! is_array($row)) {
                    throw new GoogleAdsApiException('Google Ads API 回傳格式無效的逐日資料，未儲存讀值。');
                }

                $rows[] = $row;
            }
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    private function successfulPayload(Response $response, string $serviceName): array
    {
        if (! $response->successful()) {
            $message = match ($response->status()) {
                401 => 'Google Ads 授權已失效，請重新連結帳戶。',
                403 => 'Google Ads 專案或帳戶沒有 API 讀取權限；請確認 Google Cloud 專案已啟用 Google Ads API 並取得適用存取層級。',
                429 => 'Google Ads API 配額已用盡，請稍後再同步。',
                default => $serviceName.' 暫時無法讀取，請確認客戶 ID、管理員 ID 與 API 設定。',
            };

            throw new GoogleAdsApiException($message);
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            throw new GoogleAdsApiException($serviceName.' 回傳格式無效，請稍後再試。');
        }

        return $payload;
    }

    private function nonNegativeInteger(mixed $value): int
    {
        if (! is_string($value) && ! is_int($value)) {
            throw new GoogleAdsApiException('Google Ads API 回傳的整數格式無效。');
        }

        $validated = filter_var($value, FILTER_VALIDATE_INT);

        if ($validated === false || $validated < 0) {
            throw new GoogleAdsApiException('Google Ads API 回傳的整數格式無效。');
        }

        return $validated;
    }

    private function microsToDecimal(mixed $value): string
    {
        if ((! is_string($value) && ! is_int($value)) || ! ctype_digit((string) $value)) {
            throw new GoogleAdsApiException('Google Ads API 回傳的花費格式無效。');
        }

        $micros = ltrim((string) $value, '0');
        $micros = $micros === '' ? '0' : $micros;
        $amountInTenThousandths = strlen($micros) > 2 ? substr($micros, 0, -2) : '0';
        $remainingMicros = (int) (strlen($micros) > 2 ? substr($micros, -2) : $micros);

        if ($remainingMicros >= 50) {
            $amountInTenThousandths = $this->incrementUnsignedIntegerString($amountInTenThousandths);
        }

        $amountInTenThousandths = str_pad($amountInTenThousandths, 5, '0', STR_PAD_LEFT);

        return substr($amountInTenThousandths, 0, -4).'.'.substr($amountInTenThousandths, -4);
    }

    private function incrementUnsignedIntegerString(string $value): string
    {
        for ($position = strlen($value) - 1; $position >= 0; $position--) {
            if ($value[$position] !== '9') {
                $value[$position] = (string) ((int) $value[$position] + 1);

                return $value;
            }

            $value[$position] = '0';
        }

        return '1'.$value;
    }

    private function decimalString(mixed $value, bool $allowNegative): string
    {
        if ((! is_string($value) && ! is_int($value) && ! is_float($value)) || ! is_numeric($value)) {
            throw new GoogleAdsApiException('Google Ads API 回傳的轉換指標格式無效。');
        }

        $number = (float) $value;

        if (! is_finite($number) || (! $allowNegative && $number < 0)) {
            throw new GoogleAdsApiException('Google Ads API 回傳的轉換指標格式無效。');
        }

        return PreciseDecimalFormatter::toFourDecimalPlaces($value);
    }

    private function campaignChannelType(mixed $value): ?string
    {
        return is_string($value)
            && preg_match('/^[A-Z][A-Z0-9_]{0,49}$/', $value) === 1
            && ! in_array($value, ['UNKNOWN', 'UNSPECIFIED'], true)
            ? $value
            : null;
    }

    private function searchShareMetric(
        mixed $value,
        string $metricName,
        float $minimum,
        float $maximum,
        float $cappedValue,
        bool $isLowerBound,
    ): ?float {
        if ($value === null) {
            return null;
        }

        if ((! is_string($value) && ! is_int($value) && ! is_float($value)) || ! is_numeric($value)) {
            throw new GoogleAdsApiException('Google Ads API 回傳的'.$metricName.'格式無效。');
        }

        $number = (float) $value;
        $isCappedValue = $number === $cappedValue;
        $isOutsideSupportedRange = $isLowerBound
            ? ($number < $minimum && ! $isCappedValue)
            : ($number > $maximum && ! $isCappedValue);

        if (! is_finite($number)
            || $number < 0
            || $number > 1
            || $isOutsideSupportedRange) {
            throw new GoogleAdsApiException('Google Ads API 回傳的'.$metricName.'超出有效範圍。');
        }

        return $number;
    }

    private function apiUrl(string $path): string
    {
        $version = config('services.google_ads.api_version');
        $version = is_string($version) && preg_match('/^v\d+$/', $version) === 1 ? $version : 'v26';

        return 'https://googleads.googleapis.com/'.$version.'/'.$path;
    }
}
