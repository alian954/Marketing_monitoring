<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class GoogleSearchConsoleDataApi
{
    /** @var list<string> */
    private const RATE_LIMIT_ERROR_REASONS = [
        'concurrentLimitExceeded',
        'dailyLimitExceeded',
        'dailyLimitExceededUnreg',
        'limitExceeded',
        'quotaExceeded',
        'rateLimitExceeded',
        'rateLimitExceededUnreg',
        'servingLimitExceeded',
        'userRateLimitExceeded',
        'userRateLimitExceededUnreg',
        'variableTermExpiredDailyExceeded',
        'variableTermLimitExceeded',
    ];

    /** @var list<string> */
    private const BILLING_LIMIT_ERROR_REASONS = [
        'dailyLimitExceeded402',
        'quotaExceeded402',
    ];

    private const SEARCH_ANALYTICS_ROW_LIMIT = 100;

    /**
     * @return array{access_token: string, refresh_token: ?string}
     */
    public function exchangeAuthorizationCode(string $code, string $redirectUri, ?int $workspaceId = null): array
    {
        $credentials = app(OAuthApplicationCredentialResolver::class)->resolve('google_search_console', $workspaceId);

        if ($credentials === null) {
            throw new GoogleSearchConsoleApiException('請先在 Search Console 整合設定輸入 OAuth 用戶端 ID 與密鑰。');
        }

        $response = Http::asForm()
            ->acceptJson()
            ->timeout(10)
            ->post('https://oauth2.googleapis.com/token', [
                'client_id' => $credentials['client_id'],
                'client_secret' => $credentials['client_secret'],
                'code' => $code,
                'grant_type' => 'authorization_code',
                'redirect_uri' => $redirectUri,
            ]);

        $payload = $this->successfulPayload($response, 'Google OAuth');
        $accessToken = $payload['access_token'] ?? null;
        $refreshToken = $payload['refresh_token'] ?? null;

        if (! is_string($accessToken) || $accessToken === '') {
            throw new GoogleSearchConsoleApiException('Google 授權未回傳存取權杖，請重新連結。');
        }

        return [
            'access_token' => $accessToken,
            'refresh_token' => is_string($refreshToken) && $refreshToken !== '' ? $refreshToken : null,
        ];
    }

    public function refreshAccessToken(string $refreshToken, ?int $workspaceId = null): string
    {
        $credentials = app(OAuthApplicationCredentialResolver::class)->resolve('google_search_console', $workspaceId);

        if ($credentials === null) {
            throw new GoogleSearchConsoleApiException('請先在 Search Console 整合設定輸入 OAuth 用戶端 ID 與密鑰。');
        }

        $response = Http::asForm()
            ->acceptJson()
            ->timeout(10)
            ->post('https://oauth2.googleapis.com/token', [
                'client_id' => $credentials['client_id'],
                'client_secret' => $credentials['client_secret'],
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken,
            ]);

        $payload = $this->successfulPayload($response, 'Google OAuth');
        $accessToken = $payload['access_token'] ?? null;

        if (! is_string($accessToken) || $accessToken === '') {
            throw new GoogleSearchConsoleApiException('Google 未回傳存取權杖，請重新連結帳戶。');
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
            throw new GoogleSearchConsoleApiException('Google 尚未確認授權撤銷，連線仍保留，請稍後再試。');
        }
    }

    /**
     * @return list<array{site_url: string, permission_level: string}>
     */
    public function sites(string $accessToken): array
    {
        $response = $this->readOnlyRequest($accessToken)
            ->get('https://www.googleapis.com/webmasters/v3/sites');

        $payload = $this->successfulPayload($response, 'Google Search Console');
        $siteEntries = $payload['siteEntry'] ?? [];

        if (! is_array($siteEntries)) {
            throw new GoogleSearchConsoleApiException('Search Console 網站清單格式無效，請稍後再試。');
        }

        $sites = [];

        foreach ($siteEntries as $siteEntry) {
            if (! is_array($siteEntry)
                || ! is_string($siteEntry['siteUrl'] ?? null)
                || ! is_string($siteEntry['permissionLevel'] ?? null)) {
                continue;
            }

            $sites[] = [
                'site_url' => $siteEntry['siteUrl'],
                'permission_level' => $siteEntry['permissionLevel'],
            ];
        }

        usort($sites, static fn (array $left, array $right): int => strcmp($left['site_url'], $right['site_url']));

        return $sites;
    }

    /**
     * @return array{
     *     starts_on: string,
     *     ends_on: string,
     *     dimension: string,
     *     search_type: string,
     *     totals: array{clicks: float, impressions: float, ctr: float, position: float},
     *     rows: list<array{label: string, clicks: float, impressions: float, ctr: float, position: float}>,
     *     rows_may_be_incomplete: bool
     * }
     */
    public function performanceReport(
        string $accessToken,
        string $siteUrl,
        string $startsOn,
        string $endsOn,
        string $dimension,
        string $searchType = 'web',
        bool $includeRows = true,
    ): array {
        if (! $this->isCalendarDate($startsOn)
            || ! $this->isCalendarDate($endsOn)
            || $startsOn > $endsOn
            || ! in_array($dimension, ['query', 'page', 'country', 'device'], true)
            || ! in_array($searchType, ['web', 'image', 'video', 'news', 'discover', 'googleNews', 'webMultimodal'], true)
            || mb_strlen($siteUrl) > 2048
            || trim($siteUrl) === '') {
            throw new GoogleSearchConsoleApiException('Search Console 報表設定無效，請確認網站、日期與報表維度。');
        }

        $totalsPayload = $this->query($accessToken, $siteUrl, $startsOn, $endsOn, null, $searchType);
        $rowsPayload = $includeRows
            ? $this->query($accessToken, $siteUrl, $startsOn, $endsOn, $dimension, $searchType)
            : ['rows' => []];
        $totalRow = $this->firstRow($totalsPayload);
        $rows = $rowsPayload['rows'] ?? [];

        if (! is_array($rows)) {
            throw new GoogleSearchConsoleApiException('Search Console 報表資料格式無效，請稍後再試。');
        }

        $reportRows = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $keys = $row['keys'] ?? null;

            if (! is_array($keys) || ! is_string($keys[0] ?? null)) {
                throw new GoogleSearchConsoleApiException('Search Console 報表列格式無效，請稍後再試。');
            }

            $reportRows[] = [
                'label' => $keys[0],
                ...$this->metrics($row),
            ];
        }

        return [
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
            'dimension' => $dimension,
            'search_type' => $searchType,
            'totals' => $this->metrics($totalRow ?? []),
            'rows' => $reportRows,
            'rows_may_be_incomplete' => count($reportRows) >= self::SEARCH_ANALYTICS_ROW_LIMIT,
        ];
    }

    /** @return array<string, mixed> */
    private function query(
        string $accessToken,
        string $siteUrl,
        string $startsOn,
        string $endsOn,
        ?string $dimension,
        string $searchType,
    ): array {
        $body = [
            'startDate' => $startsOn,
            'endDate' => $endsOn,
            'type' => $searchType,
            'dataState' => 'final',
            'rowLimit' => $dimension === null ? 1 : self::SEARCH_ANALYTICS_ROW_LIMIT,
        ];

        if ($dimension !== null) {
            $body['dimensions'] = [$dimension];
        }

        $response = $this->readOnlyRequest($accessToken)
            ->post(
                'https://www.googleapis.com/webmasters/v3/sites/'.rawurlencode($siteUrl).'/searchAnalytics/query',
                $body,
            );

        return $this->successfulPayload($response, 'Google Search Console Search Analytics API');
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>|null
     */
    private function firstRow(array $payload): ?array
    {
        $rows = $payload['rows'] ?? [];

        if (! is_array($rows)) {
            throw new GoogleSearchConsoleApiException('Search Console 彙總資料格式無效，請稍後再試。');
        }

        if ($rows === []) {
            return null;
        }

        $row = reset($rows);

        if (! is_array($row)) {
            throw new GoogleSearchConsoleApiException('Search Console 彙總資料列格式無效，請稍後再試。');
        }

        return $row;
    }

    /** @param array<string, mixed> $row
     * @return array{clicks: float, impressions: float, ctr: float, position: float}
     */
    private function metrics(array $row): array
    {
        foreach (['clicks', 'impressions', 'ctr', 'position'] as $name) {
            if (! is_numeric($row[$name] ?? null) || ! is_finite((float) $row[$name]) || (float) $row[$name] < 0) {
                if ($row === []) {
                    return ['clicks' => 0.0, 'impressions' => 0.0, 'ctr' => 0.0, 'position' => 0.0];
                }

                throw new GoogleSearchConsoleApiException('Search Console 回傳的指標格式無效，請稍後再試。');
            }
        }

        return [
            'clicks' => (float) $row['clicks'],
            'impressions' => (float) $row['impressions'],
            'ctr' => (float) $row['ctr'],
            'position' => (float) $row['position'],
        ];
    }

    /** @return array<string, mixed> */
    private function successfulPayload(Response $response, string $serviceName): array
    {
        if (! $response->successful()) {
            $isRevokedOAuthGrant = $serviceName === 'Google OAuth'
                && $response->status() === 400
                && $response->json('error') === 'invalid_grant';
            $errorReason = $response->json('error.errors.0.reason');
            $isRateLimited = $response->status() === 429
                || $response->json('error.status') === 'RESOURCE_EXHAUSTED'
                || (is_string($errorReason) && in_array($errorReason, self::RATE_LIMIT_ERROR_REASONS, true));
            $isBillingLimitReached = $response->status() === 402
                || (is_string($errorReason) && in_array($errorReason, self::BILLING_LIMIT_ERROR_REASONS, true));
            $message = $isRevokedOAuthGrant
                ? 'Google 授權憑證已失效或已撤銷，請重新連結帳戶。'
                : match (true) {
                    $isBillingLimitReached => 'Google Search Console API 費用或用量預算已達上限，請檢查 Google Cloud 帳單與配額設定。',
                    $isRateLimited => 'Google Search Console API 配額已用盡，請稍後再試。',
                    $response->status() === 400 => 'Google Search Console 拒絕此報表設定，請確認網站、日期與報表維度。',
                    $response->status() === 401 => 'Google Search Console 授權憑證無效，請重新連結帳戶並確認唯讀權限。',
                    $response->status() === 403 => '目前 Google 帳戶沒有此 Search Console 網站的讀取權限，請確認網站授權或改選其他網站。',
                    $response->status() === 404 => 'Search Console 網站資源不存在或已移除，請重新選擇網站。',
                    default => $serviceName.' 暫時無法讀取，請稍後再試。',
                };

            throw new GoogleSearchConsoleApiException($message);
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            throw new GoogleSearchConsoleApiException($serviceName.' 回傳格式無效，請稍後再試。');
        }

        return $payload;
    }

    private function isCalendarDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date instanceof \DateTimeImmutable && $date->format('Y-m-d') === $value;
    }

    private function readOnlyRequest(string $accessToken): PendingRequest
    {
        return ReadOnlyApiRequest::forBearerToken($accessToken, 10);
    }
}
