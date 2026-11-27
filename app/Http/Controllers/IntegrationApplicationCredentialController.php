<?php

namespace App\Http\Controllers;

use App\Models\IntegrationApplicationCredential;
use App\Models\IntegrationApplicationCredentialEvent;
use App\Services\OAuthApplicationCredentialResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class IntegrationApplicationCredentialController extends Controller
{
    public function update(Request $request, string $integrationProvider, OAuthApplicationCredentialResolver $resolver): RedirectResponse
    {
        $definition = $resolver->definition($integrationProvider);
        $workspaceId = $resolver->workspaceIdFor($request->user());

        if ($definition === null || $workspaceId === null) {
            abort(404);
        }

        $credential = IntegrationApplicationCredential::query()
            ->where('workspace_id', $workspaceId)
            ->where('provider_code', $integrationProvider)
            ->first();
        $validator = Validator::make($request->only('oauth_client_id', 'oauth_client_secret'), [
            'oauth_client_id' => ['required', 'string', 'max:255'],
            'oauth_client_secret' => ['nullable', 'string', 'max:4096'],
        ], [
            'oauth_client_id.required' => '請輸入 OAuth 用戶端 ID。',
            'oauth_client_id.max' => 'OAuth 用戶端 ID 不可超過 255 個字元。',
            'oauth_client_secret.max' => 'OAuth 用戶端密鑰不可超過 4096 個字元。',
        ]);
        if ($validator->fails()) {
            return redirect()->route($definition['edit_route'])
                ->withErrors($validator, 'oauthApplication')
                ->withInput($request->only('oauth_client_id'));
        }

        $clientSecret = $request->input('oauth_client_secret');
        $secretWasProvided = is_string($clientSecret) && trim($clientSecret) !== '';
        $clientId = trim($validator->validated()['oauth_client_id']);
        $clientIdChanged = $credential !== null && $clientId !== $credential->client_id;

        if (! $secretWasProvided && ($credential === null || $clientIdChanged)) {
            $validator->errors()->add(
                'oauth_client_secret',
                $clientIdChanged ? '更換用戶端 ID 時，請同時輸入新密鑰。' : '請輸入 OAuth 用戶端密鑰。',
            );
        }

        if ($validator->errors()->isNotEmpty()) {
            return redirect()->route($definition['edit_route'])
                ->withErrors($validator, 'oauthApplication')
                ->withInput($request->only('oauth_client_id'));
        }

        $credential ??= new IntegrationApplicationCredential([
            'workspace_id' => $workspaceId,
            'provider_code' => $integrationProvider,
        ]);
        $action = $credential->exists ? 'updated' : 'created';
        $credential->client_id = $clientId;

        if ($secretWasProvided) {
            $credential->client_secret = $clientSecret;
        }

        $credential->last_updated_by_user_id = $request->user()->getKey();
        DB::transaction(function () use ($credential, $workspaceId, $integrationProvider, $request, $action): void {
            $credential->save();
            IntegrationApplicationCredentialEvent::query()->create([
                'workspace_id' => $workspaceId,
                'actor_user_id' => $request->user()->getKey(),
                'provider_code' => $integrationProvider,
                'action' => $action,
            ]);
        });

        return redirect()->route($definition['edit_route'])
            ->with('status', $definition['label'].' OAuth 用戶端憑證已儲存。');
    }

    public function destroy(Request $request, string $integrationProvider, OAuthApplicationCredentialResolver $resolver): RedirectResponse
    {
        $definition = $resolver->definition($integrationProvider);
        $workspaceId = $resolver->workspaceIdFor($request->user());

        if ($definition === null || $workspaceId === null) {
            abort(404);
        }

        $credential = IntegrationApplicationCredential::query()
            ->where('workspace_id', $workspaceId)
            ->where('provider_code', $integrationProvider)
            ->first();

        if ($credential !== null) {
            DB::transaction(function () use ($credential, $workspaceId, $integrationProvider, $request): void {
                IntegrationApplicationCredentialEvent::query()->create([
                    'workspace_id' => $workspaceId,
                    'actor_user_id' => $request->user()->getKey(),
                    'provider_code' => $integrationProvider,
                    'action' => 'deleted',
                ]);
                $credential->delete();
            });
        }

        return redirect()->route($definition['edit_route'])
            ->with('status', $definition['label'].'自訂 OAuth 憑證已移除；若有伺服器舊設定，將改用舊設定。');
    }
}
