<?php

namespace App\Services;

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

    /** @return array{campaign_id: string, campaign_name: string, spend_amount: string, impressions: int, clicks: int, conversions: string, conversions_value: string, conversion_actions: list<array{conversion_action_id: string, conversion_action_name: string, conversions: string, conversion_value: string}>, currency_code: string} */
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

        $query = "SELECT campaign.id, campaign.name, customer.id, customer.currency_code, metrics.cost_micros, metrics.impressions, metrics.clicks, metrics.conversions, metrics.conversions_value FROM campaign WHERE campaign.id = {$campaignId} AND segments.date BETWEEN '{$startsOn}' AND '{$endsOn}'";
        $payload = $this->googleAdsRequest(
            $accessToken,
            $customerId,
            'googleAds:searchStream',
            $query,
            $loginCustomerId,
        );
        $rows = $this->rowsFromStream($payload);
        if ($rows === []) {
            $conversionActions = $this->conversionActionInsights(
                $accessToken,
                $customerId,
                $campaignId,
                $startsOn,
                $endsOn,
                $loginCustomerId,
            );

            return [
                'campaign_id' => $campaignId,
                'campaign_name' => $campaignId,
                'spend_amount' => '0.0000',
                'impressions' => 0,
                'clicks' => 0,
                'conversions' => '0.0000',
                'conversions_value' => '0.0000',
                'conversion_actions' => $conversionActions,
                'currency_code' => $accountCurrency,
            ];
        }

        $row = $rows[0];
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

        $conversionActions = $this->conversionActionInsights(
            $accessToken,
            $customerId,
            $campaignId,
            $startsOn,
            $endsOn,
            $loginCustomerId,
        );

        return [
            'campaign_id' => $campaignId,
            'campaign_name' => trim($campaign['name']),
            'spend_amount' => $this->microsToDecimal($metrics['costMicros'] ?? null),
            'impressions' => $this->nonNegativeInteger($metrics['impressions'] ?? null),
            'clicks' => $this->nonNegativeInteger($metrics['clicks'] ?? null),
            'conversions' => $this->decimalString($metrics['conversions'] ?? null, false),
            'conversions_value' => $this->decimalString($metrics['conversionsValue'] ?? null, true),
            'conversion_actions' => $conversionActions,
            'currency_code' => $returnedCurrency,
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
    ): array {
        $query = "SELECT customer.id, campaign.id, segments.conversion_action, segments.conversion_action_name, metrics.conversions, metrics.conversions_value FROM campaign WHERE campaign.id = {$campaignId} AND segments.date BETWEEN '{$startsOn}' AND '{$endsOn}'";
        $payload = $this->googleAdsRequest(
            $accessToken,
            $customerId,
            'googleAds:searchStream',
            $query,
            $loginCustomerId,
        );
        $rows = $this->rowsFromStream($payload);
        $conversionActions = [];

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

            $conversionActions[] = [
                'conversion_action_id' => substr($conversionActionResourceName, strlen($expectedResourcePrefix)),
                'conversion_action_name' => trim($conversionActionName),
                'conversions' => $this->decimalString($metrics['conversions'] ?? null, false),
                'conversion_value' => $this->decimalString($metrics['conversionsValue'] ?? null, true),
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

        return number_format($number, 4, '.', '');
    }

    private function apiUrl(string $path): string
    {
        $version = config('services.google_ads.api_version');
        $version = is_string($version) && preg_match('/^v\d+$/', $version) === 1 ? $version : 'v26';

        return 'https://googleads.googleapis.com/'.$version.'/'.$path;
    }
}
