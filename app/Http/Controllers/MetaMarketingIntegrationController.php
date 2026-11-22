<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateMetaMarketingConnectionRequest;
use App\Models\MetaMarketingConnection;
use App\Services\MetaMarketingApiException;
use App\Services\MetaMarketingDataApi;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class MetaMarketingIntegrationController extends Controller
{
    public function show(Request $request): View
    {
        return view('integrations.meta-marketing', [
            'connection' => $request->user()->metaMarketingConnection,
            'oauthConfigured' => filled(config('services.meta_marketing.client_id'))
                && filled(config('services.meta_marketing.client_secret')),
            'redirectUri' => $this->redirectUri(),
        ]);
    }

    public function connect(Request $request): RedirectResponse
    {
        $clientId = config('services.meta_marketing.client_id');
        $clientSecret = config('services.meta_marketing.client_secret');

        if (! is_string($clientId) || trim($clientId) === '' || ! is_string($clientSecret) || trim($clientSecret) === '') {
            return redirect()->route('integrations.meta-marketing.edit')
                ->withErrors(['meta_marketing' => '尚未設定 Meta App，請先在伺服器環境設定 Meta Marketing OAuth。']);
        }

        $state = Str::random(64);
        $request->session()->put('meta_marketing.oauth_state', $state);
        $query = http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            'scope' => 'ads_read',
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);

        return redirect()->away('https://www.facebook.com/'.$this->apiVersion().'/dialog/oauth?'.$query);
    }

    public function callback(Request $request, MetaMarketingDataApi $dataApi): RedirectResponse
    {
        $expectedState = $request->session()->pull('meta_marketing.oauth_state');
        $receivedState = $request->query('state');

        if (! is_string($expectedState) || ! is_string($receivedState) || ! hash_equals($expectedState, $receivedState)) {
            return redirect()->route('integrations.meta-marketing.edit')
                ->withErrors(['meta_marketing' => 'Meta 授權驗證失敗，請重新開始連結。']);
        }

        if ($request->query('error') !== null) {
            return redirect()->route('integrations.meta-marketing.edit')
                ->withErrors(['meta_marketing' => '已取消 Meta 授權，尚未建立連線。']);
        }

        $code = $request->query('code');

        if (! is_string($code) || $code === '' || strlen($code) > 2048) {
            return redirect()->route('integrations.meta-marketing.edit')
                ->withErrors(['meta_marketing' => 'Meta 未提供有效的授權碼，請重新連結。']);
        }

        try {
            $tokens = $dataApi->exchangeAuthorizationCode($code, $this->redirectUri());
        } catch (MetaMarketingApiException $exception) {
            return redirect()->route('integrations.meta-marketing.edit')
                ->withErrors(['meta_marketing' => $exception->getMessage()]);
        } catch (ConnectionException) {
            return redirect()->route('integrations.meta-marketing.edit')
                ->withErrors(['meta_marketing' => '目前無法連線至 Meta，請稍後再試。']);
        }

        $connection = MetaMarketingConnection::query()->firstOrNew([
            'user_id' => (int) $request->user()->getAuthIdentifier(),
        ]);
        $connection->access_token = $tokens['access_token'];
        $connection->token_expires_at = $tokens['expires_in'] === null
            ? null
            : now()->addSeconds($tokens['expires_in']);
        $connection->save();

        return redirect()->route('integrations.meta-marketing.edit')
            ->with('status', 'Meta Ads 帳戶已連結。請設定廣告帳戶 ID。');
    }

    public function updateAdAccount(
        UpdateMetaMarketingConnectionRequest $request,
        MetaMarketingDataApi $dataApi,
    ): RedirectResponse {
        $connection = $request->user()->metaMarketingConnection;

        if ($connection === null || ! is_string($connection->access_token) || $connection->access_token === '') {
            return redirect()->route('integrations.meta-marketing.edit')
                ->withErrors(['meta_marketing' => '請先連結 Meta 帳戶。']);
        }

        if ($connection->token_expires_at !== null && $connection->token_expires_at->isPast()) {
            return redirect()->route('integrations.meta-marketing.edit')
                ->withErrors(['meta_marketing' => 'Meta 授權已到期，請重新連結帳戶。']);
        }

        try {
            $account = $dataApi->adAccountDetails(
                $connection->access_token,
                $request->validated('ad_account_id'),
            );
        } catch (MetaMarketingApiException $exception) {
            return redirect()->route('integrations.meta-marketing.edit')
                ->withErrors(['meta_marketing' => $exception->getMessage()]);
        } catch (ConnectionException) {
            return redirect()->route('integrations.meta-marketing.edit')
                ->withErrors(['meta_marketing' => '目前無法連線至 Meta，請稍後再試。']);
        }

        $connection->ad_account_id = $account['account_id'];
        $connection->ad_account_name = $account['name'];
        $connection->currency_code = $account['currency_code'];
        $connection->save();

        return redirect()->route('integrations.meta-marketing.edit')
            ->with('status', 'Meta 廣告帳戶已設定。');
    }

    public function disconnect(Request $request, MetaMarketingDataApi $dataApi): RedirectResponse
    {
        $connection = $request->user()->metaMarketingConnection;

        if ($connection !== null) {
            try {
                $dataApi->revokeAccessToken($connection->access_token);
            } catch (MetaMarketingApiException $exception) {
                return redirect()->route('integrations.meta-marketing.edit')
                    ->withErrors(['meta_marketing' => $exception->getMessage()]);
            } catch (ConnectionException) {
                return redirect()->route('integrations.meta-marketing.edit')
                    ->withErrors(['meta_marketing' => '目前無法連線至 Meta 撤銷授權，連線仍保留，請稍後再試。']);
            }

            $connection->delete();
        }

        return redirect()->route('integrations.meta-marketing.edit')
            ->with('status', 'Meta Ads 已中斷連結。');
    }

    private function redirectUri(): string
    {
        $configuredRedirectUri = config('services.meta_marketing.redirect_uri');

        return is_string($configuredRedirectUri) && trim($configuredRedirectUri) !== ''
            ? trim($configuredRedirectUri)
            : route('integrations.meta-marketing.callback');
    }

    private function apiVersion(): string
    {
        $version = config('services.meta_marketing.api_version');

        return is_string($version) && preg_match('/^v\d+\.\d+$/', $version) === 1 ? $version : 'v27.0';
    }
}
