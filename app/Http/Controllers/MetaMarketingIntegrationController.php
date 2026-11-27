<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateMetaMarketingClientAccountRequest;
use App\Models\Client;
use App\Models\MetaMarketingClientAccount;
use App\Models\MetaMarketingConnection;
use App\Services\MetaMarketingApiException;
use App\Services\MetaMarketingClientAccountResolver;
use App\Services\MetaMarketingDataApi;
use App\Services\OAuthApplicationCredentialResolver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class MetaMarketingIntegrationController extends Controller
{
    public function show(
        Request $request,
        MetaMarketingClientAccountResolver $accountResolver,
        OAuthApplicationCredentialResolver $credentialResolver,
    ): View {
        $user = $request->user();
        $connection = $user->metaMarketingConnection;
        $credentialDetails = $credentialResolver->details('meta_marketing', $credentialResolver->workspaceIdFor($user));
        $clients = Client::query()->orderBy('name')->get();
        $clientAccounts = collect();

        if ($connection !== null) {
            foreach ($clients as $client) {
                $account = $accountResolver->forClient($connection, $client);

                if ($account !== null) {
                    $clientAccounts->put($client->getKey(), $account);
                }
            }
        }

        return view('integrations.meta-marketing', [
            'connection' => $connection,
            'clients' => $clients,
            'clientAccounts' => $clientAccounts,
            ...$credentialDetails,
            'redirectUri' => $this->redirectUri(),
        ]);
    }

    public function connect(Request $request, OAuthApplicationCredentialResolver $credentialResolver): RedirectResponse
    {
        $credentials = $credentialResolver->resolve('meta_marketing', $credentialResolver->workspaceIdFor($request->user()));

        if ($credentials === null) {
            return redirect()->route('integrations.meta-marketing.edit')
                ->withErrors(['meta_marketing' => '請先在此頁設定 Meta OAuth 用戶端 ID 與密鑰。']);
        }

        $state = Str::random(64);
        $request->session()->put('meta_marketing.oauth_state', $state);
        $query = http_build_query([
            'client_id' => $credentials['client_id'],
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            'scope' => 'ads_read',
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);

        return redirect()->away('https://www.facebook.com/'.$this->apiVersion().'/dialog/oauth?'.$query);
    }

    public function callback(
        Request $request,
        MetaMarketingDataApi $dataApi,
        OAuthApplicationCredentialResolver $credentialResolver,
    ): RedirectResponse {
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
            $tokens = $dataApi->exchangeAuthorizationCode(
                $code,
                $this->redirectUri(),
                $credentialResolver->workspaceIdFor($request->user()),
            );
        } catch (MetaMarketingApiException $exception) {
            return redirect()->route('integrations.meta-marketing.edit')
                ->withErrors(['meta_marketing' => $exception->getMessage()]);
        } catch (ConnectionException) {
            return redirect()->route('integrations.meta-marketing.edit')
                ->withErrors(['meta_marketing' => '目前無法連線至 Meta，請稍後再試。']);
        }

        $connection = MetaMarketingConnection::query()->firstOrNew([
            'user_id' => $request->user()->workspaceOwnerId(),
        ]);
        $connection->access_token = $tokens['access_token'];
        $connection->token_expires_at = $tokens['expires_in'] === null
            ? null
            : now()->addSeconds($tokens['expires_in']);
        $connection->save();

        return redirect()->route('integrations.meta-marketing.edit')
            ->with('status', 'Meta Ads 帳戶已連結。請為各客戶設定廣告帳戶。');
    }

    public function updateClientAdAccount(
        UpdateMetaMarketingClientAccountRequest $request,
        Client $client,
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

        MetaMarketingClientAccount::query()->updateOrCreate(
            [
                'meta_marketing_connection_id' => $connection->getKey(),
                'client_id' => $client->getKey(),
            ],
            [
                'ad_account_id' => $account['account_id'],
                'ad_account_name' => $account['name'],
                'currency_code' => $account['currency_code'],
            ],
        );

        return redirect()->route('integrations.meta-marketing.edit')
            ->with('status', '已為「'.$client->name.'」設定 Meta 廣告帳戶。');
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
