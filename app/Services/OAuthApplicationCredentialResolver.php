<?php

namespace App\Services;

use App\Models\IntegrationApplicationCredential;
use App\Models\User;

class OAuthApplicationCredentialResolver
{
    /** @var array<string, array{config_key: string, label: string, edit_route: string}> */
    private const PROVIDERS = [
        'google_analytics' => ['config_key' => 'google_analytics', 'label' => 'Google Analytics 4', 'edit_route' => 'integrations.google-analytics.edit'],
        'google_search_console' => ['config_key' => 'google_search_console', 'label' => 'Google Search Console', 'edit_route' => 'integrations.google-search-console.edit'],
        'meta_marketing' => ['config_key' => 'meta_marketing', 'label' => 'Meta Ads', 'edit_route' => 'integrations.meta-marketing.edit'],
        'google_ads' => ['config_key' => 'google_ads', 'label' => 'Google Ads', 'edit_route' => 'integrations.google-ads.edit'],
    ];

    /** @return array{client_id: string, client_secret: string, source: 'workspace'|'server'}|null */
    public function resolve(string $providerCode, ?int $workspaceId): ?array
    {
        $provider = self::PROVIDERS[$providerCode] ?? null;

        if ($provider === null) {
            return null;
        }

        if ($workspaceId !== null) {
            $credential = IntegrationApplicationCredential::query()
                ->where('workspace_id', $workspaceId)
                ->where('provider_code', $providerCode)
                ->first();

            if ($credential !== null) {
                return [
                    'client_id' => $credential->client_id,
                    'client_secret' => $credential->client_secret,
                    'source' => 'workspace',
                ];
            }
        }

        $clientId = config('services.'.$provider['config_key'].'.client_id');
        $clientSecret = config('services.'.$provider['config_key'].'.client_secret');

        if (! is_string($clientId) || trim($clientId) === ''
            || ! is_string($clientSecret) || trim($clientSecret) === '') {
            return null;
        }

        return [
            'client_id' => trim($clientId),
            'client_secret' => $clientSecret,
            'source' => 'server',
        ];
    }

    /** @return array{oauthConfigured: bool, oauthClientId: string|null, oauthCredentialSource: 'workspace'|'server'|null, hasWorkspaceCredential: bool} */
    public function details(string $providerCode, ?int $workspaceId): array
    {
        $provider = self::PROVIDERS[$providerCode] ?? null;
        $credential = $workspaceId === null
            ? null
            : IntegrationApplicationCredential::query()
                ->where('workspace_id', $workspaceId)
                ->where('provider_code', $providerCode)
                ->first();
        $legacyClientId = $provider === null ? null : config('services.'.$provider['config_key'].'.client_id');
        $legacyClientSecret = $provider === null ? null : config('services.'.$provider['config_key'].'.client_secret');
        $hasLegacyCredential = is_string($legacyClientId) && trim($legacyClientId) !== ''
            && is_string($legacyClientSecret) && trim($legacyClientSecret) !== '';

        return [
            'oauthConfigured' => $credential !== null || $hasLegacyCredential,
            'oauthClientId' => $credential?->client_id,
            'oauthCredentialSource' => $credential !== null ? 'workspace' : ($hasLegacyCredential ? 'server' : null),
            'hasWorkspaceCredential' => $credential !== null,
        ];
    }

    /** @return array{config_key: string, label: string, edit_route: string}|null */
    public function definition(string $providerCode): ?array
    {
        return self::PROVIDERS[$providerCode] ?? null;
    }

    public function workspaceIdFor(User $user): ?int
    {
        $workspaceId = $user->currentWorkspace()?->getKey();

        return is_numeric($workspaceId) ? (int) $workspaceId : null;
    }
}
