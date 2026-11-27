<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class MetaMarketingDataApi
{
    private const DAILY_INSIGHTS_PAGE_LIMIT = 100;

    private const MAX_DAILY_INSIGHTS_PAGES = 100;

    private const AD_INSIGHTS_PAGE_LIMIT = 100;

    private const MAX_AD_INSIGHTS_PAGES = 100;

    private const AD_INSIGHTS_REPORT_LIMIT = 200;

    private const PLACEMENT_INSIGHTS_PAGE_LIMIT = 100;

    private const MAX_PLACEMENT_INSIGHTS_PAGES = 100;

    private const PLACEMENT_INSIGHTS_REPORT_LIMIT = 200;

    private const DEMOGRAPHIC_INSIGHTS_PAGE_LIMIT = 100;

    private const MAX_DEMOGRAPHIC_INSIGHTS_PAGES = 100;

    private const DEMOGRAPHIC_INSIGHTS_REPORT_LIMIT = 200;

    /** @return array{access_token: string, expires_in: int|null} */
    public function exchangeAuthorizationCode(string $code, string $redirectUri, ?int $workspaceId = null): array
    {
        $credentials = app(OAuthApplicationCredentialResolver::class)->resolve('meta_marketing', $workspaceId);

        if ($credentials === null) {
            throw new MetaMarketingApiException('請先在 Meta Ads 整合設定輸入 OAuth 用戶端 ID 與密鑰。');
        }

        $shortLivedToken = $this->exchangeToken([
            'client_id' => $credentials['client_id'],
            'client_secret' => $credentials['client_secret'],
            'code' => $code,
            'redirect_uri' => $redirectUri,
        ]);

        return $this->exchangeToken([
            'client_id' => $credentials['client_id'],
            'client_secret' => $credentials['client_secret'],
            'grant_type' => 'fb_exchange_token',
            'fb_exchange_token' => $shortLivedToken['access_token'],
        ]);
    }

    /** @return array{account_id: string, name: string, currency_code: string} */
    public function adAccountDetails(string $accessToken, string $adAccountId): array
    {
        if (preg_match('/^\d{5,32}$/', $adAccountId) !== 1) {
            throw new MetaMarketingApiException('Meta 廣告帳戶 ID 格式無效，請輸入 5 至 32 位數字。');
        }

        $response = ReadOnlyApiRequest::forBearerToken($accessToken, 10, retryRateLimitedResponses: false)
            ->get($this->graphUrl('act_'.$adAccountId), [
                'fields' => 'account_id,name,currency',
            ]);

        $payload = $this->successfulPayload($response, 'Meta 廣告帳戶');
        $accountId = $payload['account_id'] ?? null;
        $accountName = $payload['name'] ?? null;
        $currencyCode = $payload['currency'] ?? null;

        if ((! is_string($accountId) && ! is_int($accountId))
            || (string) $accountId !== $adAccountId
            || ! is_string($accountName)
            || trim($accountName) === ''
            || ! is_string($currencyCode)
            || preg_match('/^[A-Z]{3}$/', $currencyCode) !== 1) {
            throw new MetaMarketingApiException('Meta 未回傳有效的廣告帳戶資料，請確認帳戶 ID 與 ads_read 權限。');
        }

        return [
            'account_id' => $adAccountId,
            'name' => trim($accountName),
            'currency_code' => $currencyCode,
        ];
    }

    /**
     * @return array{
     *     campaign_id: string,
     *     campaign_name: string,
     *     spend_amount: string,
     *     impressions: int,
     *     reach: int,
     *     clicks: int,
     *     actions: list<array{action_type: string, value: string}>,
     *     action_values: list<array{action_type: string, value: string}>,
     *     currency_code: string,
     *     daily_metrics: list<array{
     *         date: string,
     *         account_id: string,
     *         campaign_id: string,
     *         campaign_name: string,
     *         spend_amount: string,
     *         impressions: int,
     *         reach: int,
     *         clicks: int,
     *         actions: list<array{action_type: string, value: string}>,
     *         action_values: list<array{action_type: string, value: string}>,
     *         currency_code: string
     *     }>,
     *     daily_metrics_status: 'complete',
     *     daily_metrics_coverage_start_on: string,
     *     daily_metrics_coverage_end_on: string
     * }
     */
    public function campaignInsights(
        string $accessToken,
        string $adAccountId,
        string $campaignId,
        string $startsOn,
        string $endsOn,
        string $accountCurrency,
    ): array {
        if (preg_match('/^\d{5,32}$/', $adAccountId) !== 1 || preg_match('/^\d{5,32}$/', $campaignId) !== 1) {
            throw new MetaMarketingApiException('Meta 廣告帳戶或活動 ID 格式無效。');
        }

        $response = ReadOnlyApiRequest::forBearerToken($accessToken, 20, retryRateLimitedResponses: false)
            ->get($this->graphUrl('act_'.$adAccountId.'/insights'), [
                'fields' => 'account_id,campaign_id,campaign_name,spend,impressions,reach,clicks,actions,action_values,account_currency',
                'level' => 'campaign',
                'filtering' => json_encode([[
                    'field' => 'campaign.id',
                    'operator' => 'EQUAL',
                    'value' => $campaignId,
                ]], JSON_THROW_ON_ERROR),
                'time_range' => json_encode([
                    'since' => $startsOn,
                    'until' => $endsOn,
                ], JSON_THROW_ON_ERROR),
                'limit' => 1,
            ]);

        $payload = $this->successfulPayload($response, 'Meta Ads Insights API');
        $row = $payload['data'][0] ?? null;

        if ($row === null) {
            $dailyMetrics = $this->dailyMetrics(
                $accessToken,
                $adAccountId,
                $campaignId,
                $startsOn,
                $endsOn,
                $accountCurrency,
            );

            return [
                'campaign_id' => $campaignId,
                'campaign_name' => $campaignId,
                'spend_amount' => '0.0000',
                'impressions' => 0,
                'reach' => 0,
                'clicks' => 0,
                'actions' => [],
                'action_values' => [],
                'currency_code' => $accountCurrency,
                ...$dailyMetrics,
            ];
        }

        if (! is_array($row)
            || (string) ($row['campaign_id'] ?? '') !== $campaignId
            || (string) ($row['account_id'] ?? '') !== $adAccountId) {
            throw new MetaMarketingApiException('Meta Ads Insights API 回傳了不同廣告帳戶或活動的資料。');
        }

        $campaignName = $row['campaign_name'] ?? null;
        $currencyCode = $row['account_currency'] ?? $accountCurrency;

        if (! is_string($campaignName)
            || trim($campaignName) === ''
            || ! is_string($currencyCode)
            || preg_match('/^[A-Z]{3}$/', $currencyCode) !== 1) {
            throw new MetaMarketingApiException('Meta Ads Insights API 回傳的活動名稱或幣別無效。');
        }

        $dailyMetrics = $this->dailyMetrics(
            $accessToken,
            $adAccountId,
            $campaignId,
            $startsOn,
            $endsOn,
            $accountCurrency,
        );

        return [
            'campaign_id' => $campaignId,
            'campaign_name' => trim($campaignName),
            'spend_amount' => $this->decimalString($row['spend'] ?? null),
            'impressions' => $this->nonNegativeInteger($row['impressions'] ?? null),
            'reach' => $this->nonNegativeInteger($row['reach'] ?? null),
            'clicks' => $this->nonNegativeInteger($row['clicks'] ?? null),
            'actions' => $this->actionMetrics($row['actions'] ?? null, 'actions'),
            'action_values' => $this->actionMetrics($row['action_values'] ?? null, 'action_values'),
            'currency_code' => $currencyCode,
            ...$dailyMetrics,
        ];
    }

    /**
     * @return list<array{
     *     date: string,
     *     account_id: string,
     *     campaign_id: string,
     *     campaign_name: string,
     *     spend_amount: string,
     *     impressions: int,
     *     reach: int,
     *     clicks: int,
     *     actions: list<array{action_type: string, value: string}>,
     *     action_values: list<array{action_type: string, value: string}>,
     *     currency_code: string
     * }>
     */
    public function campaignDailyInsights(
        string $accessToken,
        string $adAccountId,
        string $campaignId,
        string $startsOn,
        string $endsOn,
        string $accountCurrency,
    ): array {
        if (preg_match('/^\d{5,32}$/', $adAccountId) !== 1
            || preg_match('/^\d{5,32}$/', $campaignId) !== 1) {
            throw new MetaMarketingApiException('Meta 廣告帳戶或活動 ID 格式無效。');
        }

        if (! $this->isCalendarDate($startsOn)
            || ! $this->isCalendarDate($endsOn)
            || $startsOn > $endsOn) {
            throw new MetaMarketingApiException('Meta 活動日期區間無效，請重新設定開始日與結束日。');
        }

        if (preg_match('/^[A-Z]{3}$/', $accountCurrency) !== 1) {
            throw new MetaMarketingApiException('Meta 廣告帳戶幣別格式無效，請重新連結帳戶。');
        }

        $parameters = [
            'fields' => 'date_start,date_stop,account_id,campaign_id,campaign_name,spend,impressions,reach,clicks,actions,action_values,account_currency',
            'level' => 'campaign',
            'filtering' => json_encode([[
                'field' => 'campaign.id',
                'operator' => 'EQUAL',
                'value' => $campaignId,
            ]], JSON_THROW_ON_ERROR),
            'time_range' => json_encode([
                'since' => $startsOn,
                'until' => $endsOn,
            ], JSON_THROW_ON_ERROR),
            'time_increment' => 1,
            'limit' => self::DAILY_INSIGHTS_PAGE_LIMIT,
        ];
        $dailyInsightsByDate = [];
        $seenCursors = [];
        $afterCursor = null;

        for ($pageNumber = 1; $pageNumber <= self::MAX_DAILY_INSIGHTS_PAGES; $pageNumber++) {
            $pageParameters = $parameters;

            if ($afterCursor !== null) {
                $pageParameters['after'] = $afterCursor;
            }

            $response = ReadOnlyApiRequest::forBearerToken($accessToken, 20, retryRateLimitedResponses: false)
                ->get($this->graphUrl('act_'.$adAccountId.'/insights'), $pageParameters);
            $payload = $this->successfulPayload($response, 'Meta Ads Insights API');
            $rows = $payload['data'] ?? null;

            if (! is_array($rows) || ! array_is_list($rows)) {
                throw new MetaMarketingApiException('Meta Ads Insights API 回傳的每日成效格式無效。');
            }

            foreach ($rows as $row) {
                if (! is_array($row)) {
                    throw new MetaMarketingApiException('Meta Ads Insights API 回傳的每日成效格式無效。');
                }

                $this->assertDailyInsightIdentity($row, $adAccountId, $campaignId, $accountCurrency);
                $date = $row['date_start'] ?? null;
                $dateStop = $row['date_stop'] ?? null;

                if (! is_string($date)
                    || ! $this->isCalendarDate($date)
                    || $date !== $dateStop
                    || $date < $startsOn
                    || $date > $endsOn) {
                    throw new MetaMarketingApiException('Meta Ads Insights API 回傳的每日日期無效。');
                }

                if (array_key_exists($date, $dailyInsightsByDate)) {
                    throw new MetaMarketingApiException('Meta Ads Insights API 回傳重複的每日日期。');
                }

                $campaignName = $row['campaign_name'] ?? null;

                if (! is_string($campaignName) || trim($campaignName) === '') {
                    throw new MetaMarketingApiException('Meta Ads Insights API 回傳的活動名稱無效。');
                }

                $dailyInsightsByDate[$date] = [
                    'date' => $date,
                    'account_id' => $adAccountId,
                    'campaign_id' => $campaignId,
                    'campaign_name' => trim($campaignName),
                    'spend_amount' => $this->decimalString($row['spend'] ?? null),
                    'impressions' => $this->nonNegativeInteger($row['impressions'] ?? null),
                    'reach' => $this->nonNegativeInteger($row['reach'] ?? null),
                    'clicks' => $this->nonNegativeInteger($row['clicks'] ?? null),
                    'actions' => $this->actionMetrics($row['actions'] ?? null, 'actions'),
                    'action_values' => $this->actionMetrics($row['action_values'] ?? null, 'action_values'),
                    'currency_code' => $accountCurrency,
                ];
            }

            $nextPage = $payload['paging']['next'] ?? null;

            if ($nextPage === null || $nextPage === '') {
                ksort($dailyInsightsByDate);

                return array_values($dailyInsightsByDate);
            }

            $nextCursor = $payload['paging']['cursors']['after'] ?? null;

            if (! is_string($nextPage) || ! is_string($nextCursor) || trim($nextCursor) === '') {
                throw new MetaMarketingApiException('Meta Ads Insights API 回傳的每日分頁資訊無效。');
            }

            if (array_key_exists($nextCursor, $seenCursors)) {
                throw new MetaMarketingApiException('Meta Ads Insights API 回傳重複的每日分頁游標。');
            }

            if ($pageNumber === self::MAX_DAILY_INSIGHTS_PAGES) {
                throw new MetaMarketingApiException('Meta Ads Insights API 日資料超過分頁上限，未回傳完整結果。');
            }

            $seenCursors[$nextCursor] = true;
            $afterCursor = $nextCursor;
        }

        throw new MetaMarketingApiException('Meta Ads Insights API 日資料超過分頁上限，未回傳完整結果。');
    }

    /**
     * @return array{
     *     rows: list<array{
     *         ad_id: string,
     *         ad_name: string,
     *         adset_id: string,
     *         adset_name: string,
     *         spend_amount: string,
     *         impressions: int,
     *         reach: int,
     *         clicks: int,
     *         actions: list<array{action_type: string, value: string}>,
     *         action_values: list<array{action_type: string, value: string}>,
     *         video_play_actions: list<array{action_type: string, value: string}>,
     *         video_thruplay_watched_actions: list<array{action_type: string, value: string}>,
     *         video_p25_watched_actions: list<array{action_type: string, value: string}>,
     *         video_p50_watched_actions: list<array{action_type: string, value: string}>,
     *         video_p75_watched_actions: list<array{action_type: string, value: string}>,
     *         video_p95_watched_actions: list<array{action_type: string, value: string}>,
     *         video_p100_watched_actions: list<array{action_type: string, value: string}>
     *     }>,
     *     truncated: bool
     * }
     */
    public function campaignAdInsights(
        string $accessToken,
        string $adAccountId,
        string $campaignId,
        string $startsOn,
        string $endsOn,
        string $accountCurrency,
    ): array {
        if (preg_match('/^\d{5,32}$/', $adAccountId) !== 1 || preg_match('/^\d{5,32}$/', $campaignId) !== 1) {
            throw new MetaMarketingApiException('Meta 廣告帳戶或活動 ID 格式無效。');
        }

        if (! $this->isCalendarDate($startsOn)
            || ! $this->isCalendarDate($endsOn)
            || $startsOn > $endsOn) {
            throw new MetaMarketingApiException('Meta 活動日期區間無效，請重新設定開始日與結束日。');
        }

        if (preg_match('/^[A-Z]{3}$/', $accountCurrency) !== 1) {
            throw new MetaMarketingApiException('Meta 廣告帳戶幣別格式無效，請重新連結帳戶。');
        }

        $parameters = [
            'fields' => 'account_id,campaign_id,adset_id,adset_name,ad_id,ad_name,spend,impressions,reach,clicks,actions,action_values,video_play_actions,video_thruplay_watched_actions,video_p25_watched_actions,video_p50_watched_actions,video_p75_watched_actions,video_p95_watched_actions,video_p100_watched_actions,account_currency',
            'level' => 'ad',
            'filtering' => json_encode([[
                'field' => 'campaign.id',
                'operator' => 'EQUAL',
                'value' => $campaignId,
            ]], JSON_THROW_ON_ERROR),
            'time_range' => json_encode([
                'since' => $startsOn,
                'until' => $endsOn,
            ], JSON_THROW_ON_ERROR),
            'limit' => self::AD_INSIGHTS_PAGE_LIMIT,
        ];
        $insightsByAdId = [];
        $seenCursors = [];
        $afterCursor = null;

        for ($pageNumber = 1; $pageNumber <= self::MAX_AD_INSIGHTS_PAGES; $pageNumber++) {
            $pageParameters = $parameters;

            if ($afterCursor !== null) {
                $pageParameters['after'] = $afterCursor;
            }

            $response = ReadOnlyApiRequest::forBearerToken($accessToken, 20, retryRateLimitedResponses: false)
                ->get($this->graphUrl('act_'.$adAccountId.'/insights'), $pageParameters);
            $payload = $this->successfulPayload($response, 'Meta Ads Insights API');
            $rows = $payload['data'] ?? null;

            if (! is_array($rows) || ! array_is_list($rows)) {
                throw new MetaMarketingApiException('Meta Ads Insights API 回傳的廣告成效格式無效。');
            }

            foreach ($rows as $row) {
                if (! is_array($row)) {
                    throw new MetaMarketingApiException('Meta Ads Insights API 回傳的廣告成效格式無效。');
                }

                $this->assertAdInsightIdentity($row, $adAccountId, $campaignId, $accountCurrency);
                $adId = $row['ad_id'];
                $adName = $row['ad_name'] ?? null;
                $adsetId = $row['adset_id'] ?? null;
                $adsetName = $row['adset_name'] ?? null;

                if (! is_string($adName)
                    || trim($adName) === ''
                    || (! is_string($adsetId) && ! is_int($adsetId))
                    || preg_match('/^\d{1,32}$/', (string) $adsetId) !== 1
                    || ! is_string($adsetName)
                    || trim($adsetName) === '') {
                    throw new MetaMarketingApiException('Meta Ads Insights API 回傳的廣告或廣告組合名稱無效。');
                }

                if (array_key_exists($adId, $insightsByAdId)) {
                    throw new MetaMarketingApiException('Meta Ads Insights API 回傳重複的廣告資料。');
                }

                $insightsByAdId[$adId] = [
                    'ad_id' => $adId,
                    'ad_name' => trim($adName),
                    'adset_id' => (string) $adsetId,
                    'adset_name' => trim($adsetName),
                    'spend_amount' => $this->decimalString($row['spend'] ?? null),
                    'impressions' => $this->nonNegativeInteger($row['impressions'] ?? null),
                    'reach' => $this->nonNegativeInteger($row['reach'] ?? null),
                    'clicks' => $this->nonNegativeInteger($row['clicks'] ?? null),
                    'actions' => $this->actionMetrics($row['actions'] ?? null, 'actions'),
                    'action_values' => $this->actionMetrics($row['action_values'] ?? null, 'action_values'),
                    'video_play_actions' => $this->actionMetrics($row['video_play_actions'] ?? null, 'video_play_actions'),
                    'video_thruplay_watched_actions' => $this->actionMetrics($row['video_thruplay_watched_actions'] ?? null, 'video_thruplay_watched_actions'),
                    'video_p25_watched_actions' => $this->actionMetrics($row['video_p25_watched_actions'] ?? null, 'video_p25_watched_actions'),
                    'video_p50_watched_actions' => $this->actionMetrics($row['video_p50_watched_actions'] ?? null, 'video_p50_watched_actions'),
                    'video_p75_watched_actions' => $this->actionMetrics($row['video_p75_watched_actions'] ?? null, 'video_p75_watched_actions'),
                    'video_p95_watched_actions' => $this->actionMetrics($row['video_p95_watched_actions'] ?? null, 'video_p95_watched_actions'),
                    'video_p100_watched_actions' => $this->actionMetrics($row['video_p100_watched_actions'] ?? null, 'video_p100_watched_actions'),
                ];
            }

            $nextPage = $payload['paging']['next'] ?? null;

            if ($nextPage === null || $nextPage === '') {
                $allInsights = array_values($insightsByAdId);
                usort($allInsights, static function (array $first, array $second): int {
                    $spendOrder = (float) $second['spend_amount'] <=> (float) $first['spend_amount'];

                    return $spendOrder !== 0 ? $spendOrder : strcmp($first['ad_id'], $second['ad_id']);
                });

                return [
                    'rows' => array_slice($allInsights, 0, self::AD_INSIGHTS_REPORT_LIMIT),
                    'truncated' => count($allInsights) > self::AD_INSIGHTS_REPORT_LIMIT,
                ];
            }

            $nextCursor = $payload['paging']['cursors']['after'] ?? null;

            if (! is_string($nextPage) || ! is_string($nextCursor) || trim($nextCursor) === '') {
                throw new MetaMarketingApiException('Meta Ads Insights API 回傳的廣告分頁資訊無效。');
            }

            if (array_key_exists($nextCursor, $seenCursors)) {
                throw new MetaMarketingApiException('Meta Ads Insights API 回傳重複的廣告分頁游標。');
            }

            if ($pageNumber === self::MAX_AD_INSIGHTS_PAGES) {
                throw new MetaMarketingApiException('Meta Ads Insights API 廣告資料超過分頁上限，未回傳不完整結果。');
            }

            $seenCursors[$nextCursor] = true;
            $afterCursor = $nextCursor;
        }

        throw new MetaMarketingApiException('Meta Ads Insights API 廣告資料超過分頁上限，未回傳不完整結果。');
    }

    /**
     * @return array{
     *     rows: list<array{
     *         adset_id: string,
     *         adset_name: string,
     *         spend_amount: string,
     *         impressions: int,
     *         reach: int,
     *         clicks: int,
     *         actions: list<array{action_type: string, value: string}>,
     *         action_values: list<array{action_type: string, value: string}>
     *     }>,
     *     truncated: bool
     * }
     */
    public function campaignAdSetInsights(
        string $accessToken,
        string $adAccountId,
        string $campaignId,
        string $startsOn,
        string $endsOn,
        string $accountCurrency,
    ): array {
        if (preg_match('/^\d{5,32}$/', $adAccountId) !== 1 || preg_match('/^\d{5,32}$/', $campaignId) !== 1) {
            throw new MetaMarketingApiException('Meta 廣告帳戶或活動 ID 格式無效。');
        }

        if (! $this->isCalendarDate($startsOn)
            || ! $this->isCalendarDate($endsOn)
            || $startsOn > $endsOn) {
            throw new MetaMarketingApiException('Meta 活動日期區間無效，請重新設定開始日與結束日。');
        }

        if (preg_match('/^[A-Z]{3}$/', $accountCurrency) !== 1) {
            throw new MetaMarketingApiException('Meta 廣告帳戶幣別格式無效，請重新連結帳戶。');
        }

        $parameters = [
            'fields' => 'account_id,campaign_id,adset_id,adset_name,spend,impressions,reach,clicks,actions,action_values,account_currency',
            'level' => 'adset',
            'filtering' => json_encode([[
                'field' => 'campaign.id',
                'operator' => 'EQUAL',
                'value' => $campaignId,
            ]], JSON_THROW_ON_ERROR),
            'time_range' => json_encode([
                'since' => $startsOn,
                'until' => $endsOn,
            ], JSON_THROW_ON_ERROR),
            'limit' => self::AD_INSIGHTS_PAGE_LIMIT,
        ];
        $insightsByAdSetId = [];
        $seenCursors = [];
        $afterCursor = null;

        for ($pageNumber = 1; $pageNumber <= self::MAX_AD_INSIGHTS_PAGES; $pageNumber++) {
            $pageParameters = $parameters;

            if ($afterCursor !== null) {
                $pageParameters['after'] = $afterCursor;
            }

            $response = ReadOnlyApiRequest::forBearerToken($accessToken, 20, retryRateLimitedResponses: false)
                ->get($this->graphUrl('act_'.$adAccountId.'/insights'), $pageParameters);
            $payload = $this->successfulPayload($response, 'Meta Ads Insights API');
            $rows = $payload['data'] ?? null;

            if (! is_array($rows) || ! array_is_list($rows)) {
                throw new MetaMarketingApiException('Meta Ads Insights API 回傳的廣告組合成效格式無效。');
            }

            foreach ($rows as $row) {
                if (! is_array($row)) {
                    throw new MetaMarketingApiException('Meta Ads Insights API 回傳的廣告組合成效格式無效。');
                }

                $this->assertAdSetInsightIdentity($row, $adAccountId, $campaignId, $accountCurrency);
                $adSetId = (string) $row['adset_id'];
                $adSetName = $row['adset_name'] ?? null;

                if (! is_string($adSetName) || trim($adSetName) === '') {
                    throw new MetaMarketingApiException('Meta Ads Insights API 回傳的廣告組合名稱無效。');
                }

                if (array_key_exists($adSetId, $insightsByAdSetId)) {
                    throw new MetaMarketingApiException('Meta Ads Insights API 回傳重複的廣告組合資料。');
                }

                $insightsByAdSetId[$adSetId] = [
                    'adset_id' => $adSetId,
                    'adset_name' => trim($adSetName),
                    'spend_amount' => $this->decimalString($row['spend'] ?? null),
                    'impressions' => $this->nonNegativeInteger($row['impressions'] ?? null),
                    'reach' => $this->nonNegativeInteger($row['reach'] ?? null),
                    'clicks' => $this->nonNegativeInteger($row['clicks'] ?? null),
                    'actions' => $this->actionMetrics($row['actions'] ?? null, 'actions'),
                    'action_values' => $this->actionMetrics($row['action_values'] ?? null, 'action_values'),
                ];
            }

            $nextPage = $payload['paging']['next'] ?? null;

            if ($nextPage === null || $nextPage === '') {
                $allInsights = array_values($insightsByAdSetId);
                usort($allInsights, static function (array $first, array $second): int {
                    $spendOrder = (float) $second['spend_amount'] <=> (float) $first['spend_amount'];

                    return $spendOrder !== 0 ? $spendOrder : strcmp($first['adset_id'], $second['adset_id']);
                });

                return [
                    'rows' => array_slice($allInsights, 0, self::AD_INSIGHTS_REPORT_LIMIT),
                    'truncated' => count($allInsights) > self::AD_INSIGHTS_REPORT_LIMIT,
                ];
            }

            $nextCursor = $payload['paging']['cursors']['after'] ?? null;

            if (! is_string($nextPage) || ! is_string($nextCursor) || trim($nextCursor) === '') {
                throw new MetaMarketingApiException('Meta Ads Insights API 回傳的廣告組合分頁資訊無效。');
            }

            if (array_key_exists($nextCursor, $seenCursors)) {
                throw new MetaMarketingApiException('Meta Ads Insights API 回傳重複的廣告組合分頁游標。');
            }

            if ($pageNumber === self::MAX_AD_INSIGHTS_PAGES) {
                throw new MetaMarketingApiException('Meta Ads Insights API 廣告組合資料超過分頁上限，未回傳不完整結果。');
            }

            $seenCursors[$nextCursor] = true;
            $afterCursor = $nextCursor;
        }

        throw new MetaMarketingApiException('Meta Ads Insights API 廣告組合資料超過分頁上限，未回傳不完整結果。');
    }

    /** @param array<string, mixed> $row */
    private function assertAdSetInsightIdentity(
        array $row,
        string $adAccountId,
        string $campaignId,
        string $accountCurrency,
    ): void {
        $returnedAccountId = $row['account_id'] ?? null;
        $returnedCampaignId = $row['campaign_id'] ?? null;
        $adSetId = $row['adset_id'] ?? null;

        if ((! is_string($returnedAccountId) && ! is_int($returnedAccountId))
            || (string) $returnedAccountId !== $adAccountId
            || (! is_string($returnedCampaignId) && ! is_int($returnedCampaignId))
            || (string) $returnedCampaignId !== $campaignId
            || (! is_string($adSetId) && ! is_int($adSetId))
            || preg_match('/^\d{1,32}$/', (string) $adSetId) !== 1) {
            throw new MetaMarketingApiException('Meta Ads Insights API 回傳了不同帳戶、活動或無效廣告組合 ID 的資料。');
        }

        $currencyCode = $row['account_currency'] ?? null;

        if (! is_string($currencyCode)
            || preg_match('/^[A-Z]{3}$/', $currencyCode) !== 1
            || $currencyCode !== $accountCurrency) {
            throw new MetaMarketingApiException('Meta Ads Insights API 回傳了不同幣別的廣告組合資料。');
        }
    }

    /**
     * @return array{
     *     rows: list<array{
     *         publisher_platform: string,
     *         platform_position: string,
     *         spend_amount: string,
     *         impressions: int,
     *         reach: int|null,
     *         clicks: int,
     *         actions: list<array{action_type: string, value: string}>,
     *         action_values: list<array{action_type: string, value: string}>
     *     }>,
     *     truncated: bool
     * }
     */
    public function campaignPlacementInsights(
        string $accessToken,
        string $adAccountId,
        string $campaignId,
        string $startsOn,
        string $endsOn,
        string $accountCurrency,
    ): array {
        if (preg_match('/^\d{5,32}$/', $adAccountId) !== 1 || preg_match('/^\d{5,32}$/', $campaignId) !== 1) {
            throw new MetaMarketingApiException('Meta 廣告帳戶或活動 ID 格式無效。');
        }

        if (! $this->isCalendarDate($startsOn)
            || ! $this->isCalendarDate($endsOn)
            || $startsOn > $endsOn) {
            throw new MetaMarketingApiException('Meta 活動日期區間無效，請重新設定開始日與結束日。');
        }

        if (preg_match('/^[A-Z]{3}$/', $accountCurrency) !== 1) {
            throw new MetaMarketingApiException('Meta 廣告帳戶幣別格式無效，請重新連結帳戶。');
        }

        $parameters = [
            'fields' => 'account_id,campaign_id,publisher_platform,platform_position,spend,impressions,reach,clicks,actions,action_values,account_currency',
            'level' => 'campaign',
            'breakdowns' => 'publisher_platform,platform_position',
            'filtering' => json_encode([[
                'field' => 'campaign.id',
                'operator' => 'EQUAL',
                'value' => $campaignId,
            ]], JSON_THROW_ON_ERROR),
            'time_range' => json_encode([
                'since' => $startsOn,
                'until' => $endsOn,
            ], JSON_THROW_ON_ERROR),
            'limit' => self::PLACEMENT_INSIGHTS_PAGE_LIMIT,
        ];
        $insightsByPlacement = [];
        $seenCursors = [];
        $afterCursor = null;

        for ($pageNumber = 1; $pageNumber <= self::MAX_PLACEMENT_INSIGHTS_PAGES; $pageNumber++) {
            $pageParameters = $parameters;

            if ($afterCursor !== null) {
                $pageParameters['after'] = $afterCursor;
            }

            $response = ReadOnlyApiRequest::forBearerToken($accessToken, 20, retryRateLimitedResponses: false)
                ->get($this->graphUrl('act_'.$adAccountId.'/insights'), $pageParameters);
            $payload = $this->successfulPayload($response, 'Meta Ads Insights API 版位成效');
            $rows = $payload['data'] ?? null;

            if (! is_array($rows) || ! array_is_list($rows)) {
                throw new MetaMarketingApiException('Meta Ads Insights API 回傳的版位成效格式無效。');
            }

            foreach ($rows as $row) {
                if (! is_array($row)) {
                    throw new MetaMarketingApiException('Meta Ads Insights API 回傳的版位成效格式無效。');
                }

                $this->assertDailyInsightIdentity($row, $adAccountId, $campaignId, $accountCurrency);
                $publisherPlatform = $row['publisher_platform'] ?? null;
                $platformPosition = $row['platform_position'] ?? null;

                if (! is_string($publisherPlatform)
                    || trim($publisherPlatform) === ''
                    || mb_strlen($publisherPlatform) > 100
                    || ! is_string($platformPosition)
                    || trim($platformPosition) === ''
                    || mb_strlen($platformPosition) > 100) {
                    throw new MetaMarketingApiException('Meta Ads Insights API 回傳的版位名稱無效。');
                }

                $placementKey = json_encode([
                    mb_strtolower(trim($publisherPlatform)),
                    mb_strtolower(trim($platformPosition)),
                ], JSON_THROW_ON_ERROR);

                if (array_key_exists($placementKey, $insightsByPlacement)) {
                    throw new MetaMarketingApiException('Meta Ads Insights API 回傳重複的版位資料。');
                }

                $insightsByPlacement[$placementKey] = [
                    'publisher_platform' => trim($publisherPlatform),
                    'platform_position' => trim($platformPosition),
                    'spend_amount' => $this->decimalString($row['spend'] ?? null),
                    'impressions' => $this->nonNegativeInteger($row['impressions'] ?? null),
                    'reach' => ($row['reach'] ?? null) === null ? null : $this->nonNegativeInteger($row['reach']),
                    'clicks' => $this->nonNegativeInteger($row['clicks'] ?? null),
                    'actions' => $this->actionMetrics($row['actions'] ?? null, 'actions'),
                    'action_values' => $this->actionMetrics($row['action_values'] ?? null, 'action_values'),
                ];
            }

            $nextPage = $payload['paging']['next'] ?? null;

            if ($nextPage === null || $nextPage === '') {
                $allInsights = array_values($insightsByPlacement);
                usort($allInsights, static function (array $first, array $second): int {
                    $spendOrder = (float) $second['spend_amount'] <=> (float) $first['spend_amount'];

                    if ($spendOrder !== 0) {
                        return $spendOrder;
                    }

                    $platformOrder = strcmp($first['publisher_platform'], $second['publisher_platform']);

                    return $platformOrder !== 0
                        ? $platformOrder
                        : strcmp($first['platform_position'], $second['platform_position']);
                });

                return [
                    'rows' => array_slice($allInsights, 0, self::PLACEMENT_INSIGHTS_REPORT_LIMIT),
                    'truncated' => count($allInsights) > self::PLACEMENT_INSIGHTS_REPORT_LIMIT,
                ];
            }

            $nextCursor = $payload['paging']['cursors']['after'] ?? null;

            if (! is_string($nextPage) || ! is_string($nextCursor) || trim($nextCursor) === '') {
                throw new MetaMarketingApiException('Meta Ads Insights API 回傳的版位分頁資訊無效。');
            }

            if (array_key_exists($nextCursor, $seenCursors)) {
                throw new MetaMarketingApiException('Meta Ads Insights API 回傳重複的版位分頁游標。');
            }

            if ($pageNumber === self::MAX_PLACEMENT_INSIGHTS_PAGES) {
                throw new MetaMarketingApiException('Meta Ads Insights API 版位資料超過分頁上限，未回傳不完整結果。');
            }

            $seenCursors[$nextCursor] = true;
            $afterCursor = $nextCursor;
        }

        throw new MetaMarketingApiException('Meta Ads Insights API 版位資料超過分頁上限，未回傳不完整結果。');
    }

    /**
     * @return array{
     *     rows: list<array{
     *         age: string,
     *         gender: string,
     *         spend_amount: string,
     *         impressions: int,
     *         reach: int|null,
     *         clicks: int,
     *         actions: list<array{action_type: string, value: string}>,
     *         action_values: list<array{action_type: string, value: string}>
     *     }>,
     *     truncated: bool
     * }
     */
    public function campaignDemographicInsights(
        string $accessToken,
        string $adAccountId,
        string $campaignId,
        string $startsOn,
        string $endsOn,
        string $accountCurrency,
    ): array {
        if (preg_match('/^\d{5,32}$/', $adAccountId) !== 1 || preg_match('/^\d{5,32}$/', $campaignId) !== 1) {
            throw new MetaMarketingApiException('Meta 廣告帳戶或活動 ID 格式無效。');
        }

        if (! $this->isCalendarDate($startsOn)
            || ! $this->isCalendarDate($endsOn)
            || $startsOn > $endsOn) {
            throw new MetaMarketingApiException('Meta 活動日期區間無效，請重新設定開始日與結束日。');
        }

        if (preg_match('/^[A-Z]{3}$/', $accountCurrency) !== 1) {
            throw new MetaMarketingApiException('Meta 廣告帳戶幣別格式無效，請重新連結帳戶。');
        }

        $parameters = [
            'fields' => 'account_id,campaign_id,age,gender,spend,impressions,reach,clicks,actions,action_values,account_currency',
            'level' => 'campaign',
            'breakdowns' => 'age,gender',
            'filtering' => json_encode([[
                'field' => 'campaign.id',
                'operator' => 'EQUAL',
                'value' => $campaignId,
            ]], JSON_THROW_ON_ERROR),
            'time_range' => json_encode([
                'since' => $startsOn,
                'until' => $endsOn,
            ], JSON_THROW_ON_ERROR),
            'limit' => self::DEMOGRAPHIC_INSIGHTS_PAGE_LIMIT,
        ];
        $insightsByDemographic = [];
        $seenCursors = [];
        $afterCursor = null;

        for ($pageNumber = 1; $pageNumber <= self::MAX_DEMOGRAPHIC_INSIGHTS_PAGES; $pageNumber++) {
            $pageParameters = $parameters;

            if ($afterCursor !== null) {
                $pageParameters['after'] = $afterCursor;
            }

            $response = ReadOnlyApiRequest::forBearerToken($accessToken, 20, retryRateLimitedResponses: false)
                ->get($this->graphUrl('act_'.$adAccountId.'/insights'), $pageParameters);
            $payload = $this->successfulPayload($response, 'Meta Ads Insights API 年齡與性別成效');
            $rows = $payload['data'] ?? null;

            if (! is_array($rows) || ! array_is_list($rows)) {
                throw new MetaMarketingApiException('Meta Ads Insights API 回傳的年齡與性別成效格式無效。');
            }

            foreach ($rows as $row) {
                if (! is_array($row)) {
                    throw new MetaMarketingApiException('Meta Ads Insights API 回傳的年齡與性別成效格式無效。');
                }

                $this->assertDailyInsightIdentity($row, $adAccountId, $campaignId, $accountCurrency);
                $age = $row['age'] ?? null;
                $gender = $row['gender'] ?? null;

                if (! is_string($age)
                    || trim($age) === ''
                    || mb_strlen($age) > 50
                    || ! is_string($gender)
                    || trim($gender) === ''
                    || mb_strlen($gender) > 50) {
                    throw new MetaMarketingApiException('Meta Ads Insights API 回傳的年齡或性別欄位無效。');
                }

                $demographicKey = json_encode([
                    mb_strtolower(trim($age)),
                    mb_strtolower(trim($gender)),
                ], JSON_THROW_ON_ERROR);

                if (array_key_exists($demographicKey, $insightsByDemographic)) {
                    throw new MetaMarketingApiException('Meta Ads Insights API 回傳重複的年齡與性別資料。');
                }

                $insightsByDemographic[$demographicKey] = [
                    'age' => trim($age),
                    'gender' => trim($gender),
                    'spend_amount' => $this->decimalString($row['spend'] ?? null),
                    'impressions' => $this->nonNegativeInteger($row['impressions'] ?? null),
                    'reach' => ($row['reach'] ?? null) === null ? null : $this->nonNegativeInteger($row['reach']),
                    'clicks' => $this->nonNegativeInteger($row['clicks'] ?? null),
                    'actions' => $this->actionMetrics($row['actions'] ?? null, 'actions'),
                    'action_values' => $this->actionMetrics($row['action_values'] ?? null, 'action_values'),
                ];
            }

            $nextPage = $payload['paging']['next'] ?? null;

            if ($nextPage === null || $nextPage === '') {
                $allInsights = array_values($insightsByDemographic);
                usort($allInsights, static function (array $first, array $second): int {
                    $spendOrder = (float) $second['spend_amount'] <=> (float) $first['spend_amount'];

                    if ($spendOrder !== 0) {
                        return $spendOrder;
                    }

                    $ageOrder = strcmp($first['age'], $second['age']);

                    return $ageOrder !== 0 ? $ageOrder : strcmp($first['gender'], $second['gender']);
                });

                return [
                    'rows' => array_slice($allInsights, 0, self::DEMOGRAPHIC_INSIGHTS_REPORT_LIMIT),
                    'truncated' => count($allInsights) > self::DEMOGRAPHIC_INSIGHTS_REPORT_LIMIT,
                ];
            }

            $nextCursor = $payload['paging']['cursors']['after'] ?? null;

            if (! is_string($nextPage) || ! is_string($nextCursor) || trim($nextCursor) === '') {
                throw new MetaMarketingApiException('Meta Ads Insights API 回傳的年齡與性別分頁資訊無效。');
            }

            if (array_key_exists($nextCursor, $seenCursors)) {
                throw new MetaMarketingApiException('Meta Ads Insights API 回傳重複的年齡與性別分頁游標。');
            }

            if ($pageNumber === self::MAX_DEMOGRAPHIC_INSIGHTS_PAGES) {
                throw new MetaMarketingApiException('Meta Ads Insights API 年齡與性別資料超過分頁上限，未回傳不完整結果。');
            }

            $seenCursors[$nextCursor] = true;
            $afterCursor = $nextCursor;
        }

        throw new MetaMarketingApiException('Meta Ads Insights API 年齡與性別資料超過分頁上限，未回傳不完整結果。');
    }

    /** @param array<string, mixed> $row */
    private function assertAdInsightIdentity(
        array $row,
        string $adAccountId,
        string $campaignId,
        string $accountCurrency,
    ): void {
        $returnedAccountId = $row['account_id'] ?? null;
        $returnedCampaignId = $row['campaign_id'] ?? null;
        $adId = $row['ad_id'] ?? null;

        if ((! is_string($returnedAccountId) && ! is_int($returnedAccountId))
            || (string) $returnedAccountId !== $adAccountId
            || (! is_string($returnedCampaignId) && ! is_int($returnedCampaignId))
            || (string) $returnedCampaignId !== $campaignId
            || (! is_string($adId) && ! is_int($adId))
            || preg_match('/^\d{1,32}$/', (string) $adId) !== 1) {
            throw new MetaMarketingApiException('Meta Ads Insights API 回傳了不同帳戶、活動或無效廣告 ID 的資料。');
        }

        $currencyCode = $row['account_currency'] ?? null;

        if (! is_string($currencyCode)
            || preg_match('/^[A-Z]{3}$/', $currencyCode) !== 1
            || $currencyCode !== $accountCurrency) {
            throw new MetaMarketingApiException('Meta Ads Insights API 回傳了不同幣別的廣告資料。');
        }
    }

    /** @return list<array{action_type: string, value: string}> */
    private function actionMetrics(mixed $metrics, string $field): array
    {
        if ($metrics === null) {
            return [];
        }

        if (! is_array($metrics) || ! array_is_list($metrics)) {
            throw new MetaMarketingApiException('Meta Ads Insights API 回傳的 '.$field.' 格式無效。');
        }

        $normalizedMetrics = [];

        foreach ($metrics as $metric) {
            if (! is_array($metric)
                || ! is_string($metric['action_type'] ?? null)
                || trim($metric['action_type']) === '') {
                throw new MetaMarketingApiException('Meta Ads Insights API 回傳的 '.$field.' 行動類型無效。');
            }

            $normalizedMetrics[] = [
                'action_type' => trim($metric['action_type']),
                'value' => $this->decimalString($metric['value'] ?? null),
            ];
        }

        return $normalizedMetrics;
    }

    /** @param array<string, mixed> $row */
    private function assertDailyInsightIdentity(
        array $row,
        string $adAccountId,
        string $campaignId,
        string $accountCurrency,
    ): void {
        $returnedAccountId = $row['account_id'] ?? null;
        $returnedCampaignId = $row['campaign_id'] ?? null;

        if ((! is_string($returnedAccountId) && ! is_int($returnedAccountId))
            || (string) $returnedAccountId !== $adAccountId
            || (! is_string($returnedCampaignId) && ! is_int($returnedCampaignId))
            || (string) $returnedCampaignId !== $campaignId) {
            throw new MetaMarketingApiException('Meta Ads Insights API 回傳了不同廣告帳戶或活動的資料。');
        }

        $currencyCode = $row['account_currency'] ?? null;

        if (! is_string($currencyCode)
            || preg_match('/^[A-Z]{3}$/', $currencyCode) !== 1
            || $currencyCode !== $accountCurrency) {
            throw new MetaMarketingApiException('Meta Ads Insights API 回傳了不同幣別的活動資料。');
        }
    }

    /**
     * @return array{
     *     daily_metrics: list<array{
     *         date: string,
     *         account_id: string,
     *         campaign_id: string,
     *         campaign_name: string,
     *         spend_amount: string,
     *         impressions: int,
     *         reach: int,
     *         clicks: int,
     *         actions: list<array{action_type: string, value: string}>,
     *         action_values: list<array{action_type: string, value: string}>,
     *         currency_code: string
     *     }>,
     *     daily_metrics_status: 'complete',
     *     daily_metrics_coverage_start_on: string,
     *     daily_metrics_coverage_end_on: string
     * }
     */
    private function dailyMetrics(
        string $accessToken,
        string $adAccountId,
        string $campaignId,
        string $startsOn,
        string $endsOn,
        string $accountCurrency,
    ): array {
        return [
            'daily_metrics' => $this->campaignDailyInsights(
                $accessToken,
                $adAccountId,
                $campaignId,
                $startsOn,
                $endsOn,
                $accountCurrency,
            ),
            'daily_metrics_status' => 'complete',
            'daily_metrics_coverage_start_on' => $startsOn,
            'daily_metrics_coverage_end_on' => $endsOn,
        ];
    }

    private function isCalendarDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date instanceof \DateTimeImmutable && $date->format('Y-m-d') === $value;
    }

    public function revokeAccessToken(string $accessToken): void
    {
        $response = Http::withToken($accessToken)
            ->timeout(10)
            ->delete($this->graphUrl('me/permissions'));

        if (! $response->successful()) {
            throw new MetaMarketingApiException('Meta 尚未確認授權撤銷，連線仍保留，請稍後再試。');
        }
    }

    /** @param array<string, string> $parameters
     * @return array{access_token: string, expires_in: int|null}
     */
    private function exchangeToken(array $parameters): array
    {
        $response = Http::acceptJson()
            ->timeout(10)
            ->get($this->graphUrl('oauth/access_token'), $parameters);
        $payload = $this->successfulPayload($response, 'Meta OAuth');
        $accessToken = $payload['access_token'] ?? null;
        $expiresIn = $payload['expires_in'] ?? null;

        if (! is_string($accessToken) || $accessToken === '') {
            throw new MetaMarketingApiException('Meta 授權未回傳有效的存取權杖，請重新連結。');
        }

        return [
            'access_token' => $accessToken,
            'expires_in' => is_numeric($expiresIn) && (int) $expiresIn > 0 ? (int) $expiresIn : null,
        ];
    }

    /** @return array<string, mixed> */
    private function successfulPayload(Response $response, string $serviceName): array
    {
        if (! $response->successful()) {
            $errorCode = $response->json('error.code');
            $normalizedErrorCode = is_numeric($errorCode) ? (int) $errorCode : null;
            $errorSubcode = $response->json('error.error_subcode');
            $normalizedErrorSubcode = is_numeric($errorSubcode) ? (int) $errorSubcode : null;
            $isRateLimited = $response->status() === 429
                || in_array($normalizedErrorCode, [4, 17, 32, 341, 613], true)
                || ($normalizedErrorCode === 80000 && $normalizedErrorSubcode === 2446079);
            $message = match (true) {
                $normalizedErrorCode === 190 => 'Meta 授權已失效，請重新連結帳戶。',
                $normalizedErrorCode === 200 || $normalizedErrorCode === 10 => 'Meta 權限不足，請重新連結並授予 ads_read 權限。',
                $isRateLimited => 'Meta API 配額已用盡，請稍後再同步。',
                default => $serviceName.' 暫時無法讀取，請稍後再試。',
            };

            throw new MetaMarketingApiException($message);
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            throw new MetaMarketingApiException($serviceName.' 回傳格式無效，請稍後再試。');
        }

        return $payload;
    }

    private function nonNegativeInteger(mixed $value): int
    {
        if (! is_string($value) && ! is_int($value)) {
            throw new MetaMarketingApiException('Meta API 回傳的數值格式無效。');
        }

        $validated = filter_var($value, FILTER_VALIDATE_INT);

        if ($validated === false || $validated < 0) {
            throw new MetaMarketingApiException('Meta API 回傳的數值格式無效。');
        }

        return $validated;
    }

    private function decimalString(mixed $value): string
    {
        if ((! is_string($value) && ! is_int($value) && ! is_float($value)) || ! is_numeric($value)) {
            throw new MetaMarketingApiException('Meta API 回傳的數值格式無效。');
        }

        $number = (float) $value;

        if (! is_finite($number) || $number < 0) {
            throw new MetaMarketingApiException('Meta API 回傳的數值格式無效。');
        }

        return PreciseDecimalFormatter::toFourDecimalPlaces($value);
    }

    private function graphUrl(string $path): string
    {
        $version = config('services.meta_marketing.api_version');
        $version = is_string($version) && preg_match('/^v\d+\.\d+$/', $version) === 1 ? $version : 'v27.0';

        return 'https://graph.facebook.com/'.$version.'/'.$path;
    }
}
