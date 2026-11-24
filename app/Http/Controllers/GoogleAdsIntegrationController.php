<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateGoogleAdsClientAccountRequest;
use App\Models\Client;
use App\Models\GoogleAdsConnection;
use App\Services\GoogleAdsApiException;
use App\Services\GoogleAdsClientAccountResolver;
use App\Services\GoogleAdsDataApi;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class GoogleAdsIntegrationController extends Controller
{
    public function show(Request $request, GoogleAdsClientAccountResolver $accountResolver): View
    {
        $user = $request->user();
        Client::ensureDefaultFor($user);
        $clients = $user->clients()->orderBy('name')->get();
        $connection = $user->googleAdsConnection;
        $clientAccounts = $connection === null
            ? collect()
            : $clients->mapWithKeys(function (Client $client) use ($connection, $accountResolver): array {
                $account = $accountResolver->forClient($connection, $client);

                return $account === null ? [] : [$client->getKey() => $account];
            });

        return view('integrations.google-ads', [
            'connection' => $connection,
            'clients' => $clients,
            'clientAccounts' => $clientAccounts,
            'oauthConfigured' => filled(config('services.google_ads.client_id'))
                && filled(config('services.google_ads.client_secret')),
            'redirectUri' => $this->redirectUri(),
        ]);
    }

    public function connect(Request $request): RedirectResponse
    {
        $clientId = config('services.google_ads.client_id');
        $clientSecret = config('services.google_ads.client_secret');

        if (! is_string($clientId) || trim($clientId) === '' || ! is_string($clientSecret) || trim($clientSecret) === '') {
            return redirect()->route('integrations.google-ads.edit')
                ->withErrors(['google_ads' => '尚未設定 Google OAuth 用戶端，請先在伺服器環境設定 Google Ads 連線參數。']);
        }

        $state = Str::random(64);
        $request->session()->put('google_ads.oauth_state', $state);
        $query = http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            'scope' => 'https://www.googleapis.com/auth/adwords',
            'state' => $state,
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
        ], '', '&', PHP_QUERY_RFC3986);

        return redirect()->away('https://accounts.google.com/o/oauth2/v2/auth?'.$query);
    }

    public function callback(Request $request, GoogleAdsDataApi $dataApi): RedirectResponse
    {
        $expectedState = $request->session()->pull('google_ads.oauth_state');
        $receivedState = $request->query('state');

        if (! is_string($expectedState) || ! is_string($receivedState) || ! hash_equals($expectedState, $receivedState)) {
            return redirect()->route('integrations.google-ads.edit')
                ->withErrors(['google_ads' => 'Google Ads 授權驗證失敗，請重新開始連結。']);
        }

        if ($request->query('error') !== null) {
            return redirect()->route('integrations.google-ads.edit')
                ->withErrors(['google_ads' => '已取消 Google Ads 授權，尚未建立連線。']);
        }

        $code = $request->query('code');

        if (! is_string($code) || $code === '' || strlen($code) > 2048) {
            return redirect()->route('integrations.google-ads.edit')
                ->withErrors(['google_ads' => 'Google 未提供有效的授權碼，請重新連結。']);
        }

        try {
            $tokens = $dataApi->exchangeAuthorizationCode($code, $this->redirectUri());
        } catch (GoogleAdsApiException $exception) {
            return redirect()->route('integrations.google-ads.edit')
                ->withErrors(['google_ads' => $exception->getMessage()]);
        } catch (ConnectionException) {
            return redirect()->route('integrations.google-ads.edit')
                ->withErrors(['google_ads' => '目前無法連線至 Google，請稍後再試。']);
        }

        $connection = GoogleAdsConnection::query()->firstOrNew([
            'user_id' => $request->user()->workspaceOwnerId(),
        ]);
        $refreshToken = $tokens['refresh_token'] ?? $connection->refresh_token;

        if (! is_string($refreshToken) || $refreshToken === '') {
            return redirect()->route('integrations.google-ads.edit')
                ->withErrors(['google_ads' => 'Google 未提供離線存取權杖，請重新授權並確認同意持續存取。']);
        }

        $connection->refresh_token = $refreshToken;
        $connection->save();

        return redirect()->route('integrations.google-ads.edit')
            ->with('status', 'Google Ads 帳戶已連結。請設定要同步的客戶帳戶。');
    }

    public function updateClientCustomer(
        UpdateGoogleAdsClientAccountRequest $request,
        Client $client,
        GoogleAdsDataApi $dataApi,
    ): RedirectResponse {
        $user = $request->user();
        $client = $user->clients()->findOrFail($client->getKey());
        $connection = $user->googleAdsConnection;

        if ($connection === null || ! is_string($connection->refresh_token) || $connection->refresh_token === '') {
            return redirect()->route('integrations.google-ads.edit')
                ->withErrors(['google_ads' => '請先連結 Google Ads 帳戶。']);
        }

        try {
            $accessToken = $dataApi->refreshAccessToken($connection->refresh_token);
            $customer = $dataApi->customerDetails(
                $accessToken,
                $request->validated('customer_id'),
                $request->validated('login_customer_id'),
            );
        } catch (GoogleAdsApiException $exception) {
            return redirect()->route('integrations.google-ads.edit')
                ->withErrors(['google_ads' => $exception->getMessage()]);
        } catch (ConnectionException) {
            return redirect()->route('integrations.google-ads.edit')
                ->withErrors(['google_ads' => '目前無法連線至 Google Ads，請稍後再試。']);
        }

        $connection->clientAccounts()->updateOrCreate(
            ['client_id' => $client->getKey()],
            [
                'customer_id' => $customer['customer_id'],
                'login_customer_id' => $request->validated('login_customer_id'),
                'customer_name' => $customer['customer_name'],
                'currency_code' => $customer['currency_code'],
            ],
        );

        return redirect()->route('integrations.google-ads.edit')
            ->with('status', $client->name.'的 Google Ads 客戶帳戶已驗證並儲存。');
    }

    public function disconnect(Request $request, GoogleAdsDataApi $dataApi): RedirectResponse
    {
        $connection = $request->user()->googleAdsConnection;

        if ($connection !== null) {
            try {
                $dataApi->revokeRefreshToken($connection->refresh_token);
            } catch (GoogleAdsApiException $exception) {
                return redirect()->route('integrations.google-ads.edit')
                    ->withErrors(['google_ads' => $exception->getMessage()]);
            } catch (ConnectionException) {
                return redirect()->route('integrations.google-ads.edit')
                    ->withErrors(['google_ads' => '目前無法連線至 Google 撤銷授權，連線仍保留，請稍後再試。']);
            }

            $connection->delete();
        }

        return redirect()->route('integrations.google-ads.edit')
            ->with('status', 'Google Ads 已中斷連結。');
    }

    private function redirectUri(): string
    {
        $configuredRedirectUri = config('services.google_ads.redirect_uri');

        return is_string($configuredRedirectUri) && trim($configuredRedirectUri) !== ''
            ? trim($configuredRedirectUri)
            : route('integrations.google-ads.callback');
    }
}
