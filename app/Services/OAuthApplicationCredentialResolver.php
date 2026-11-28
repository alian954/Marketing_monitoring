<?php

namespace App\Services;

use App\Models\IntegrationApplicationCredential;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class OAuthApplicationCredentialResolver
{
    /** @var array<string, array{config_key: string, label: string, edit_route: string, connection_table: string}> */
    private const PROVIDERS = [
        'google_analytics' => ['config_key' => 'google_analytics', 'label' => 'Google Analytics 4', 'edit_route' => 'integrations.google-analytics.edit', 'connection_table' => 'google_analytics_connections'],
        'google_search_console' => ['config_key' => 'google_search_console', 'label' => 'Google Search Console', 'edit_route' => 'integrations.google-search-console.edit', 'connection_table' => 'google_search_console_connections'],
        'meta_marketing' => ['config_key' => 'meta_marketing', 'label' => 'Meta Ads', 'edit_route' => 'integrations.meta-marketing.edit', 'connection_table' => 'meta_marketing_connections'],
        'google_ads' => ['config_key' => 'google_ads', 'label' => 'Google Ads', 'edit_route' => 'integrations.google-ads.edit', 'connection_table' => 'google_ads_connections'],
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

        if (! $this->allowsLegacyFallback()) {
            return null;
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
        $hasLegacyCredential = $this->allowsLegacyFallback()
            && is_string($legacyClientId) && trim($legacyClientId) !== ''
            && is_string($legacyClientSecret) && trim($legacyClientSecret) !== '';

        return [
            'oauthConfigured' => $credential !== null || $hasLegacyCredential,
            'oauthClientId' => $credential?->client_id,
            'oauthCredentialSource' => $credential !== null ? 'workspace' : ($hasLegacyCredential ? 'server' : null),
            'hasWorkspaceCredential' => $credential !== null,
        ];
    }

    public function allowsLegacyFallback(): bool
    {
        return (bool) config('services.oauth_legacy_credential_fallback', true);
    }

    /**
     * Workspaces with an existing provider connection but no stored app credential of their own, so they still depend on the
     * server-wide fallback. The fallback can be turned off once this list is empty.
     *
     * @return list<array{workspace_id: int|null, organization_name: string|null, providers: list<string>}>
     */
    public function workspacesRelyingOnServerCredentials(): array
    {
        $dependents = [];

        foreach (self::PROVIDERS as $providerCode => $provider) {
            $workspaceIds = DB::table($provider['connection_table'])
                ->whereNotExists(static function ($query) use ($provider, $providerCode): void {
                    $query->selectRaw('1')
                        ->from('integration_application_credentials')
                        ->whereColumn('integration_application_credentials.workspace_id', $provider['connection_table'].'.workspace_id')
                        ->where('integration_application_credentials.provider_code', $providerCode);
                })
                ->distinct()
                ->pluck('workspace_id');

            foreach ($workspaceIds as $workspaceId) {
                $key = $workspaceId === null ? 'unassigned' : (string) $workspaceId;
                $dependents[$key] ??= [
                    'workspace_id' => $workspaceId === null ? null : (int) $workspaceId,
                    'organization_name' => $workspaceId === null ? null : DB::table('workspaces')
                        ->join('organizations', 'organizations.id', '=', 'workspaces.organization_id')
                        ->where('workspaces.id', $workspaceId)
                        ->value('organizations.name'),
                    'providers' => [],
                ];
                $dependents[$key]['providers'][] = $provider['label'];
            }
        }

        return array_values($dependents);
    }

    /** @return array{config_key: string, label: string, edit_route: string, connection_table: string}|null */
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
