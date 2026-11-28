<?php

namespace Tests\Feature;

use App\Models\GoogleAdsConnection;
use App\Models\IntegrationApplicationCredential;
use App\Models\IntegrationApplicationCredentialEvent;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use App\Services\GoogleAdsDataApi;
use App\Services\GoogleAnalyticsDataApi;
use App\Services\GoogleSearchConsoleDataApi;
use App\Services\MetaMarketingDataApi;
use App\Services\OAuthApplicationCredentialResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IntegrationApplicationCredentialTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspace_owner_can_store_encrypted_oauth_application_credentials(): void
    {
        $owner = User::factory()->create();
        Organization::factory()->for($owner, 'owner')->has(Workspace::factory(), 'workspace')->create();

        $response = $this->actingAs($owner)->put(route('integrations.oauth-application.update', 'google_analytics'), [
            'oauth_client_id' => 'workspace-client-id',
            'oauth_client_secret' => 'workspace-secret-value',
        ]);

        $response->assertRedirect(route('integrations.google-analytics.edit'));
        $credential = IntegrationApplicationCredential::query()->sole();
        $this->assertSame('workspace-client-id', $credential->client_id);
        $this->assertSame('workspace-secret-value', $credential->client_secret);
        $this->assertNotSame('workspace-secret-value', $credential->getRawOriginal('client_secret'));
        $this->assertArrayNotHasKey('client_secret', $credential->toArray());
        $this->actingAs($owner)
            ->get(route('integrations.google-analytics.edit'))
            ->assertOk()
            ->assertSee('workspace-client-id')
            ->assertDontSee('workspace-secret-value');
        $this->assertDatabaseHas('integration_application_credential_events', [
            'workspace_id' => $credential->workspace_id,
            'actor_user_id' => $owner->getKey(),
            'provider_code' => 'google_analytics',
            'action' => 'created',
        ]);
        $this->assertFalse(app('db')->getSchemaBuilder()->hasColumn('integration_application_credential_events', 'client_secret'));
    }

    public function test_validation_failure_does_not_flash_oauth_secret_into_session(): void
    {
        $owner = User::factory()->create();
        Organization::factory()->for($owner, 'owner')->has(Workspace::factory(), 'workspace')->create();

        $response = $this->actingAs($owner)->from(route('integrations.google-analytics.edit'))
            ->put(route('integrations.oauth-application.update', 'google_analytics'), [
                'oauth_client_id' => '',
                'oauth_client_secret' => 'must-not-be-flashed',
            ]);

        $response->assertRedirect(route('integrations.google-analytics.edit'));
        $response->assertSessionHasErrorsIn('oauthApplication', 'oauth_client_id');
        $response->assertSessionHas('_old_input', static function (array $input): bool {
            return ! array_key_exists('oauth_client_secret', $input);
        });
        $this->assertDatabaseMissing('integration_application_credentials', ['provider_code' => 'google_analytics']);
    }

    public function test_workspace_credentials_override_server_credentials_and_are_isolated_by_workspace(): void
    {
        config([
            'services.google_analytics.client_id' => 'legacy-client-id',
            'services.google_analytics.client_secret' => 'legacy-client-secret',
        ]);
        $firstWorkspace = Workspace::factory()->create();
        $secondWorkspace = Workspace::factory()->create();
        IntegrationApplicationCredential::factory()->create([
            'workspace_id' => $firstWorkspace->getKey(),
            'provider_code' => 'google_analytics',
            'client_id' => 'custom-client-id',
            'client_secret' => 'custom-client-secret',
        ]);
        $resolver = app(OAuthApplicationCredentialResolver::class);

        $this->assertSame('custom-client-id', $resolver->resolve('google_analytics', $firstWorkspace->getKey())['client_id']);
        $this->assertSame('workspace', $resolver->resolve('google_analytics', $firstWorkspace->getKey())['source']);
        $this->assertSame('legacy-client-id', $resolver->resolve('google_analytics', $secondWorkspace->getKey())['client_id']);
        $this->assertSame('server', $resolver->resolve('google_analytics', $secondWorkspace->getKey())['source']);
    }

    public function test_server_credential_fallback_can_be_turned_off(): void
    {
        config([
            'services.google_analytics.client_id' => 'legacy-client-id',
            'services.google_analytics.client_secret' => 'legacy-client-secret',
            'services.oauth_legacy_credential_fallback' => false,
        ]);
        $workspace = Workspace::factory()->create();
        $resolver = app(OAuthApplicationCredentialResolver::class);

        $this->assertNull($resolver->resolve('google_analytics', $workspace->getKey()));
        $this->assertFalse($resolver->details('google_analytics', $workspace->getKey())['oauthConfigured']);

        IntegrationApplicationCredential::factory()->create([
            'workspace_id' => $workspace->getKey(),
            'provider_code' => 'google_analytics',
            'client_id' => 'custom-client-id',
            'client_secret' => 'custom-client-secret',
        ]);

        $this->assertSame('workspace', $resolver->resolve('google_analytics', $workspace->getKey())['source']);
    }

    public function test_system_status_lists_workspaces_still_relying_on_server_credentials(): void
    {
        $legacyOwner = User::factory()->create();
        $legacyOrganization = Organization::factory()->create(['owner_user_id' => $legacyOwner->id, 'name' => '舊連線組織']);
        $migratedOwner = User::factory()->create();
        $migratedOrganization = Organization::factory()->create(['owner_user_id' => $migratedOwner->id, 'name' => '已遷移組織']);
        $legacyWorkspaceId = Workspace::factory()->create(['organization_id' => $legacyOrganization->id])->getKey();
        $migratedWorkspaceId = Workspace::factory()->create(['organization_id' => $migratedOrganization->id])->getKey();
        GoogleAdsConnection::factory()->create(['user_id' => $legacyOwner->id])->forceFill(['workspace_id' => $legacyWorkspaceId])->save();
        GoogleAdsConnection::factory()->create(['user_id' => $migratedOwner->id])->forceFill(['workspace_id' => $migratedWorkspaceId])->save();
        IntegrationApplicationCredential::factory()->create([
            'workspace_id' => $migratedWorkspaceId,
            'provider_code' => 'google_ads',
        ]);
        $admin = User::factory()->create();
        $admin->forceFill(['is_platform_admin' => true])->save();

        $dependents = app(OAuthApplicationCredentialResolver::class)->workspacesRelyingOnServerCredentials();

        $this->assertSame([[
            'workspace_id' => $legacyWorkspaceId,
            'organization_name' => '舊連線組織',
            'providers' => ['Google Ads'],
        ]], $dependents);
        $this->actingAs($admin, 'platform')
            ->get(route('platform.system.index'))
            ->assertOk()
            ->assertSee('主機 OAuth 憑證備援')
            ->assertSee($legacyOrganization->name)
            ->assertDontSee($migratedOrganization->name);
    }

    public function test_all_oauth_providers_exchange_codes_using_workspace_credentials(): void
    {
        $providers = [
            ['code' => 'google_analytics', 'config' => 'google_analytics', 'api' => GoogleAnalyticsDataApi::class, 'requests' => 1],
            ['code' => 'google_search_console', 'config' => 'google_search_console', 'api' => GoogleSearchConsoleDataApi::class, 'requests' => 1],
            ['code' => 'google_ads', 'config' => 'google_ads', 'api' => GoogleAdsDataApi::class, 'requests' => 1],
            ['code' => 'meta_marketing', 'config' => 'meta_marketing', 'api' => MetaMarketingDataApi::class, 'requests' => 2],
        ];

        foreach ($providers as $provider) {
            $workspace = Workspace::factory()->create();
            $clientId = 'workspace-'.$provider['code'].'-client';
            $clientSecret = 'workspace-'.$provider['code'].'-secret';
            config([
                'services.'.$provider['config'].'.client_id' => 'legacy-client',
                'services.'.$provider['config'].'.client_secret' => 'legacy-secret',
            ]);
            IntegrationApplicationCredential::factory()->create([
                'workspace_id' => $workspace->getKey(),
                'provider_code' => $provider['code'],
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
            ]);
            Http::fake(static function (ClientRequest $request): mixed {
                return ($request->data()['grant_type'] ?? null) === 'fb_exchange_token'
                    ? Http::response(['access_token' => 'long-lived-token', 'expires_in' => 7200])
                    : Http::response(['access_token' => 'short-lived-token', 'refresh_token' => 'refresh-token', 'expires_in' => 3600]);
            });

            $api = app($provider['api']);
            $api->exchangeAuthorizationCode('authorization-code', 'https://example.test/callback', $workspace->getKey());

            Http::assertSent(function (ClientRequest $request) use ($clientId, $clientSecret): bool {
                return ($request->data()['client_id'] ?? null) === $clientId
                    && ($request->data()['client_secret'] ?? null) === $clientSecret;
            });
            Http::assertSentCount($provider['requests']);
        }
    }

    public function test_blank_secret_keeps_the_saved_secret_and_records_a_non_secret_audit_event(): void
    {
        $owner = User::factory()->create();
        $organization = Organization::factory()->for($owner, 'owner')->has(Workspace::factory(), 'workspace')->create();
        $credential = IntegrationApplicationCredential::factory()->create([
            'workspace_id' => $organization->workspace->getKey(),
            'provider_code' => 'google_analytics',
            'client_id' => 'original-client-id',
            'client_secret' => 'original-secret-value',
        ]);

        $this->actingAs($owner)->put(route('integrations.oauth-application.update', 'google_analytics'), [
            'oauth_client_id' => 'original-client-id',
            'oauth_client_secret' => '',
        ])->assertRedirect(route('integrations.google-analytics.edit'));

        $credential->refresh();
        $this->assertSame('original-client-id', $credential->client_id);
        $this->assertSame('original-secret-value', $credential->client_secret);
        $this->assertDatabaseHas('integration_application_credential_events', [
            'workspace_id' => $credential->workspace_id,
            'actor_user_id' => $owner->getKey(),
            'provider_code' => 'google_analytics',
            'action' => 'updated',
        ]);
        $this->assertSame(1, IntegrationApplicationCredentialEvent::query()->where('action', 'updated')->count());

        $this->actingAs($owner)->from(route('integrations.google-analytics.edit'))
            ->put(route('integrations.oauth-application.update', 'google_analytics'), [
                'oauth_client_id' => 'rotated-client-id',
                'oauth_client_secret' => '',
            ])
            ->assertSessionHasErrorsIn('oauthApplication', 'oauth_client_secret');

        $this->assertSame('original-client-id', $credential->fresh()->client_id);
        $this->assertSame('original-secret-value', $credential->fresh()->client_secret);
    }

    public function test_owner_can_remove_only_the_current_workspaces_custom_credentials(): void
    {
        $owner = User::factory()->create();
        $organization = Organization::factory()->for($owner, 'owner')->has(Workspace::factory(), 'workspace')->create();
        $workspace = $organization->workspace;
        $credential = IntegrationApplicationCredential::factory()->create([
            'workspace_id' => $workspace->getKey(),
            'provider_code' => 'google_analytics',
        ]);
        $otherWorkspaceCredential = IntegrationApplicationCredential::factory()->create([
            'provider_code' => 'google_analytics',
        ]);

        $this->actingAs($owner)->delete(route('integrations.oauth-application.destroy', 'google_analytics'))
            ->assertRedirect(route('integrations.google-analytics.edit'));

        $this->assertModelMissing($credential);
        $this->assertNotNull($otherWorkspaceCredential->fresh());
        $this->assertDatabaseHas('integration_application_credential_events', [
            'workspace_id' => $workspace->getKey(),
            'actor_user_id' => $owner->getKey(),
            'provider_code' => 'google_analytics',
            'action' => 'deleted',
        ]);
    }
}
