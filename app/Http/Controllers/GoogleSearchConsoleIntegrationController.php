<?php

namespace App\Http\Controllers;

use App\Http\Requests\GoogleSearchConsolePerformanceReportRequest;
use App\Models\Client;
use App\Models\GoogleSearchConsoleConnection;
use App\Models\User;
use App\Services\GoogleSearchConsoleApiException;
use App\Services\GoogleSearchConsoleDataApi;
use App\Services\GoogleSearchConsoleReportCsv;
use App\Services\OAuthApplicationCredentialResolver;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class GoogleSearchConsoleIntegrationController extends Controller
{
    public function show(
        Request $request,
        GoogleSearchConsoleDataApi $dataApi,
        OAuthApplicationCredentialResolver $credentialResolver,
    ): View {
        $user = $request->user();
        Client::ensureDefaultFor($user);
        $clients = $user->clients()->orderBy('name')->get();
        $connection = $user->googleSearchConsoleConnection;
        $workspaceId = $credentialResolver->workspaceIdFor($user);
        $credentialDetails = $credentialResolver->details('google_search_console', $workspaceId);
        $sites = [];
        $siteListError = null;

        if ($connection !== null) {
            try {
                $accessToken = $dataApi->refreshAccessToken($connection->refresh_token, $workspaceId);
                $sites = $dataApi->sites($accessToken);
            } catch (GoogleSearchConsoleApiException $exception) {
                $siteListError = $exception->getMessage();
            } catch (ConnectionException) {
                $siteListError = '目前無法連線至 Google Search Console，請稍後重新載入。';
            }
        }

        $clientSites = $connection === null
            ? collect()
            : $connection->clientSites()
                ->whereIn('client_id', $clients->modelKeys())
                ->get()
                ->keyBy('client_id');
        $reportInputs = session('searchConsoleReportInputs');
        $requestedClientId = $request->query('client_id')
            ?? $request->session()->get('_old_input.client_id')
            ?? (is_array($reportInputs) ? ($reportInputs['client_id'] ?? null) : null);
        $selectedClient = is_numeric($requestedClientId)
            ? $clients->firstWhere('id', (int) $requestedClientId)
            : null;
        $selectedClient ??= $clients->first();
        $selectedClientSite = $selectedClient === null
            ? null
            : $clientSites->get($selectedClient->getKey());
        $selectedSiteUrl = $selectedClientSite?->site_url;

        if ($selectedSiteUrl === null && $clients->count() === 1 && is_string($connection?->site_url)) {
            $selectedSiteUrl = $connection->site_url;
        }

        $latestCompleteDate = CarbonImmutable::now('America/Los_Angeles')->subDays(3)->toDateString();

        return view('integrations.google-search-console', [
            'connection' => $connection,
            'clients' => $clients,
            'clientSites' => $clientSites,
            'selectedClient' => $selectedClient,
            'selectedSiteUrl' => $selectedSiteUrl,
            ...$credentialDetails,
            'redirectUri' => $this->redirectUri(),
            'sites' => $sites,
            'siteListError' => $siteListError,
            'report' => session('searchConsoleReport'),
            'reportInputs' => $reportInputs,
            'defaultStartDate' => CarbonImmutable::parse($latestCompleteDate)->subDays(29)->toDateString(),
            'defaultEndDate' => $latestCompleteDate,
        ]);
    }

    public function connect(Request $request, OAuthApplicationCredentialResolver $credentialResolver): RedirectResponse
    {
        $credentials = $credentialResolver->resolve(
            'google_search_console',
            $credentialResolver->workspaceIdFor($request->user()),
        );

        if ($credentials === null) {
            return redirect()->route('integrations.google-search-console.edit')
                ->withErrors(['google_search_console' => '請先在此頁設定 OAuth 用戶端 ID 與密鑰。']);
        }

        $state = Str::random(64);
        $request->session()->put('google_search_console.oauth_state', $state);
        $query = http_build_query([
            'client_id' => $credentials['client_id'],
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            'scope' => 'https://www.googleapis.com/auth/webmasters.readonly',
            'state' => $state,
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
        ], '', '&', PHP_QUERY_RFC3986);

        return redirect()->away('https://accounts.google.com/o/oauth2/v2/auth?'.$query);
    }

    public function callback(
        Request $request,
        GoogleSearchConsoleDataApi $dataApi,
        OAuthApplicationCredentialResolver $credentialResolver,
    ): RedirectResponse {
        $expectedState = $request->session()->pull('google_search_console.oauth_state');
        $receivedState = $request->query('state');

        if (! is_string($expectedState) || ! is_string($receivedState) || ! hash_equals($expectedState, $receivedState)) {
            return redirect()->route('integrations.google-search-console.edit')
                ->withErrors(['google_search_console' => 'Google 授權驗證失敗，請重新開始連結。']);
        }

        if ($request->query('error') !== null) {
            return redirect()->route('integrations.google-search-console.edit')
                ->withErrors(['google_search_console' => '已取消 Google 授權，尚未建立連線。']);
        }

        $code = $request->query('code');

        if (! is_string($code) || $code === '' || strlen($code) > 2048) {
            return redirect()->route('integrations.google-search-console.edit')
                ->withErrors(['google_search_console' => 'Google 未提供有效的授權碼，請重新連結。']);
        }

        try {
            $tokens = $dataApi->exchangeAuthorizationCode(
                $code,
                $this->redirectUri(),
                $credentialResolver->workspaceIdFor($request->user()),
            );
        } catch (GoogleSearchConsoleApiException $exception) {
            return redirect()->route('integrations.google-search-console.edit')
                ->withErrors(['google_search_console' => $exception->getMessage()]);
        } catch (ConnectionException) {
            return redirect()->route('integrations.google-search-console.edit')
                ->withErrors(['google_search_console' => '目前無法連線至 Google，請稍後再試。']);
        }

        $connection = GoogleSearchConsoleConnection::query()->firstOrNew([
            'user_id' => $request->user()->workspaceOwnerId(),
        ]);
        $refreshToken = $tokens['refresh_token'] ?? $connection->refresh_token;

        if (! is_string($refreshToken) || $refreshToken === '') {
            return redirect()->route('integrations.google-search-console.edit')
                ->withErrors(['google_search_console' => 'Google 未提供離線存取權杖，請重新授權並確認同意持續存取。']);
        }

        $connection->refresh_token = $refreshToken;
        $connection->save();

        return redirect()->route('integrations.google-search-console.edit')
            ->with('status', 'Google Search Console 已連結。請選擇要查看的網站。');
    }

    public function updateSite(
        Request $request,
        GoogleSearchConsoleDataApi $dataApi,
        OAuthApplicationCredentialResolver $credentialResolver,
    ): RedirectResponse {
        $validated = $request->validate([
            'client_id' => ['required', 'integer'],
            'site_url' => ['required', 'string', 'max:2048'],
        ], [
            'client_id.required' => '請指定要設定網站的客戶。',
            'client_id.integer' => '客戶選擇無效。',
            'site_url.required' => '請選擇 Search Console 網站。',
            'site_url.max' => 'Search Console 網站網址過長。',
        ]);
        $client = $request->user()->clients()->findOrFail($validated['client_id']);
        $connection = $request->user()->googleSearchConsoleConnection;

        if ($connection === null) {
            return redirect()->route('integrations.google-search-console.edit')
                ->withErrors(['google_search_console' => '請先連結 Google Search Console。'])
                ->withInput($validated);
        }

        try {
            $accessToken = $dataApi->refreshAccessToken(
                $connection->refresh_token,
                $credentialResolver->workspaceIdFor($request->user()),
            );
            $sites = $dataApi->sites($accessToken);
        } catch (GoogleSearchConsoleApiException $exception) {
            return redirect()->route('integrations.google-search-console.edit')
                ->withErrors(['google_search_console' => $exception->getMessage()])
                ->withInput($validated);
        } catch (ConnectionException) {
            return redirect()->route('integrations.google-search-console.edit')
                ->withErrors(['google_search_console' => '目前無法連線至 Google Search Console，請稍後再試。'])
                ->withInput($validated);
        }

        $siteIsAccessible = collect($sites)->contains(
            static fn (array $site): bool => $site['site_url'] === $validated['site_url'],
        );

        if (! $siteIsAccessible) {
            return redirect()->route('integrations.google-search-console.edit')
                ->withErrors(['site_url' => '此網站不在目前 Google 帳戶可讀取的 Search Console 網站清單中，請重新選取。'])
                ->withInput($validated);
        }

        $connection->site_url = $validated['site_url'];
        $connection->save();
        $connection->clientSites()->updateOrCreate(
            ['client_id' => $client->getKey()],
            ['site_url' => $validated['site_url']],
        );

        return redirect()->route('integrations.google-search-console.edit', ['client_id' => $client->getKey()])
            ->with('status', $client->name.'的 Search Console 網站已儲存。');
    }

    public function report(
        GoogleSearchConsolePerformanceReportRequest $request,
        GoogleSearchConsoleDataApi $dataApi,
        OAuthApplicationCredentialResolver $credentialResolver,
    ): RedirectResponse {
        $validated = [...$request->validated(), 'compare' => $request->boolean('compare')];

        try {
            $report = $this->buildReport($request->user(), $validated, $dataApi, $credentialResolver);
        } catch (GoogleSearchConsoleApiException $exception) {
            return redirect()->route('integrations.google-search-console.edit')
                ->withErrors(['google_search_console' => $exception->getMessage()])
                ->withInput($validated);
        } catch (ConnectionException) {
            return redirect()->route('integrations.google-search-console.edit')
                ->withErrors(['google_search_console' => '目前無法連線至 Google Search Console，請稍後再試。'])
                ->withInput($validated);
        }

        return redirect()->route('integrations.google-search-console.edit')
            ->with('searchConsoleReport', $report)
            ->with('searchConsoleReportInputs', $validated);
    }

    public function export(
        GoogleSearchConsolePerformanceReportRequest $request,
        GoogleSearchConsoleDataApi $dataApi,
        GoogleSearchConsoleReportCsv $csv,
        OAuthApplicationCredentialResolver $credentialResolver,
    ): RedirectResponse|StreamedResponse {
        $validated = [...$request->validated(), 'compare' => $request->boolean('compare')];

        try {
            $report = $this->buildReport($request->user(), $validated, $dataApi, $credentialResolver);
        } catch (GoogleSearchConsoleApiException $exception) {
            return redirect()->route('integrations.google-search-console.edit')
                ->withErrors(['google_search_console' => $exception->getMessage()])
                ->withInput($validated);
        } catch (ConnectionException) {
            return redirect()->route('integrations.google-search-console.edit')
                ->withErrors(['google_search_console' => '目前無法連線至 Google Search Console，請稍後再試。'])
                ->withInput($validated);
        }

        return $csv->download($report);
    }

    public function disconnect(Request $request, GoogleSearchConsoleDataApi $dataApi): RedirectResponse
    {
        $connection = $request->user()->googleSearchConsoleConnection;

        if ($connection !== null) {
            try {
                $dataApi->revokeRefreshToken($connection->refresh_token);
            } catch (GoogleSearchConsoleApiException $exception) {
                return redirect()->route('integrations.google-search-console.edit')
                    ->withErrors(['google_search_console' => $exception->getMessage()]);
            } catch (ConnectionException) {
                return redirect()->route('integrations.google-search-console.edit')
                    ->withErrors(['google_search_console' => '目前無法連線至 Google 撤銷授權，連線仍保留，請稍後再試。']);
            }

            $connection->delete();
        }

        return redirect()->route('integrations.google-search-console.edit')
            ->with('status', 'Google Search Console 已中斷連結。');
    }

    /** @param array<string, mixed> $validated
     * @return array<string, mixed>
     */
    private function buildReport(
        User $user,
        array $validated,
        GoogleSearchConsoleDataApi $dataApi,
        OAuthApplicationCredentialResolver $credentialResolver,
    ): array {
        Client::ensureDefaultFor($user);
        $clients = $user->clients()->orderBy('name')->get();
        $requestedClientId = $validated['client_id'] ?? null;
        $client = is_numeric($requestedClientId)
            ? $clients->firstWhere('id', (int) $requestedClientId)
            : ($clients->count() === 1 ? $clients->first() : null);

        if ($client === null) {
            throw new GoogleSearchConsoleApiException('請先選擇要檢視的客戶。');
        }

        $connection = $user->googleSearchConsoleConnection;

        if ($connection === null) {
            throw new GoogleSearchConsoleApiException('請先連結 Google Search Console。');
        }

        $clientSite = $connection->clientSites()
            ->where('client_id', $client->getKey())
            ->first();
        $siteUrl = $clientSite?->site_url;

        if ($siteUrl === null && $clients->count() === 1 && is_string($connection->site_url)) {
            $siteUrl = $connection->site_url;
        }

        if (! is_string($siteUrl) || $siteUrl === '') {
            throw new GoogleSearchConsoleApiException('請先為所選客戶選擇 Search Console 網站。');
        }

        $accessToken = $dataApi->refreshAccessToken(
            $connection->refresh_token,
            $credentialResolver->workspaceIdFor($user),
        );
        $sites = $dataApi->sites($accessToken);
        $siteIsAccessible = collect($sites)->contains(
            static fn (array $site): bool => $site['site_url'] === $siteUrl,
        );

        if (! $siteIsAccessible) {
            throw new GoogleSearchConsoleApiException('目前 Google 帳戶無法讀取已選網站，請重新連結或選擇其他網站。');
        }

        $startsOn = $validated['start_date'];
        $endsOn = $validated['end_date'];
        $dimension = $validated['dimension'];
        $searchType = $validated['search_type'];
        $current = $dataApi->performanceReport($accessToken, $siteUrl, $startsOn, $endsOn, $dimension, $searchType);
        $comparison = ['status' => 'disabled', 'starts_on' => null, 'ends_on' => null, 'report' => null];

        if (($validated['compare'] ?? false) === true || ($validated['compare'] ?? null) === '1') {
            $periodDays = (int) CarbonImmutable::parse($startsOn)->diffInDays(CarbonImmutable::parse($endsOn), true) + 1;
            $previousEndsOn = CarbonImmutable::parse($startsOn)->subDay();
            $previousStartsOn = $previousEndsOn->subDays($periodDays - 1);
            $earliestAvailableDate = CarbonImmutable::now('America/Los_Angeles')->subMonthsNoOverflow(16)->toDateString();

            $comparison = [
                'status' => $previousStartsOn->toDateString() < $earliestAvailableDate ? 'outside_retention' : 'available',
                'starts_on' => $previousStartsOn->toDateString(),
                'ends_on' => $previousEndsOn->toDateString(),
                'report' => null,
            ];

            if ($comparison['status'] === 'available') {
                $comparison['report'] = $dataApi->performanceReport(
                    $accessToken,
                    $siteUrl,
                    $comparison['starts_on'],
                    $comparison['ends_on'],
                    $dimension,
                    $searchType,
                    false,
                );
                $comparison['changes'] = $this->metricChanges($current['totals'], $comparison['report']['totals']);
            }
        }

        return [
            'client_id' => (int) $client->getKey(),
            'client_name' => $client->name,
            'site_url' => $siteUrl,
            'dimension' => $dimension,
            'dimension_label' => $this->dimensionLabel($dimension),
            'search_type' => $searchType,
            'search_type_label' => $this->searchTypeLabel($searchType),
            'current' => $current,
            'comparison' => $comparison,
        ];
    }

    private function dimensionLabel(string $dimension): string
    {
        return match ($dimension) {
            'page' => '熱門頁面',
            'country' => '國家／地區',
            'device' => '裝置',
            default => '搜尋查詢',
        };
    }

    private function searchTypeLabel(string $searchType): string
    {
        return match ($searchType) {
            'image' => '圖片搜尋',
            'video' => '影片搜尋',
            'news' => '新聞搜尋',
            'discover' => 'Discover',
            'googleNews' => 'Google News',
            'webMultimodal' => '多模態搜尋',
            default => '網頁搜尋',
        };
    }

    /**
     * @param  array{clicks: float, impressions: float, ctr: float, position: float}  $current
     * @param  array{clicks: float, impressions: float, ctr: float, position: float}  $previous
     * @return array<string, array{difference: float, percent: ?float}>
     */
    private function metricChanges(array $current, array $previous): array
    {
        $changes = [];

        foreach (['clicks', 'impressions', 'ctr', 'position'] as $metric) {
            $difference = $current[$metric] - $previous[$metric];
            $changes[$metric] = [
                'difference' => $metric === 'ctr' ? $difference * 100 : $difference,
                'percent' => $previous[$metric] > 0 ? $difference / $previous[$metric] * 100 : null,
            ];
        }

        return $changes;
    }

    private function redirectUri(): string
    {
        $configuredRedirectUri = config('services.google_search_console.redirect_uri');

        return is_string($configuredRedirectUri) && trim($configuredRedirectUri) !== ''
            ? trim($configuredRedirectUri)
            : route('integrations.google-search-console.callback');
    }
}
