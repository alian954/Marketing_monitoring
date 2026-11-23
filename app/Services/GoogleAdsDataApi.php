<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class GoogleAdsDataApi
{
    private const DELIVERY_UNIT_REPORT_LIMIT = 200;

    private const DEVICE_REPORT_LIMIT = 20;

    private const AUCTION_INSIGHT_REPORT_LIMIT = 200;

    private const RESPONSIVE_SEARCH_AD_ASSET_REPORT_LIMIT = 200;

    private const PERFORMANCE_MAX_ASSET_REPORT_LIMIT = 200;

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
     *     status: 'complete'|'unsupported'|'no_complete_days',
     *     window_start_on: string|null,
     *     window_end_on: string|null,
     *     currency_code: string,
     *     truncated: bool,
     *     rows: list<array{search_term: string, ad_group_id: string, ad_group_name: string, impressions: int, clicks: int, spend_amount: string, conversions: string, conversions_value: string}>
     * }
     */
    public function campaignSearchTermInsights(
        string $accessToken,
        string $customerId,
        string $campaignId,
        string $startsOn,
        string $endsOn,
        string $accountCurrency,
        ?string $campaignChannelType,
        ?string $loginCustomerId = null,
    ): array {
        $this->validateCustomerIds($customerId, $loginCustomerId);

        if (preg_match('/^\d{5,32}$/', $campaignId) !== 1
            || ! $this->isCalendarDate($startsOn)
            || ! $this->isCalendarDate($endsOn)
            || $startsOn > $endsOn
            || preg_match('/^[A-Z]{3}$/', $accountCurrency) !== 1) {
            throw new GoogleAdsApiException('Google Ads 搜尋字詞報表設定無效，請確認活動、日期與幣別。');
        }

        if ($campaignChannelType !== 'SEARCH') {
            return $this->emptySearchTermReport('unsupported', $accountCurrency);
        }

        $today = CarbonImmutable::now(config('app.timezone'))->toDateString();
        $yesterday = CarbonImmutable::parse($today)->subDay()->toDateString();
        $minimumSupportedDate = $this->minimumSupportedDate();
        $windowEndOn = min($endsOn, $yesterday);

        if ($windowEndOn < $minimumSupportedDate) {
            return $this->emptySearchTermReport('unsupported', $accountCurrency);
        }

        if ($startsOn > $windowEndOn) {
            return $this->emptySearchTermReport('no_complete_days', $accountCurrency);
        }

        $thirtyDayStart = CarbonImmutable::parse($windowEndOn)->subDays(29)->toDateString();
        $windowStartOn = max($startsOn, $thirtyDayStart, $minimumSupportedDate);
        $query = "SELECT customer.id, customer.currency_code, campaign.id, ad_group.id, ad_group.name, search_term_view.search_term, metrics.cost_micros, metrics.impressions, metrics.clicks, metrics.conversions, metrics.conversions_value FROM search_term_view WHERE campaign.id = {$campaignId} AND segments.date BETWEEN '{$windowStartOn}' AND '{$windowEndOn}' ORDER BY metrics.cost_micros DESC, search_term_view.search_term ASC LIMIT 201";
        $payload = $this->googleAdsRequest(
            $accessToken,
            $customerId,
            'googleAds:searchStream',
            $query,
            $loginCustomerId,
        );
        $rawRows = $this->strictRowsFromStream($payload);

        if (count($rawRows) > 201) {
            throw new GoogleAdsApiException('Google Ads API 回傳超過搜尋字詞讀取上限，未儲存讀值。');
        }

        $rows = [];

        foreach ($rawRows as $row) {
            $campaign = $row['campaign'] ?? null;
            $customer = $row['customer'] ?? null;
            $adGroup = $row['adGroup'] ?? null;
            $searchTermView = $row['searchTermView'] ?? null;
            $metrics = $row['metrics'] ?? null;
            $rowCurrencyCode = is_array($customer) ? ($customer['currencyCode'] ?? null) : null;
            $adGroupId = is_array($adGroup) ? ($adGroup['id'] ?? null) : null;
            $adGroupName = is_array($adGroup) ? ($adGroup['name'] ?? null) : null;
            $searchTerm = is_array($searchTermView) ? ($searchTermView['searchTerm'] ?? null) : null;

            if (! is_array($campaign)
                || (string) ($campaign['id'] ?? '') !== $campaignId
                || ! is_array($customer)
                || (string) ($customer['id'] ?? '') !== $customerId
                || ! is_string($rowCurrencyCode)
                || $rowCurrencyCode !== $accountCurrency
                || (! is_string($adGroupId) && ! is_int($adGroupId))
                || preg_match('/^\d{1,32}$/', (string) $adGroupId) !== 1
                || ! is_string($adGroupName)
                || trim($adGroupName) === ''
                || strlen($adGroupName) > 1024
                || ! is_string($searchTerm)
                || trim($searchTerm) === ''
                || strlen($searchTerm) > 4096
                || ! is_array($metrics)) {
                throw new GoogleAdsApiException('Google Ads API 回傳不同帳戶、活動或格式無效的搜尋字詞資料，未儲存讀值。');
            }

            $rows[] = [
                'search_term' => $searchTerm,
                'ad_group_id' => (string) $adGroupId,
                'ad_group_name' => trim($adGroupName),
                'impressions' => $this->nonNegativeInteger($metrics['impressions'] ?? null),
                'clicks' => $this->nonNegativeInteger($metrics['clicks'] ?? null),
                'spend_amount' => $this->microsToDecimal($metrics['costMicros'] ?? null),
                'conversions' => $this->decimalString($metrics['conversions'] ?? null, false),
                'conversions_value' => $this->decimalString($metrics['conversionsValue'] ?? null, true),
            ];
        }

        return [
            'status' => 'complete',
            'window_start_on' => $windowStartOn,
            'window_end_on' => $windowEndOn,
            'currency_code' => $accountCurrency,
            'truncated' => count($rows) > 200,
            'rows' => array_slice($rows, 0, 200),
        ];
    }

    /**
     * @return array{
     *     status: 'complete'|'unsupported'|'no_complete_days',
     *     window_start_on: string|null,
     *     window_end_on: string|null,
     *     currency_code: string,
     *     truncated: bool,
     *     rows: list<array{keyword_text: string, match_type: string, criterion_status: string, criterion_id: string, ad_group_id: string, ad_group_name: string, impressions: int, clicks: int, spend_amount: string, conversions: string, conversions_value: string, search_impression_share: float|null, search_budget_lost_impression_share: float|null, search_rank_lost_impression_share: float|null}>
     * }
     */
    public function campaignKeywordInsights(
        string $accessToken,
        string $customerId,
        string $campaignId,
        string $startsOn,
        string $endsOn,
        string $accountCurrency,
        ?string $campaignChannelType,
        ?string $loginCustomerId = null,
    ): array {
        $this->validateCustomerIds($customerId, $loginCustomerId);

        if (preg_match('/^\d{5,32}$/', $campaignId) !== 1
            || ! $this->isCalendarDate($startsOn)
            || ! $this->isCalendarDate($endsOn)
            || $startsOn > $endsOn
            || preg_match('/^[A-Z]{3}$/', $accountCurrency) !== 1) {
            throw new GoogleAdsApiException('Google Ads 關鍵字報表設定無效，請確認活動、日期與幣別。');
        }

        if ($campaignChannelType !== 'SEARCH') {
            return $this->emptyKeywordReport('unsupported', $accountCurrency);
        }

        $today = CarbonImmutable::now(config('app.timezone'))->toDateString();
        $yesterday = CarbonImmutable::parse($today)->subDay()->toDateString();
        $minimumSupportedDate = $this->minimumSupportedDate();
        $windowEndOn = min($endsOn, $yesterday);

        if ($windowEndOn < $minimumSupportedDate) {
            return $this->emptyKeywordReport('unsupported', $accountCurrency);
        }

        if ($startsOn > $windowEndOn) {
            return $this->emptyKeywordReport('no_complete_days', $accountCurrency);
        }

        $thirtyDayStart = CarbonImmutable::parse($windowEndOn)->subDays(29)->toDateString();
        $windowStartOn = max($startsOn, $thirtyDayStart, $minimumSupportedDate);
        $query = "SELECT customer.id, customer.currency_code, campaign.id, ad_group.id, ad_group.name, ad_group_criterion.criterion_id, ad_group_criterion.keyword.text, ad_group_criterion.keyword.match_type, ad_group_criterion.status, metrics.cost_micros, metrics.impressions, metrics.clicks, metrics.conversions, metrics.conversions_value, metrics.search_impression_share, metrics.search_budget_lost_impression_share, metrics.search_rank_lost_impression_share FROM keyword_view WHERE campaign.id = {$campaignId} AND segments.date BETWEEN '{$windowStartOn}' AND '{$windowEndOn}' ORDER BY metrics.cost_micros DESC, ad_group_criterion.criterion_id ASC LIMIT 201";
        $payload = $this->googleAdsRequest(
            $accessToken,
            $customerId,
            'googleAds:searchStream',
            $query,
            $loginCustomerId,
        );
        $rawRows = $this->strictRowsFromStream($payload);

        if (count($rawRows) > 201) {
            throw new GoogleAdsApiException('Google Ads API 回傳超過關鍵字讀取上限，未儲存讀值。');
        }

        $rows = [];

        foreach ($rawRows as $row) {
            $campaign = $row['campaign'] ?? null;
            $customer = $row['customer'] ?? null;
            $adGroup = $row['adGroup'] ?? null;
            $criterion = $row['adGroupCriterion'] ?? null;
            $keyword = is_array($criterion) ? ($criterion['keyword'] ?? null) : null;
            $metrics = $row['metrics'] ?? null;
            $rowCurrencyCode = is_array($customer) ? ($customer['currencyCode'] ?? null) : null;
            $adGroupId = is_array($adGroup) ? ($adGroup['id'] ?? null) : null;
            $adGroupName = is_array($adGroup) ? ($adGroup['name'] ?? null) : null;
            $criterionId = is_array($criterion) ? ($criterion['criterionId'] ?? null) : null;
            $keywordText = is_array($keyword) ? ($keyword['text'] ?? null) : null;
            $matchType = is_array($keyword) ? ($keyword['matchType'] ?? null) : null;
            $criterionStatus = is_array($criterion) ? ($criterion['status'] ?? null) : null;

            if (! is_array($campaign)
                || (string) ($campaign['id'] ?? '') !== $campaignId
                || ! is_array($customer)
                || (string) ($customer['id'] ?? '') !== $customerId
                || ! is_string($rowCurrencyCode)
                || $rowCurrencyCode !== $accountCurrency
                || (! is_string($adGroupId) && ! is_int($adGroupId))
                || preg_match('/^\d{1,32}$/', (string) $adGroupId) !== 1
                || ! is_string($adGroupName)
                || trim($adGroupName) === ''
                || strlen($adGroupName) > 1024
                || (! is_string($criterionId) && ! is_int($criterionId))
                || preg_match('/^\d{1,32}$/', (string) $criterionId) !== 1
                || ! is_string($keywordText)
                || trim($keywordText) === ''
                || strlen($keywordText) > 4096
                || ! is_string($matchType)
                || preg_match('/^[A-Z][A-Z_]{0,49}$/', $matchType) !== 1
                || ! is_string($criterionStatus)
                || preg_match('/^[A-Z][A-Z_]{0,49}$/', $criterionStatus) !== 1
                || ! is_array($metrics)) {
                throw new GoogleAdsApiException('Google Ads API 回傳不同帳戶、活動或格式無效的關鍵字資料，未儲存讀值。');
            }

            $rows[] = [
                'keyword_text' => $keywordText,
                'match_type' => $matchType,
                'criterion_status' => $criterionStatus,
                'criterion_id' => (string) $criterionId,
                'ad_group_id' => (string) $adGroupId,
                'ad_group_name' => trim($adGroupName),
                'impressions' => $this->nonNegativeInteger($metrics['impressions'] ?? null),
                'clicks' => $this->nonNegativeInteger($metrics['clicks'] ?? null),
                'spend_amount' => $this->microsToDecimal($metrics['costMicros'] ?? null),
                'conversions' => $this->decimalString($metrics['conversions'] ?? null, false),
                'conversions_value' => $this->decimalString($metrics['conversionsValue'] ?? null, true),
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
                    '預算流失比重',
                    0.0,
                    0.9,
                    0.9001,
                    false,
                ),
                'search_rank_lost_impression_share' => $this->searchShareMetric(
                    $metrics['searchRankLostImpressionShare'] ?? null,
                    '排名流失比重',
                    0.0,
                    0.9,
                    0.9001,
                    false,
                ),
            ];
        }

        return [
            'status' => 'complete',
            'window_start_on' => $windowStartOn,
            'window_end_on' => $windowEndOn,
            'currency_code' => $accountCurrency,
            'truncated' => count($rows) > 200,
            'rows' => array_slice($rows, 0, 200),
        ];
    }

    /**
     * @return array{
     *     status: 'complete'|'unsupported'|'no_complete_days',
     *     window_start_on: string|null,
     *     window_end_on: string|null,
     *     currency_code: string,
     *     truncated: bool,
     *     rows: list<array{
     *         domain: string,
     *         impression_share: float|null,
     *         impression_share_is_below_10_percent: bool,
     *         overlap_rate: float|null,
     *         position_above_rate: float|null,
     *         outranking_share: float|null,
     *         outranking_share_is_below_10_percent: bool,
     *         top_impression_percentage: float|null,
     *         absolute_top_impression_percentage: float|null
     *     }>
     * }
     */
    public function campaignAuctionInsights(
        string $accessToken,
        string $customerId,
        string $campaignId,
        string $startsOn,
        string $endsOn,
        string $accountCurrency,
        ?string $campaignChannelType,
        ?string $loginCustomerId = null,
    ): array {
        $this->validateCustomerIds($customerId, $loginCustomerId);

        if (preg_match('/^\d{5,32}$/', $campaignId) !== 1
            || ! $this->isCalendarDate($startsOn)
            || ! $this->isCalendarDate($endsOn)
            || $startsOn > $endsOn
            || preg_match('/^[A-Z]{3}$/', $accountCurrency) !== 1) {
            throw new GoogleAdsApiException('Google Ads 競價洞察報表設定無效，請確認活動、日期與幣別。');
        }

        if ($campaignChannelType !== 'SEARCH') {
            return $this->emptyAuctionInsightReport('unsupported', $accountCurrency);
        }

        $today = CarbonImmutable::now(config('app.timezone'))->toDateString();
        $yesterday = CarbonImmutable::parse($today)->subDay()->toDateString();
        $minimumSupportedDate = $this->minimumSupportedDate();
        $windowEndOn = min($endsOn, $yesterday);

        if ($windowEndOn < $minimumSupportedDate) {
            return $this->emptyAuctionInsightReport('unsupported', $accountCurrency);
        }

        if ($startsOn > $windowEndOn) {
            return $this->emptyAuctionInsightReport('no_complete_days', $accountCurrency);
        }

        $thirtyDayStart = CarbonImmutable::parse($windowEndOn)->subDays(29)->toDateString();
        $windowStartOn = max($startsOn, $thirtyDayStart, $minimumSupportedDate);
        $query = "SELECT customer.id, customer.currency_code, campaign.id, segments.auction_insight_domain, metrics.auction_insight_search_impression_share, metrics.auction_insight_search_overlap_rate, metrics.auction_insight_search_position_above_rate, metrics.auction_insight_search_outranking_share, metrics.auction_insight_search_top_impression_percentage, metrics.auction_insight_search_absolute_top_impression_percentage FROM campaign WHERE campaign.id = {$campaignId} AND segments.date BETWEEN '{$windowStartOn}' AND '{$windowEndOn}' ORDER BY metrics.auction_insight_search_overlap_rate DESC, segments.auction_insight_domain ASC LIMIT ".(self::AUCTION_INSIGHT_REPORT_LIMIT + 1);
        $payload = $this->googleAdsRequest(
            $accessToken,
            $customerId,
            'googleAds:searchStream',
            $query,
            $loginCustomerId,
        );
        $rawRows = $this->strictRowsFromStream($payload);

        if (count($rawRows) > self::AUCTION_INSIGHT_REPORT_LIMIT + 1) {
            throw new GoogleAdsApiException('Google Ads API 回傳超過競價洞察讀取上限，未儲存讀值。');
        }

        $rows = [];
        $seenDomains = [];

        foreach ($rawRows as $row) {
            $campaign = $row['campaign'] ?? null;
            $customer = $row['customer'] ?? null;
            $segments = $row['segments'] ?? null;
            $metrics = $row['metrics'] ?? null;
            $domain = is_array($segments) ? ($segments['auctionInsightDomain'] ?? null) : null;
            $rowCurrencyCode = is_array($customer) ? ($customer['currencyCode'] ?? null) : null;

            if (! is_array($campaign)
                || (string) ($campaign['id'] ?? '') !== $campaignId
                || ! is_array($customer)
                || (string) ($customer['id'] ?? '') !== $customerId
                || ! is_string($rowCurrencyCode)
                || $rowCurrencyCode !== $accountCurrency
                || ! is_string($domain)
                || trim($domain) === ''
                || mb_strlen($domain) > 2048
                || ! is_array($metrics)) {
                throw new GoogleAdsApiException('Google Ads API 回傳不同帳戶、活動或格式無效的競價洞察資料，未儲存讀值。');
            }

            $domain = trim($domain);

            if (isset($seenDomains[$domain])) {
                throw new GoogleAdsApiException('Google Ads API 回傳重複網域的競價洞察資料，未儲存讀值。');
            }

            $seenDomains[$domain] = true;
            $impressionShare = $this->searchShareMetric(
                $metrics['auctionInsightSearchImpressionShare'] ?? null,
                '競價洞察曝光比重',
                0.1,
                1.0,
                0.0999,
                true,
            );
            $outrankingShare = $this->searchShareMetric(
                $metrics['auctionInsightSearchOutrankingShare'] ?? null,
                '競價洞察勝出比重',
                0.1,
                1.0,
                0.0999,
                true,
            );

            $rows[] = [
                'domain' => $domain,
                'impression_share' => $impressionShare,
                'impression_share_is_below_10_percent' => $impressionShare === 0.0999,
                'overlap_rate' => $this->searchShareMetric(
                    $metrics['auctionInsightSearchOverlapRate'] ?? null,
                    '競價洞察重疊率',
                    0.0,
                    1.0,
                    0.0999,
                    false,
                ),
                'position_above_rate' => $this->searchShareMetric(
                    $metrics['auctionInsightSearchPositionAboveRate'] ?? null,
                    '競價洞察排名高於比率',
                    0.0,
                    1.0,
                    0.0999,
                    false,
                ),
                'outranking_share' => $outrankingShare,
                'outranking_share_is_below_10_percent' => $outrankingShare === 0.0999,
                'top_impression_percentage' => $this->searchShareMetric(
                    $metrics['auctionInsightSearchTopImpressionPercentage'] ?? null,
                    '競價洞察頂端曝光比率',
                    0.0,
                    1.0,
                    0.0999,
                    false,
                ),
                'absolute_top_impression_percentage' => $this->searchShareMetric(
                    $metrics['auctionInsightSearchAbsoluteTopImpressionPercentage'] ?? null,
                    '競價洞察絕對頂端曝光比率',
                    0.0,
                    1.0,
                    0.0999,
                    false,
                ),
            ];
        }

        usort($rows, static function (array $first, array $second): int {
            $firstOverlapRate = $first['overlap_rate'];
            $secondOverlapRate = $second['overlap_rate'];

            if ($firstOverlapRate !== $secondOverlapRate) {
                if ($firstOverlapRate === null) {
                    return 1;
                }

                if ($secondOverlapRate === null) {
                    return -1;
                }

                return $secondOverlapRate <=> $firstOverlapRate;
            }

            return strcasecmp($first['domain'], $second['domain']);
        });

        return [
            'status' => 'complete',
            'window_start_on' => $windowStartOn,
            'window_end_on' => $windowEndOn,
            'currency_code' => $accountCurrency,
            'truncated' => count($rows) > self::AUCTION_INSIGHT_REPORT_LIMIT,
            'rows' => array_slice($rows, 0, self::AUCTION_INSIGHT_REPORT_LIMIT),
        ];
    }

    /**
     * @return array{
     *     status: 'complete'|'unsupported'|'no_complete_days',
     *     window_start_on: string|null,
     *     window_end_on: string|null,
     *     currency_code: string,
     *     unit_type: 'ad_group'|'asset_group',
     *     truncated: bool,
     *     rows: list<array{
     *         unit_id: string,
     *         unit_name: string,
     *         status: string,
     *         impressions: int,
     *         clicks: int,
     *         spend_amount: string,
     *         conversions: string,
     *         conversions_value: string
     *     }>
     * }
     */
    public function campaignDeliveryUnitInsights(
        string $accessToken,
        string $customerId,
        string $campaignId,
        string $startsOn,
        string $endsOn,
        string $accountCurrency,
        ?string $campaignChannelType,
        ?string $loginCustomerId = null,
    ): array {
        $this->validateCustomerIds($customerId, $loginCustomerId);

        if (preg_match('/^\d{5,32}$/', $campaignId) !== 1
            || ! $this->isCalendarDate($startsOn)
            || ! $this->isCalendarDate($endsOn)
            || $startsOn > $endsOn
            || preg_match('/^[A-Z]{3}$/', $accountCurrency) !== 1) {
            throw new GoogleAdsApiException('Google Ads 投放單位報表設定無效，請確認活動、日期與幣別。');
        }

        $unitType = $campaignChannelType === 'PERFORMANCE_MAX' ? 'asset_group' : 'ad_group';
        $unitResource = $unitType;
        $unitResponseKey = $unitType === 'asset_group' ? 'assetGroup' : 'adGroup';
        $today = CarbonImmutable::now(config('app.timezone'))->toDateString();
        $yesterday = CarbonImmutable::parse($today)->subDay()->toDateString();
        $minimumSupportedDate = $this->minimumSupportedDate();
        $windowEndOn = min($endsOn, $yesterday);

        if ($windowEndOn < $minimumSupportedDate) {
            return $this->emptyDeliveryUnitReport('unsupported', $accountCurrency, $unitType);
        }

        if ($startsOn > $windowEndOn) {
            return $this->emptyDeliveryUnitReport('no_complete_days', $accountCurrency, $unitType);
        }

        $thirtyDayStart = CarbonImmutable::parse($windowEndOn)->subDays(29)->toDateString();
        $windowStartOn = max($startsOn, $thirtyDayStart, $minimumSupportedDate);
        $query = "SELECT customer.id, customer.currency_code, campaign.id, {$unitResource}.id, {$unitResource}.name, {$unitResource}.status, metrics.cost_micros, metrics.impressions, metrics.clicks, metrics.conversions, metrics.conversions_value FROM {$unitResource} WHERE campaign.id = {$campaignId} AND segments.date BETWEEN '{$windowStartOn}' AND '{$windowEndOn}' ORDER BY metrics.cost_micros DESC, {$unitResource}.id ASC LIMIT 201";
        $payload = $this->googleAdsRequest(
            $accessToken,
            $customerId,
            'googleAds:searchStream',
            $query,
            $loginCustomerId,
        );
        $rawRows = $this->strictRowsFromStream($payload);

        if (count($rawRows) > self::DELIVERY_UNIT_REPORT_LIMIT + 1) {
            throw new GoogleAdsApiException('Google Ads API 回傳超過投放單位讀取上限，未儲存讀值。');
        }

        $rows = [];

        foreach ($rawRows as $row) {
            $campaign = $row['campaign'] ?? null;
            $customer = $row['customer'] ?? null;
            $deliveryUnit = $row[$unitResponseKey] ?? null;
            $metrics = $row['metrics'] ?? null;
            $unitId = is_array($deliveryUnit) ? ($deliveryUnit['id'] ?? null) : null;
            $unitName = is_array($deliveryUnit) ? ($deliveryUnit['name'] ?? null) : null;
            $unitStatus = is_array($deliveryUnit) ? ($deliveryUnit['status'] ?? null) : null;
            $rowCurrencyCode = is_array($customer) ? ($customer['currencyCode'] ?? null) : null;

            if (! is_array($campaign)
                || (string) ($campaign['id'] ?? '') !== $campaignId
                || ! is_array($customer)
                || (string) ($customer['id'] ?? '') !== $customerId
                || ! is_string($rowCurrencyCode)
                || $rowCurrencyCode !== $accountCurrency
                || (! is_string($unitId) && ! is_int($unitId))
                || preg_match('/^\d{1,32}$/', (string) $unitId) !== 1
                || ! is_string($unitName)
                || trim($unitName) === ''
                || strlen($unitName) > 1024
                || ! is_string($unitStatus)
                || preg_match('/^[A-Z][A-Z0-9_]{0,49}$/', $unitStatus) !== 1
                || ! is_array($metrics)) {
                throw new GoogleAdsApiException('Google Ads API 回傳不同帳戶、活動或格式無效的投放單位資料，未儲存讀值。');
            }

            $rows[] = [
                'unit_id' => (string) $unitId,
                'unit_name' => trim($unitName),
                'status' => $unitStatus,
                'impressions' => $this->nonNegativeInteger($metrics['impressions'] ?? null),
                'clicks' => $this->nonNegativeInteger($metrics['clicks'] ?? null),
                'spend_amount' => $this->microsToDecimal($metrics['costMicros'] ?? null),
                'conversions' => $this->decimalString($metrics['conversions'] ?? null, false),
                'conversions_value' => $this->decimalString($metrics['conversionsValue'] ?? null, true),
            ];
        }

        return [
            'status' => 'complete',
            'window_start_on' => $windowStartOn,
            'window_end_on' => $windowEndOn,
            'currency_code' => $accountCurrency,
            'unit_type' => $unitType,
            'truncated' => count($rows) > self::DELIVERY_UNIT_REPORT_LIMIT,
            'rows' => array_slice($rows, 0, self::DELIVERY_UNIT_REPORT_LIMIT),
        ];
    }

    /**
     * @return array{
     *     status: 'complete'|'unsupported'|'no_complete_days',
     *     window_start_on: string|null,
     *     window_end_on: string|null,
     *     currency_code: string,
     *     truncated: bool,
     *     rows: list<array{
     *         ad_group_id: string,
     *         ad_group_name: string,
     *         ad_id: string,
     *         asset_id: string,
     *         text: string,
     *         field_type: string,
     *         source: string,
     *         pinned_field: string,
     *         performance_label: string,
     *         impressions: int,
     *         clicks: int,
     *         spend_amount: string,
     *         conversions: string,
     *         conversions_value: string
     *     }>
     * }
     */
    public function campaignResponsiveSearchAdAssetInsights(
        string $accessToken,
        string $customerId,
        string $campaignId,
        string $startsOn,
        string $endsOn,
        string $accountCurrency,
        ?string $loginCustomerId = null,
    ): array {
        $this->validateCustomerIds($customerId, $loginCustomerId);

        if (preg_match('/^\d{5,32}$/', $campaignId) !== 1
            || ! $this->isCalendarDate($startsOn)
            || ! $this->isCalendarDate($endsOn)
            || $startsOn > $endsOn
            || preg_match('/^[A-Z]{3}$/', $accountCurrency) !== 1) {
            throw new GoogleAdsApiException('Google Ads 搜尋廣告素材報表設定無效，請確認活動、日期與幣別。');
        }

        $today = CarbonImmutable::now(config('app.timezone'))->toDateString();
        $yesterday = CarbonImmutable::parse($today)->subDay()->toDateString();
        $minimumSupportedDate = $this->minimumSupportedDate();
        $windowEndOn = min($endsOn, $yesterday);

        if ($windowEndOn < $minimumSupportedDate) {
            return $this->emptyResponsiveSearchAdAssetReport('unsupported', $accountCurrency);
        }

        if ($startsOn > $windowEndOn) {
            return $this->emptyResponsiveSearchAdAssetReport('no_complete_days', $accountCurrency);
        }

        $thirtyDayStart = CarbonImmutable::parse($windowEndOn)->subDays(29)->toDateString();
        $windowStartOn = max($startsOn, $thirtyDayStart, $minimumSupportedDate);
        $query = "SELECT customer.id, customer.currency_code, campaign.id, ad_group.id, ad_group.name, ad_group_ad.ad.id, ad_group_ad.ad.type, ad_group_ad_asset_view.asset, asset.id, asset.text_asset.text, ad_group_ad_asset_view.field_type, ad_group_ad_asset_view.source, ad_group_ad_asset_view.pinned_field, ad_group_ad_asset_view.performance_label, ad_group_ad_asset_view.enabled, metrics.cost_micros, metrics.impressions, metrics.clicks, metrics.conversions, metrics.conversions_value FROM ad_group_ad_asset_view WHERE campaign.id = {$campaignId} AND ad_group_ad.ad.type = 'RESPONSIVE_SEARCH_AD' AND ad_group_ad.status != 'REMOVED' AND ad_group_ad_asset_view.enabled = TRUE AND segments.date BETWEEN '{$windowStartOn}' AND '{$windowEndOn}' ORDER BY metrics.impressions DESC, ad_group_ad.ad.id ASC, asset.id ASC LIMIT 201";
        $payload = $this->googleAdsRequest(
            $accessToken,
            $customerId,
            'googleAds:searchStream',
            $query,
            $loginCustomerId,
        );
        $rawRows = $this->strictRowsFromStream($payload);

        if (count($rawRows) > self::RESPONSIVE_SEARCH_AD_ASSET_REPORT_LIMIT + 1) {
            throw new GoogleAdsApiException('Google Ads API 回傳超過搜尋廣告素材讀取上限，未儲存讀值。');
        }

        $rows = [];

        foreach ($rawRows as $row) {
            $campaign = $row['campaign'] ?? null;
            $customer = $row['customer'] ?? null;
            $adGroup = $row['adGroup'] ?? null;
            $adGroupAd = $row['adGroupAd'] ?? null;
            $ad = is_array($adGroupAd) ? ($adGroupAd['ad'] ?? null) : null;
            $asset = $row['asset'] ?? null;
            $textAsset = is_array($asset) ? ($asset['textAsset'] ?? null) : null;
            $assetView = $row['adGroupAdAssetView'] ?? null;
            $metrics = $row['metrics'] ?? null;
            $adGroupId = is_array($adGroup) ? ($adGroup['id'] ?? null) : null;
            $adGroupName = is_array($adGroup) ? ($adGroup['name'] ?? null) : null;
            $adId = is_array($ad) ? ($ad['id'] ?? null) : null;
            $assetId = is_array($asset) ? ($asset['id'] ?? null) : null;
            $assetText = is_array($textAsset) ? ($textAsset['text'] ?? null) : null;
            $adType = is_array($ad) ? ($ad['type'] ?? null) : null;
            $assetEnabled = is_array($assetView) ? ($assetView['enabled'] ?? null) : null;
            $rowCurrencyCode = is_array($customer) ? ($customer['currencyCode'] ?? null) : null;

            if (! is_array($campaign)
                || (string) ($campaign['id'] ?? '') !== $campaignId
                || ! is_array($customer)
                || (string) ($customer['id'] ?? '') !== $customerId
                || ! is_string($rowCurrencyCode)
                || $rowCurrencyCode !== $accountCurrency
                || ! is_array($adGroup)
                || (! is_string($adGroupId) && ! is_int($adGroupId))
                || preg_match('/^\d{1,32}$/', (string) $adGroupId) !== 1
                || ! is_string($adGroupName)
                || trim($adGroupName) === ''
                || strlen($adGroupName) > 1024
                || ! is_array($ad)
                || (! is_string($adId) && ! is_int($adId))
                || preg_match('/^\d{1,32}$/', (string) $adId) !== 1
                || ! is_array($asset)
                || (! is_string($assetId) && ! is_int($assetId))
                || preg_match('/^\d{1,32}$/', (string) $assetId) !== 1
                || ! is_string($assetText)
                || trim($assetText) === ''
                || strlen($assetText) > 1024
                || ! is_array($assetView)
                || ! is_array($metrics)) {
                throw new GoogleAdsApiException('Google Ads API 回傳不同帳戶、活動或格式無效的搜尋廣告素材資料，未儲存讀值。');
            }

            if ($adType !== 'RESPONSIVE_SEARCH_AD' || $assetEnabled !== true) {
                throw new GoogleAdsApiException('Google Ads API 回傳未啟用或非響應式搜尋廣告素材，未儲存讀值。');
            }

            $fieldType = $assetView['fieldType'] ?? null;
            $source = $assetView['source'] ?? null;
            $pinnedField = $assetView['pinnedField'] ?? null;
            $performanceLabel = $assetView['performanceLabel'] ?? null;

            foreach ([$fieldType, $source, $pinnedField, $performanceLabel] as $enumValue) {
                if (! is_string($enumValue) || preg_match('/^[A-Z][A-Z0-9_]{0,49}$/', $enumValue) !== 1) {
                    throw new GoogleAdsApiException('Google Ads API 回傳格式無效的搜尋廣告素材分類，未儲存讀值。');
                }
            }

            $rows[] = [
                'ad_group_id' => (string) $adGroupId,
                'ad_group_name' => trim($adGroupName),
                'ad_id' => (string) $adId,
                'asset_id' => (string) $assetId,
                'text' => trim($assetText),
                'field_type' => $fieldType,
                'source' => $source,
                'pinned_field' => $pinnedField,
                'performance_label' => $performanceLabel,
                'impressions' => $this->nonNegativeInteger($metrics['impressions'] ?? null),
                'clicks' => $this->nonNegativeInteger($metrics['clicks'] ?? null),
                'spend_amount' => $this->microsToDecimal($metrics['costMicros'] ?? null),
                'conversions' => $this->decimalString($metrics['conversions'] ?? null, false),
                'conversions_value' => $this->decimalString($metrics['conversionsValue'] ?? null, true),
            ];
        }

        return [
            'status' => 'complete',
            'window_start_on' => $windowStartOn,
            'window_end_on' => $windowEndOn,
            'currency_code' => $accountCurrency,
            'truncated' => count($rows) > self::RESPONSIVE_SEARCH_AD_ASSET_REPORT_LIMIT,
            'rows' => array_slice($rows, 0, self::RESPONSIVE_SEARCH_AD_ASSET_REPORT_LIMIT),
        ];
    }

    /** @return array{status: 'unsupported'|'no_complete_days', window_start_on: null, window_end_on: null, currency_code: string, truncated: false, rows: list<array<string, mixed>>} */
    private function emptyResponsiveSearchAdAssetReport(string $status, string $currencyCode): array
    {
        return [
            'status' => $status,
            'window_start_on' => null,
            'window_end_on' => null,
            'currency_code' => $currencyCode,
            'truncated' => false,
            'rows' => [],
        ];
    }

    /**
     * @return array{
     *     status: 'complete'|'unsupported'|'no_complete_days',
     *     window_start_on: string|null,
     *     window_end_on: string|null,
     *     currency_code: string,
     *     truncated: bool,
     *     rows: list<array{
     *         asset_group_id: string,
     *         asset_group_name: string,
     *         asset_id: string,
     *         asset_name: string,
     *         field_type: string,
     *         asset_type: string,
     *         content: string,
     *         youtube_video_id: string|null,
     *         status: string,
     *         primary_status: string,
     *         primary_status_reasons: list<string>,
     *         source: string,
     *         impressions: int,
     *         clicks: int,
     *         spend_amount: string,
     *         conversions: string,
     *         conversions_value: string
     *     }>
     * }
     */
    public function campaignPerformanceMaxAssetInsights(
        string $accessToken,
        string $customerId,
        string $campaignId,
        string $startsOn,
        string $endsOn,
        string $accountCurrency,
        ?string $loginCustomerId = null,
    ): array {
        $this->validateCustomerIds($customerId, $loginCustomerId);

        if (preg_match('/^\d{5,32}$/', $campaignId) !== 1
            || ! $this->isCalendarDate($startsOn)
            || ! $this->isCalendarDate($endsOn)
            || $startsOn > $endsOn
            || preg_match('/^[A-Z]{3}$/', $accountCurrency) !== 1) {
            throw new GoogleAdsApiException('Google Ads Performance Max 素材報表設定無效，請確認活動、日期與幣別。');
        }

        $today = CarbonImmutable::now(config('app.timezone'))->toDateString();
        $yesterday = CarbonImmutable::parse($today)->subDay()->toDateString();
        $minimumSupportedDate = $this->minimumSupportedDate();
        $windowEndOn = min($endsOn, $yesterday);

        if ($windowEndOn < $minimumSupportedDate) {
            return $this->emptyPerformanceMaxAssetReport('unsupported', $accountCurrency);
        }

        if ($startsOn > $windowEndOn) {
            return $this->emptyPerformanceMaxAssetReport('no_complete_days', $accountCurrency);
        }

        $thirtyDayStart = CarbonImmutable::parse($windowEndOn)->subDays(29)->toDateString();
        $windowStartOn = max($startsOn, $thirtyDayStart, $minimumSupportedDate);
        $query = "SELECT customer.id, customer.currency_code, campaign.id, campaign.advertising_channel_type, asset_group.id, asset_group.name, asset_group_asset.asset, asset_group_asset.field_type, asset_group_asset.status, asset_group_asset.source, asset_group_asset.primary_status, asset_group_asset.primary_status_reasons, asset.id, asset.name, asset.type, asset.text_asset.text, asset.youtube_video_asset.youtube_video_id, metrics.cost_micros, metrics.impressions, metrics.clicks, metrics.conversions, metrics.conversions_value FROM asset_group_asset WHERE campaign.id = {$campaignId} AND campaign.advertising_channel_type = 'PERFORMANCE_MAX' AND asset_group_asset.status != 'REMOVED' AND segments.date BETWEEN '{$windowStartOn}' AND '{$windowEndOn}' ORDER BY metrics.impressions DESC, asset_group.id ASC, asset.id ASC, asset_group_asset.field_type ASC LIMIT 201";
        $payload = $this->googleAdsRequest(
            $accessToken,
            $customerId,
            'googleAds:searchStream',
            $query,
            $loginCustomerId,
        );
        $rawRows = $this->strictRowsFromStream($payload);

        if (count($rawRows) > self::PERFORMANCE_MAX_ASSET_REPORT_LIMIT + 1) {
            throw new GoogleAdsApiException('Google Ads API 回傳超過 Performance Max 素材讀取上限，未儲存讀值。');
        }

        $rows = [];
        $seenAssetLinks = [];

        foreach ($rawRows as $row) {
            $campaign = $row['campaign'] ?? null;
            $customer = $row['customer'] ?? null;
            $assetGroup = $row['assetGroup'] ?? null;
            $assetGroupAsset = $row['assetGroupAsset'] ?? null;
            $asset = $row['asset'] ?? null;
            $metrics = $row['metrics'] ?? null;
            $customerIdFromRow = is_array($customer) ? ($customer['id'] ?? null) : null;
            $campaignIdFromRow = is_array($campaign) ? ($campaign['id'] ?? null) : null;
            $campaignChannelType = is_array($campaign) ? ($campaign['advertisingChannelType'] ?? null) : null;
            $currencyCode = is_array($customer) ? ($customer['currencyCode'] ?? null) : null;
            $assetGroupId = is_array($assetGroup) ? ($assetGroup['id'] ?? null) : null;
            $assetGroupName = is_array($assetGroup) ? ($assetGroup['name'] ?? null) : null;
            $assetId = is_array($asset) ? ($asset['id'] ?? null) : null;
            $assetName = is_array($asset) ? ($asset['name'] ?? null) : null;
            $assetType = is_array($asset) ? ($asset['type'] ?? null) : null;
            $textAsset = is_array($asset) ? ($asset['textAsset'] ?? null) : null;
            $youtubeVideoAsset = is_array($asset) ? ($asset['youtubeVideoAsset'] ?? null) : null;
            $assetText = is_array($textAsset) ? ($textAsset['text'] ?? null) : null;
            $youtubeVideoId = is_array($youtubeVideoAsset) ? ($youtubeVideoAsset['youtubeVideoId'] ?? null) : null;
            $fieldType = is_array($assetGroupAsset) ? ($assetGroupAsset['fieldType'] ?? null) : null;
            $assetStatus = is_array($assetGroupAsset) ? ($assetGroupAsset['status'] ?? null) : null;
            $assetSource = is_array($assetGroupAsset) ? ($assetGroupAsset['source'] ?? null) : null;
            $primaryStatus = is_array($assetGroupAsset) ? ($assetGroupAsset['primaryStatus'] ?? 'UNSPECIFIED') : null;
            $primaryStatusReasons = is_array($assetGroupAsset) ? ($assetGroupAsset['primaryStatusReasons'] ?? []) : null;

            if ((! is_string($customerIdFromRow) && ! is_int($customerIdFromRow))
                || (string) $customerIdFromRow !== $customerId
                || (! is_string($campaignIdFromRow) && ! is_int($campaignIdFromRow))
                || (string) $campaignIdFromRow !== $campaignId
                || $campaignChannelType !== 'PERFORMANCE_MAX'
                || $currencyCode !== $accountCurrency
                || ! is_array($assetGroup)
                || (! is_string($assetGroupId) && ! is_int($assetGroupId))
                || preg_match('/^\d{1,32}$/', (string) $assetGroupId) !== 1
                || ! is_string($assetGroupName)
                || trim($assetGroupName) === ''
                || strlen($assetGroupName) > 1024
                || ! is_array($asset)
                || (! is_string($assetId) && ! is_int($assetId))
                || preg_match('/^\d{1,32}$/', (string) $assetId) !== 1
                || ! is_array($assetGroupAsset)
                || ! is_array($metrics)) {
                throw new GoogleAdsApiException('Google Ads API 回傳不同帳戶、活動或格式無效的 Performance Max 素材資料，未儲存讀值。');
            }

            foreach ([$fieldType, $assetType, $assetStatus, $assetSource, $primaryStatus] as $enumValue) {
                if (! is_string($enumValue) || preg_match('/^[A-Z][A-Z0-9_]{0,49}$/', $enumValue) !== 1) {
                    throw new GoogleAdsApiException('Google Ads API 回傳格式無效的 Performance Max 素材分類，未儲存讀值。');
                }
            }

            if ($assetStatus === 'REMOVED'
                || ! is_array($primaryStatusReasons)
                || ! array_is_list($primaryStatusReasons)
                || ($assetText !== null && (! is_string($assetText) || strlen($assetText) > 2000))
                || ($assetName !== null && (! is_string($assetName) || strlen($assetName) > 1024))) {
                throw new GoogleAdsApiException('Google Ads API 回傳格式無效的 Performance Max 素材內容，未儲存讀值。');
            }

            foreach ($primaryStatusReasons as $reason) {
                if (! is_string($reason) || preg_match('/^[A-Z][A-Z0-9_]{0,79}$/', $reason) !== 1) {
                    throw new GoogleAdsApiException('Google Ads API 回傳格式無效的 Performance Max 素材狀態原因，未儲存讀值。');
                }
            }

            if ($youtubeVideoId !== null
                && (! is_string($youtubeVideoId) || preg_match('/^[A-Za-z0-9_-]{11}$/', $youtubeVideoId) !== 1)) {
                throw new GoogleAdsApiException('Google Ads API 回傳格式無效的 Performance Max YouTube 影片 ID，未儲存讀值。');
            }

            $assetLinkKey = implode(':', [$assetGroupId, $assetId, $fieldType]);

            if (array_key_exists($assetLinkKey, $seenAssetLinks)) {
                throw new GoogleAdsApiException('Google Ads API 回傳重複的 Performance Max 素材連結，未儲存讀值。');
            }

            $seenAssetLinks[$assetLinkKey] = true;
            $content = is_string($assetText) && trim($assetText) !== ''
                ? trim($assetText)
                : (is_string($assetName) && trim($assetName) !== ''
                    ? trim($assetName)
                    : ($youtubeVideoId !== null ? 'YouTube 影片 '.$youtubeVideoId : $assetType.' 素材'));

            $rows[] = [
                'asset_group_id' => (string) $assetGroupId,
                'asset_group_name' => trim($assetGroupName),
                'asset_id' => (string) $assetId,
                'asset_name' => is_string($assetName) ? trim($assetName) : '',
                'field_type' => $fieldType,
                'asset_type' => $assetType,
                'content' => $content,
                'youtube_video_id' => $youtubeVideoId,
                'status' => $assetStatus,
                'primary_status' => $primaryStatus,
                'primary_status_reasons' => array_values($primaryStatusReasons),
                'source' => $assetSource,
                'impressions' => $this->nonNegativeInteger($metrics['impressions'] ?? null),
                'clicks' => $this->nonNegativeInteger($metrics['clicks'] ?? null),
                'spend_amount' => $this->microsToDecimal($metrics['costMicros'] ?? null),
                'conversions' => $this->decimalString($metrics['conversions'] ?? null, false),
                'conversions_value' => $this->decimalString($metrics['conversionsValue'] ?? null, true),
            ];
        }

        return [
            'status' => 'complete',
            'window_start_on' => $windowStartOn,
            'window_end_on' => $windowEndOn,
            'currency_code' => $accountCurrency,
            'truncated' => count($rows) > self::PERFORMANCE_MAX_ASSET_REPORT_LIMIT,
            'rows' => array_slice($rows, 0, self::PERFORMANCE_MAX_ASSET_REPORT_LIMIT),
        ];
    }

    /** @return array{status: 'unsupported'|'no_complete_days', window_start_on: null, window_end_on: null, currency_code: string, truncated: false, rows: list<array<string, mixed>>} */
    private function emptyPerformanceMaxAssetReport(string $status, string $currencyCode): array
    {
        return [
            'status' => $status,
            'window_start_on' => null,
            'window_end_on' => null,
            'currency_code' => $currencyCode,
            'truncated' => false,
            'rows' => [],
        ];
    }

    /**
     * @return array{
     *     status: 'complete'|'unsupported'|'no_complete_days',
     *     window_start_on: string|null,
     *     window_end_on: string|null,
     *     currency_code: string,
     *     truncated: false,
     *     rows: list<array{
     *         device: string,
     *         impressions: int,
     *         clicks: int,
     *         spend_amount: string,
     *         conversions: string,
     *         conversions_value: string
     *     }>
     * }
     */
    public function campaignDeviceInsights(
        string $accessToken,
        string $customerId,
        string $campaignId,
        string $startsOn,
        string $endsOn,
        string $accountCurrency,
        ?string $loginCustomerId = null,
    ): array {
        $this->validateCustomerIds($customerId, $loginCustomerId);

        if (preg_match('/^\d{5,32}$/', $campaignId) !== 1
            || ! $this->isCalendarDate($startsOn)
            || ! $this->isCalendarDate($endsOn)
            || $startsOn > $endsOn
            || preg_match('/^[A-Z]{3}$/', $accountCurrency) !== 1) {
            throw new GoogleAdsApiException('Google Ads 裝置報表設定無效，請確認活動、日期與幣別。');
        }

        $today = CarbonImmutable::now(config('app.timezone'))->toDateString();
        $yesterday = CarbonImmutable::parse($today)->subDay()->toDateString();
        $minimumSupportedDate = $this->minimumSupportedDate();
        $windowEndOn = min($endsOn, $yesterday);

        if ($windowEndOn < $minimumSupportedDate) {
            return [
                'status' => 'unsupported',
                'window_start_on' => null,
                'window_end_on' => null,
                'currency_code' => $accountCurrency,
                'truncated' => false,
                'rows' => [],
            ];
        }

        if ($startsOn > $windowEndOn) {
            return [
                'status' => 'no_complete_days',
                'window_start_on' => null,
                'window_end_on' => null,
                'currency_code' => $accountCurrency,
                'truncated' => false,
                'rows' => [],
            ];
        }

        $thirtyDayStart = CarbonImmutable::parse($windowEndOn)->subDays(29)->toDateString();
        $windowStartOn = max($startsOn, $thirtyDayStart, $minimumSupportedDate);
        $query = "SELECT customer.id, customer.currency_code, campaign.id, segments.device, metrics.cost_micros, metrics.impressions, metrics.clicks, metrics.conversions, metrics.conversions_value FROM campaign WHERE campaign.id = {$campaignId} AND segments.date BETWEEN '{$windowStartOn}' AND '{$windowEndOn}' ORDER BY metrics.cost_micros DESC, segments.device ASC LIMIT ".(self::DEVICE_REPORT_LIMIT + 1);
        $payload = $this->googleAdsRequest(
            $accessToken,
            $customerId,
            'googleAds:searchStream',
            $query,
            $loginCustomerId,
        );
        $rawRows = $this->strictRowsFromStream($payload);

        if (count($rawRows) > self::DEVICE_REPORT_LIMIT) {
            throw new GoogleAdsApiException('Google Ads API 回傳過多裝置分類，未儲存讀值。');
        }

        $rows = [];
        $seenDevices = [];

        foreach ($rawRows as $row) {
            $campaign = $row['campaign'] ?? null;
            $customer = $row['customer'] ?? null;
            $segments = $row['segments'] ?? null;
            $metrics = $row['metrics'] ?? null;
            $device = is_array($segments) ? ($segments['device'] ?? null) : null;
            $rowCurrencyCode = is_array($customer) ? ($customer['currencyCode'] ?? null) : null;

            if (! is_array($campaign)
                || (string) ($campaign['id'] ?? '') !== $campaignId
                || ! is_array($customer)
                || (string) ($customer['id'] ?? '') !== $customerId
                || ! is_string($rowCurrencyCode)
                || $rowCurrencyCode !== $accountCurrency
                || ! is_string($device)
                || preg_match('/^[A-Z][A-Z0-9_]{0,49}$/', $device) !== 1
                || ! is_array($metrics)) {
                throw new GoogleAdsApiException('Google Ads API 回傳不同帳戶、活動或格式無效的裝置資料，未儲存讀值。');
            }

            if (array_key_exists($device, $seenDevices)) {
                throw new GoogleAdsApiException('Google Ads API 回傳重複的裝置資料，未儲存讀值。');
            }

            $seenDevices[$device] = true;
            $rows[] = [
                'device' => $device,
                'impressions' => $this->nonNegativeInteger($metrics['impressions'] ?? null),
                'clicks' => $this->nonNegativeInteger($metrics['clicks'] ?? null),
                'spend_amount' => $this->microsToDecimal($metrics['costMicros'] ?? null),
                'conversions' => $this->decimalString($metrics['conversions'] ?? null, false),
                'conversions_value' => $this->decimalString($metrics['conversionsValue'] ?? null, true),
            ];
        }

        usort($rows, static function (array $first, array $second): int {
            $spendOrder = (float) $second['spend_amount'] <=> (float) $first['spend_amount'];

            return $spendOrder !== 0 ? $spendOrder : strcmp($first['device'], $second['device']);
        });

        return [
            'status' => 'complete',
            'window_start_on' => $windowStartOn,
            'window_end_on' => $windowEndOn,
            'currency_code' => $accountCurrency,
            'truncated' => false,
            'rows' => $rows,
        ];
    }

    /** @return array{status: 'unsupported'|'no_complete_days', window_start_on: null, window_end_on: null, currency_code: string, unit_type: 'ad_group'|'asset_group', truncated: false, rows: list<array<string, mixed>>} */
    private function emptyDeliveryUnitReport(string $status, string $currencyCode, string $unitType): array
    {
        return [
            'status' => $status,
            'window_start_on' => null,
            'window_end_on' => null,
            'currency_code' => $currencyCode,
            'unit_type' => $unitType,
            'truncated' => false,
            'rows' => [],
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

    /** @return array{status: 'unsupported'|'no_complete_days', window_start_on: null, window_end_on: null, currency_code: string, truncated: false, rows: list<array<string, mixed>>} */
    private function emptySearchTermReport(string $status, string $currencyCode): array
    {
        return [
            'status' => $status,
            'window_start_on' => null,
            'window_end_on' => null,
            'currency_code' => $currencyCode,
            'truncated' => false,
            'rows' => [],
        ];
    }

    /** @return array{status: 'unsupported'|'no_complete_days', window_start_on: null, window_end_on: null, currency_code: string, truncated: false, rows: list<array<string, mixed>>} */
    private function emptyKeywordReport(string $status, string $currencyCode): array
    {
        return [
            'status' => $status,
            'window_start_on' => null,
            'window_end_on' => null,
            'currency_code' => $currencyCode,
            'truncated' => false,
            'rows' => [],
        ];
    }

    /** @return array{status: 'unsupported'|'no_complete_days', window_start_on: null, window_end_on: null, currency_code: string, truncated: false, rows: list<array<string, mixed>>} */
    private function emptyAuctionInsightReport(string $status, string $currencyCode): array
    {
        return [
            'status' => $status,
            'window_start_on' => null,
            'window_end_on' => null,
            'currency_code' => $currencyCode,
            'truncated' => false,
            'rows' => [],
        ];
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
