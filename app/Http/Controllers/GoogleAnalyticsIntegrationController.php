<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateGoogleAnalyticsPropertyRequest;
use App\Models\Client;
use App\Models\GoogleAnalyticsConnection;
use App\Services\GoogleAnalyticsApiException;
use App\Services\GoogleAnalyticsClientPropertyResolver;
use App\Services\GoogleAnalyticsDataApi;
use App\Services\OAuthApplicationCredentialResolver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class GoogleAnalyticsIntegrationController extends Controller
{
    public function show(
        Request $request,
        GoogleAnalyticsClientPropertyResolver $propertyResolver,
        OAuthApplicationCredentialResolver $credentialResolver,
    ): View {
        $user = $request->user();
        Client::ensureDefaultFor($user);
        $clients = $user->clients()->orderBy('name')->get();
        $connection = $user->googleAnalyticsConnection;
        $credentialDetails = $credentialResolver->details('google_analytics', $credentialResolver->workspaceIdFor($user));
        $clientProperties = $connection === null
            ? collect()
            : $clients->mapWithKeys(function (Client $client) use ($connection, $propertyResolver): array {
                $property = $propertyResolver->forClient($connection, $client);

                return $property === null ? [] : [$client->getKey() => $property];
            });

        return view('integrations.google-analytics', [
            'connection' => $connection,
            'clients' => $clients,
            'clientProperties' => $clientProperties,
            ...$credentialDetails,
            'redirectUri' => $this->redirectUri(),
        ]);
    }

    public function connect(Request $request, OAuthApplicationCredentialResolver $credentialResolver): RedirectResponse
    {
        $credentials = $credentialResolver->resolve('google_analytics', $credentialResolver->workspaceIdFor($request->user()));

        if ($credentials === null) {
            return redirect()->route('integrations.google-analytics.edit')
                ->withErrors(['google_analytics' => '請先在此頁設定 Google OAuth 用戶端 ID 與密鑰。']);
        }

        $state = Str::random(64);
        $request->session()->put('google_analytics.oauth_state', $state);
        $query = http_build_query([
            'client_id' => $credentials['client_id'],
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            'scope' => 'https://www.googleapis.com/auth/analytics.readonly',
            'state' => $state,
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
        ], '', '&', PHP_QUERY_RFC3986);

        return redirect()->away('https://accounts.google.com/o/oauth2/v2/auth?'.$query);
    }

    public function callback(
        Request $request,
        GoogleAnalyticsDataApi $dataApi,
        OAuthApplicationCredentialResolver $credentialResolver,
    ): RedirectResponse {
        $expectedState = $request->session()->pull('google_analytics.oauth_state');
        $receivedState = $request->query('state');

        if (! is_string($expectedState) || ! is_string($receivedState) || ! hash_equals($expectedState, $receivedState)) {
            return redirect()->route('integrations.google-analytics.edit')
                ->withErrors(['google_analytics' => 'Google 授權驗證失敗，請重新開始連結。']);
        }

        if ($request->query('error') !== null) {
            return redirect()->route('integrations.google-analytics.edit')
                ->withErrors(['google_analytics' => '已取消 Google 授權，尚未建立連線。']);
        }

        $code = $request->query('code');

        if (! is_string($code) || $code === '' || strlen($code) > 2048) {
            return redirect()->route('integrations.google-analytics.edit')
                ->withErrors(['google_analytics' => 'Google 未提供有效的授權碼，請重新連結。']);
        }

        try {
            $tokens = $dataApi->exchangeAuthorizationCode(
                $code,
                $this->redirectUri(),
                $credentialResolver->workspaceIdFor($request->user()),
            );
        } catch (GoogleAnalyticsApiException $exception) {
            return redirect()->route('integrations.google-analytics.edit')
                ->withErrors(['google_analytics' => $exception->getMessage()]);
        } catch (ConnectionException) {
            return redirect()->route('integrations.google-analytics.edit')
                ->withErrors(['google_analytics' => '目前無法連線至 Google，請稍後再試。']);
        }

        $connection = GoogleAnalyticsConnection::query()->firstOrNew([
            'user_id' => $request->user()->workspaceOwnerId(),
        ]);
        $refreshToken = $tokens['refresh_token'] ?? $connection->refresh_token;

        if (! is_string($refreshToken) || $refreshToken === '') {
            return redirect()->route('integrations.google-analytics.edit')
                ->withErrors(['google_analytics' => 'Google 未提供離線存取權杖，請重新授權並確認同意持續存取。']);
        }

        $connection->refresh_token = $refreshToken;
        $connection->save();

        return redirect()->route('integrations.google-analytics.edit')
            ->with('status', 'Google Analytics 已連結。');
    }

    public function updateProperty(UpdateGoogleAnalyticsPropertyRequest $request, Client $client): RedirectResponse
    {
        $user = $request->user();
        $client = $user->clients()->findOrFail($client->getKey());
        $connection = $user->googleAnalyticsConnection;

        if ($connection === null) {
            return redirect()->route('integrations.google-analytics.edit')
                ->withErrors(['google_analytics' => '請先連結 Google Analytics 帳戶。']);
        }

        $connection->clientProperties()->updateOrCreate(
            ['client_id' => $client->getKey()],
            ['property_id' => $request->validated('property_id')],
        );

        return redirect()->route('integrations.google-analytics.edit', ['client_id' => $client->getKey()])
            ->with('status', $client->name.'的 GA4 資源 ID 已儲存。');
    }

    public function disconnect(Request $request, GoogleAnalyticsDataApi $dataApi): RedirectResponse
    {
        $connection = $request->user()->googleAnalyticsConnection;

        if ($connection !== null) {
            try {
                $dataApi->revokeRefreshToken($connection->refresh_token);
            } catch (GoogleAnalyticsApiException $exception) {
                return redirect()->route('integrations.google-analytics.edit')
                    ->withErrors(['google_analytics' => $exception->getMessage()]);
            } catch (ConnectionException) {
                return redirect()->route('integrations.google-analytics.edit')
                    ->withErrors(['google_analytics' => '目前無法連線至 Google 撤銷授權，連線仍保留，請稍後再試。']);
            }

            $connection->delete();
        }

        return redirect()->route('integrations.google-analytics.edit')
            ->with('status', 'Google Analytics 已中斷連結。');
    }

    private function redirectUri(): string
    {
        $configuredRedirectUri = config('services.google_analytics.redirect_uri');

        return is_string($configuredRedirectUri) && trim($configuredRedirectUri) !== ''
            ? trim($configuredRedirectUri)
            : route('integrations.google-analytics.callback');
    }
}
