<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class MetaMarketingDataApi
{
    /** @return array{access_token: string, expires_in: int|null} */
    public function exchangeAuthorizationCode(string $code, string $redirectUri): array
    {
        $shortLivedToken = $this->exchangeToken([
            'client_id' => (string) config('services.meta_marketing.client_id'),
            'client_secret' => (string) config('services.meta_marketing.client_secret'),
            'code' => $code,
            'redirect_uri' => $redirectUri,
        ]);

        return $this->exchangeToken([
            'client_id' => (string) config('services.meta_marketing.client_id'),
            'client_secret' => (string) config('services.meta_marketing.client_secret'),
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

        $response = Http::withToken($accessToken)
            ->acceptJson()
            ->timeout(10)
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
     *     currency_code: string
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

        $response = Http::withToken($accessToken)
            ->acceptJson()
            ->timeout(20)
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
        ];
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
            $message = match (true) {
                $errorCode === 190 => 'Meta 授權已失效，請重新連結帳戶。',
                $errorCode === 200 || $errorCode === 10 => 'Meta 權限不足，請重新連結並授予 ads_read 權限。',
                $response->status() === 429 || $errorCode === 17 || $errorCode === 613 => 'Meta API 配額已用盡，請稍後再同步。',
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

        return number_format($number, 4, '.', '');
    }

    private function graphUrl(string $path): string
    {
        $version = config('services.meta_marketing.api_version');
        $version = is_string($version) && preg_match('/^v\d+\.\d+$/', $version) === 1 ? $version : 'v27.0';

        return 'https://graph.facebook.com/'.$version.'/'.$path;
    }
}
