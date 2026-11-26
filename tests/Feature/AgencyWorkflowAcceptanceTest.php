<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\GoogleAnalyticsClientProperty;
use App\Models\GoogleAnalyticsConnection;
use App\Models\Organization;
use App\Models\SocialEngagementTarget;
use App\Models\User;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AgencyWorkflowAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_agency_workflow_keeps_members_sources_reports_exports_and_shares_in_their_organizations(): void
    {
        $this->travelTo(Carbon::parse('2026-11-26 12:00:00', 'Asia/Taipei'));
        config([
            'monitoring.transport' => 'fixture',
            'services.google_analytics.client_id' => 'test-client-id',
            'services.google_analytics.client_secret' => 'test-client-secret',
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'test-access-token']),
            'https://analyticsdata.googleapis.com/*' => Http::response([
                'rows' => [],
                'metadata' => ['currencyCode' => 'TWD'],
            ]),
        ]);

        $platformAdmin = User::factory()->create();
        $platformAdmin->forceFill(['is_platform_admin' => true])->save();
        [$agencyOwnerA] = $this->createOrganization($platformAdmin, 'Agency Alpha', 'owner.alpha@example.test');
        [$agencyOwnerB] = $this->createOrganization($platformAdmin, 'Agency Beta', 'owner.beta@example.test');

        $clientA1 = $this->createClient($agencyOwnerA, 'Alpha Client One');
        $clientA2 = $this->createClient($agencyOwnerA, 'Alpha Client Two');
        $clientB1 = $this->createClient($agencyOwnerB, 'Beta Client One');

        $editor = $this->inviteMember(
            $agencyOwnerA,
            'editor.alpha@example.test',
            WorkspaceMembership::ROLE_EDITOR,
            [$clientA1->id],
        );
        $readOnlyMember = $this->inviteMember(
            $agencyOwnerB,
            'reader.beta@example.test',
            WorkspaceMembership::ROLE_READ_ONLY,
            [$clientB1->id],
        );

        $this->assertSame($agencyOwnerA->id, $editor->workspaceOwnerId());
        $this->assertSame(WorkspaceMembership::ROLE_EDITOR, $editor->workspaceMembership?->role);
        $this->assertSame($agencyOwnerB->id, $readOnlyMember->workspaceOwnerId());
        $this->assertSame(WorkspaceMembership::ROLE_READ_ONLY, $readOnlyMember->workspaceMembership?->role);

        $connectionA = GoogleAnalyticsConnection::query()->create([
            'user_id' => $agencyOwnerA->id,
            'refresh_token' => 'alpha-refresh-token',
        ]);
        $connectionB = GoogleAnalyticsConnection::query()->create([
            'user_id' => $agencyOwnerB->id,
            'refresh_token' => 'beta-refresh-token',
        ]);

        $this->actingAs($agencyOwnerA)
            ->put(route('integrations.google-analytics.property.update', $clientA1), ['property_id' => '111111111'])
            ->assertRedirect();
        $this->put(route('integrations.google-analytics.property.update', $clientA2), ['property_id' => '222222222'])
            ->assertRedirect();
        $this->actingAs($agencyOwnerB)
            ->put(route('integrations.google-analytics.property.update', $clientB1), ['property_id' => '333333333'])
            ->assertRedirect();

        $this->assertDatabaseHas('google_analytics_client_properties', [
            'google_analytics_connection_id' => $connectionA->id,
            'client_id' => $clientA1->id,
            'property_id' => '111111111',
        ]);
        $this->assertDatabaseHas('google_analytics_client_properties', [
            'google_analytics_connection_id' => $connectionA->id,
            'client_id' => $clientA2->id,
            'property_id' => '222222222',
        ]);
        $this->assertDatabaseHas('google_analytics_client_properties', [
            'google_analytics_connection_id' => $connectionB->id,
            'client_id' => $clientB1->id,
            'property_id' => '333333333',
        ]);

        $targetA1 = $this->createTarget($agencyOwnerA, $clientA1, 'Alpha Launch');
        $targetA2 = $this->createTarget($agencyOwnerA, $clientA2, 'Alpha Private Campaign');
        $targetB1 = $this->createTarget($agencyOwnerB, $clientB1, 'Beta Private Campaign');

        $this->actingAs($agencyOwnerA)
            ->post(route('social-engagement-targets.google-analytics.sync', $targetA1))
            ->assertRedirect(route('social-engagement-targets.report', $targetA1))
            ->assertSessionHas('status', 'GA4 活動成效已同步。');
        $this->actingAs($agencyOwnerB)
            ->post(route('social-engagement-targets.google-analytics.sync', $targetB1))
            ->assertRedirect(route('social-engagement-targets.report', $targetB1))
            ->assertSessionHas('status', 'GA4 活動成效已同步。');
        $this->actingAs($agencyOwnerA)
            ->get(route('social-engagement-targets.report', $targetB1))
            ->assertNotFound();
        $this->post(route('social-engagement-targets.google-analytics.sync', $targetB1))
            ->assertNotFound();

        $this->assertDatabaseHas('social_engagement_target_google_analytics_reports', [
            'google_analytics_connection_id' => $connectionA->id,
            'social_engagement_target_id' => $targetA1->id,
            'property_id' => '111111111',
        ]);
        $this->assertDatabaseHas('social_engagement_target_google_analytics_reports', [
            'google_analytics_connection_id' => $connectionB->id,
            'social_engagement_target_id' => $targetB1->id,
            'property_id' => '333333333',
        ]);
        Http::assertSent(fn (ClientRequest $request): bool => str_contains($request->url(), '/properties/111111111:runReport'));
        Http::assertSent(fn (ClientRequest $request): bool => str_contains($request->url(), '/properties/333333333:runReport'));
        Http::assertNotSent(fn (ClientRequest $request): bool => str_contains($request->url(), '/properties/222222222:runReport'));

        $this->actingAs($editor)
            ->get(route('social-engagement-targets.report', $targetA1))
            ->assertOk()
            ->assertSee('Alpha Launch');
        $this->get(route('social-engagement-targets.report', $targetA2))->assertNotFound();
        $this->get(route('integrations.google-analytics.edit'))->assertForbidden();
        $editorPortfolio = $this->get(route('clients.performance'))
            ->assertOk()
            ->assertSee('Alpha Client One')
            ->assertDontSee('Alpha Client Two')
            ->assertDontSee('Beta Client One');
        $editorPortfolioCsv = $this->get(route('clients.performance.export'))
            ->assertOk()
            ->streamedContent();
        $this->assertStringContainsString('Alpha Client One', $editorPortfolioCsv);
        $this->assertStringNotContainsString('Alpha Client Two', $editorPortfolioCsv);
        $this->assertStringNotContainsString('Beta Client One', $editorPortfolioCsv);
        $this->assertSame([$clientA1->id], collect($editorPortfolio->viewData('clientPortfolioRows'))
            ->pluck('client.id')
            ->all());

        $this->actingAs($readOnlyMember)
            ->get(route('clients.index'))
            ->assertOk()
            ->assertSee('Beta Client One')
            ->assertDontSee('Alpha Client One')
            ->assertDontSee('Alpha Client Two');
        $this->post(route('clients.store'), ['name' => 'Unauthorized Client'])->assertForbidden();
        $this->put(route('integrations.google-analytics.property.update', $clientB1), ['property_id' => '444444444'])
            ->assertForbidden();

        $this->actingAs($agencyOwnerA)
            ->get(route('clients.performance'))
            ->assertOk()
            ->assertSee('Alpha Client One')
            ->assertSee('Alpha Client Two')
            ->assertDontSee('Beta Client One');
        $agencyPortfolioCsv = $this->get(route('clients.performance.export'))
            ->assertOk()
            ->streamedContent();
        $this->assertStringContainsString('Alpha Client One', $agencyPortfolioCsv);
        $this->assertStringContainsString('Alpha Client Two', $agencyPortfolioCsv);
        $this->assertStringNotContainsString('Beta Client One', $agencyPortfolioCsv);

        $shareResponse = $this->post(route('clients.report-shares.store', $clientA1), [
            'name' => 'Alpha Client One monthly report',
            'expires_in_days' => '7',
            'from' => '2026-11-01',
            'to' => '2026-11-25',
            'target_ids' => [$targetA1->id],
        ])->assertRedirect(route('clients.report-shares.index', $clientA1));
        $shareUrl = (string) $shareResponse->getSession()->get('createdShareUrl');
        $shareToken = basename((string) parse_url($shareUrl, PHP_URL_PATH));

        $this->get(route('client-report-shares.public', ['token' => $shareToken]))
            ->assertOk()
            ->assertSee('Alpha Client One')
            ->assertSee('Alpha Launch')
            ->assertDontSee('Alpha Client Two')
            ->assertDontSee('Alpha Private Campaign')
            ->assertDontSee('Beta Client One')
            ->assertDontSee('Beta Private Campaign');
        $sharedReportCsv = $this->get(route('client-report-shares.public.export', ['token' => $shareToken]))
            ->assertOk()
            ->streamedContent();
        $this->assertStringContainsString('Alpha Client One', $sharedReportCsv);
        $this->assertStringContainsString('Alpha Launch', $sharedReportCsv);
        $this->assertStringNotContainsString('Alpha Client Two', $sharedReportCsv);
        $this->assertStringNotContainsString('Beta Client One', $sharedReportCsv);

        $this->actingAs($agencyOwnerB)
            ->get(route('clients.report-shares.index', $clientA1))
            ->assertNotFound();
        $this->actingAs($agencyOwnerA);
        $this->assertInstanceOf(GoogleAnalyticsClientProperty::class, $clientA1->googleAnalyticsProperty()->firstOrFail());
    }

    /** @return array{User, Organization} */
    private function createOrganization(User $platformAdmin, string $name, string $ownerEmail): array
    {
        $this->actingAs($platformAdmin)
            ->post(route('platform.organizations.store'), [
                'name' => $name,
                'owner_name' => $name.' Owner',
                'owner_email' => $ownerEmail,
                'owner_password' => 'agency-owner-password-123',
                'owner_password_confirmation' => 'agency-owner-password-123',
                'subscription_ends_on' => '2027-12-31',
                'seat_limit' => 5,
                'plan_name' => 'Agency trial',
                'client_limit' => 10,
                'monitor_limit' => 100,
                'campaign_limit' => 50,
            ])
            ->assertRedirect(route('platform.index'))
            ->assertSessionHas('status', '組織與負責人帳號已建立。');

        $owner = User::query()->where('email', $ownerEmail)->firstOrFail();
        $organization = Organization::query()->where('owner_user_id', $owner->id)->firstOrFail();

        return [$owner, $organization];
    }

    private function createClient(User $owner, string $name): Client
    {
        $this->actingAs($owner)
            ->post(route('clients.store'), ['name' => $name])
            ->assertRedirect(route('clients.index'));

        return Client::query()
            ->where('user_id', $owner->id)
            ->where('name', $name)
            ->firstOrFail();
    }

    /** @param list<int> $clientIds */
    private function inviteMember(User $owner, string $email, string $role, array $clientIds): User
    {
        $response = $this->actingAs($owner)->post(route('team.invitations.store'), [
            'email' => $email,
            'role' => $role,
            'client_scope' => WorkspaceMembership::CLIENT_SCOPE_SELECTED,
            'client_ids' => $clientIds,
        ])->assertRedirect(route('team.index'));
        $inviteUrl = (string) $response->getSession()->get('createdInviteUrl');
        $token = basename((string) parse_url($inviteUrl, PHP_URL_PATH));

        $this->post(route('logout'))->assertRedirect(route('login'));
        $this->get(route('team.invitations.show', ['token' => $token]))->assertOk();
        $this->post(route('team.invitations.accept', ['token' => $token]), [
            'name' => $email,
            'password' => 'agency-member-password-123',
            'password_confirmation' => 'agency-member-password-123',
        ])->assertRedirect(route('dashboard'));

        return User::query()->where('email', $email)->firstOrFail();
    }

    private function createTarget(User $owner, Client $client, string $tag): SocialEngagementTarget
    {
        $this->actingAs($owner);

        return SocialEngagementTarget::factory()->create([
            'user_id' => $owner->id,
            'client_id' => $client->id,
            'tag' => $tag,
            'social_platform' => 'facebook',
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-25',
        ]);
    }
}
