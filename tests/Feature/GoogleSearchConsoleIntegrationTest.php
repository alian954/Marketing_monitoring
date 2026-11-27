<?php

namespace Tests\Feature;

use App\Models\GoogleSearchConsoleConnection;
use App\Models\User;
use App\Models\WorkspaceMembership;
use App\Services\GoogleSearchConsoleApiException;
use App\Services\GoogleSearchConsoleDataApi;
use App\Services\GoogleSearchConsoleReportCsv;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GoogleSearchConsoleIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.google_search_console.client_id' => 'test-google-search-console-client',
            'services.google_search_console.client_secret' => 'test-google-search-console-secret',
        ]);
    }

    public function test_search_analytics_retries_quota_and_connection_failures_before_succeeding(): void
    {
        Http::fake([
            'https://www.googleapis.com/webmasters/v3/sites/*/searchAnalytics/query' => Http::sequence()
                ->push(['error' => ['message' => 'quota exceeded']], 429, ['Retry-After' => '1'])
                ->pushFailedConnection()
                ->push(['rows' => [['clicks' => 25, 'impressions' => 500, 'ctr' => 0.05, 'position' => 4.2]]])
                ->push(['rows' => [['keys' => ['ordinary SEO query'], 'clicks' => 25, 'impressions' => 500, 'ctr' => 0.05, 'position' => 4.2]]]),
        ]);

        $report = app(GoogleSearchConsoleDataApi::class)->performanceReport(
            'short-lived-access-token',
            'https://example.com/',
            '2026-11-01',
            '2026-11-02',
            'query',
        );

        $this->assertSame(25.0, $report['totals']['clicks']);
        $this->assertSame('ordinary SEO query', $report['rows'][0]['label']);
        Http::assertSentCount(4);
        Sleep::assertSequence([
            Sleep::for(1)->second(),
            Sleep::for(750)->milliseconds(),
        ]);
    }

    public function test_search_analytics_stops_after_two_retries_when_quota_is_exhausted(): void
    {
        Http::fake([
            'https://www.googleapis.com/webmasters/v3/sites/*/searchAnalytics/query' => Http::sequence()
                ->push(['error' => ['message' => 'quota exceeded']], 429, ['Retry-After' => '1'])
                ->push(['error' => ['message' => 'quota exceeded']], 429, ['Retry-After' => '1'])
                ->push(['error' => ['message' => 'quota exceeded']], 429, ['Retry-After' => '1']),
        ]);

        $exception = null;

        try {
            app(GoogleSearchConsoleDataApi::class)->performanceReport(
                'short-lived-access-token',
                'https://example.com/',
                '2026-11-01',
                '2026-11-02',
                'query',
            );
        } catch (GoogleSearchConsoleApiException $caughtException) {
            $exception = $caughtException;
        }

        $this->assertInstanceOf(GoogleSearchConsoleApiException::class, $exception);
        $this->assertSame('Google Search Console API 配額已用盡，請稍後再試。', $exception->getMessage());
        Http::assertSentCount(3);
        Sleep::assertSequence([
            Sleep::for(1)->second(),
            Sleep::for(1)->second(),
        ]);
    }

    public function test_search_analytics_does_not_retry_permission_errors(): void
    {
        Http::fake([
            'https://www.googleapis.com/webmasters/v3/sites/*/searchAnalytics/query' => Http::response([
                'error' => ['message' => 'permission denied'],
            ], 403),
        ]);

        $exception = null;

        try {
            app(GoogleSearchConsoleDataApi::class)->performanceReport(
                'short-lived-access-token',
                'https://example.com/',
                '2026-11-01',
                '2026-11-02',
                'query',
            );
        } catch (GoogleSearchConsoleApiException $caughtException) {
            $exception = $caughtException;
        }

        $this->assertInstanceOf(GoogleSearchConsoleApiException::class, $exception);
        $this->assertSame(
            '目前 Google 帳戶沒有此 Search Console 網站的讀取權限，請確認網站授權或改選其他網站。',
            $exception->getMessage(),
        );
        Http::assertSentCount(1);
        Sleep::assertNeverSlept();
    }

    #[DataProvider('searchConsoleRateLimitReasons')]
    public function test_search_analytics_reports_403_quota_reasons_without_mislabeling_them_as_permission_errors(string $reason): void
    {
        Http::fake([
            'https://www.googleapis.com/webmasters/v3/sites/*/searchAnalytics/query' => Http::response([
                'error' => [
                    'code' => 403,
                    'message' => 'Quota exceeded.',
                    'errors' => [[
                        'domain' => 'usageLimits',
                        'reason' => $reason,
                    ]],
                ],
            ], 403),
        ]);

        try {
            app(GoogleSearchConsoleDataApi::class)->performanceReport(
                'short-lived-access-token',
                'https://example.com/',
                '2026-11-01',
                '2026-11-02',
                'query',
            );
            $this->fail('Quota errors should be reported to the user.');
        } catch (GoogleSearchConsoleApiException $exception) {
            $this->assertSame('Google Search Console API 配額已用盡，請稍後再試。', $exception->getMessage());
        }

        Http::assertSentCount(1);
        Sleep::assertNeverSlept();
    }

    /** @return iterable<string, array{string}> */
    public static function searchConsoleRateLimitReasons(): iterable
    {
        foreach ([
            'concurrentLimitExceeded',
            'dailyLimitExceeded',
            'limitExceeded',
            'quotaExceeded',
            'rateLimitExceeded',
            'servingLimitExceeded',
            'userRateLimitExceeded',
        ] as $reason) {
            yield $reason => [$reason];
        }
    }

    public function test_search_analytics_reports_payment_quota_as_a_billing_configuration_issue(): void
    {
        Http::fake([
            'https://www.googleapis.com/webmasters/v3/sites/*/searchAnalytics/query' => Http::response([
                'error' => [
                    'code' => 402,
                    'errors' => [[
                        'reason' => 'quotaExceeded402',
                    ]],
                ],
            ], 402),
        ]);

        try {
            app(GoogleSearchConsoleDataApi::class)->performanceReport(
                'short-lived-access-token',
                'https://example.com/',
                '2026-11-01',
                '2026-11-02',
                'query',
            );
            $this->fail('Billing quota errors should be reported to the user.');
        } catch (GoogleSearchConsoleApiException $exception) {
            $this->assertSame(
                'Google Search Console API 費用或用量預算已達上限，請檢查 Google Cloud 帳單與配額設定。',
                $exception->getMessage(),
            );
        }

        Http::assertSentCount(1);
        Sleep::assertNeverSlept();
    }

    public function test_integration_settings_require_authentication(): void
    {
        Http::preventStrayRequests();

        $this->get(route('integrations.google-search-console.edit'))
            ->assertRedirect(route('login'));

        Http::assertNothingSent();
    }

    public function test_integration_page_lists_accessible_sites_without_exposing_the_refresh_token(): void
    {
        config([
            'services.google_search_console.client_id' => 'google-search-console-client',
            'services.google_search_console.client_secret' => 'google-search-console-secret',
        ]);
        $user = User::factory()->create();
        $connection = GoogleSearchConsoleConnection::factory()->for($user)->create([
            'site_url' => 'https://example.com/',
            'refresh_token' => 'private-search-console-refresh-token',
        ]);
        $this->fakeConnectedSiteList();

        $this->actingAs($user)
            ->get(route('integrations.google-search-console.edit'))
            ->assertOk()
            ->assertSee('Google Search Console')
            ->assertSee('https://example.com/')
            ->assertSee('Search Console 唯讀權限')
            ->assertSee('自然搜尋成效')
            ->assertDontSee('private-search-console-refresh-token');

        $storedToken = DB::table('google_search_console_connections')
            ->where('id', $connection->id)
            ->value('refresh_token');

        $this->assertIsString($storedToken);
        $this->assertStringNotContainsString('private-search-console-refresh-token', $storedToken);
    }

    public function test_expired_refresh_token_is_reported_without_deleting_the_saved_connection(): void
    {
        config([
            'services.google_search_console.client_id' => 'google-search-console-client',
            'services.google_search_console.client_secret' => 'google-search-console-secret',
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'error' => 'invalid_grant',
                'error_description' => 'Token has been expired or revoked.',
            ], 400),
        ]);
        $user = User::factory()->create();
        $connection = GoogleSearchConsoleConnection::factory()->for($user)->create([
            'refresh_token' => 'expired-private-refresh-token',
        ]);

        $this->actingAs($user)
            ->get(route('integrations.google-search-console.edit'))
            ->assertOk()
            ->assertSee('Google 授權憑證已失效或已撤銷，請重新連結帳戶。')
            ->assertDontSee('expired-private-refresh-token');

        $this->assertDatabaseHas('google_search_console_connections', ['id' => $connection->getKey()]);
        $this->assertSame('expired-private-refresh-token', $connection->fresh()->refresh_token);
        Http::assertSentCount(1);
    }

    public function test_connect_reports_missing_oauth_credentials(): void
    {
        config([
            'services.google_search_console.client_id' => null,
            'services.google_search_console.client_secret' => null,
        ]);
        Http::preventStrayRequests();

        $this->actingAs(User::factory()->create())
            ->from(route('integrations.google-search-console.edit'))
            ->post(route('integrations.google-search-console.connect'))
            ->assertRedirect(route('integrations.google-search-console.edit'))
            ->assertSessionHasErrors(['google_search_console']);

        Http::assertNothingSent();
    }

    public function test_connect_requests_read_only_search_console_scope_and_session_state(): void
    {
        config([
            'services.google_search_console.client_id' => 'google-client-id',
            'services.google_search_console.client_secret' => 'google-client-secret',
            'services.google_search_console.redirect_uri' => 'https://monitor.example.test/integrations/google-search-console/callback',
        ]);
        Http::preventStrayRequests();

        $response = $this->actingAs(User::factory()->create())
            ->post(route('integrations.google-search-console.connect'))
            ->assertRedirect();
        $location = (string) $response->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        $this->assertSame('accounts.google.com', parse_url($location, PHP_URL_HOST));
        $this->assertSame('google-client-id', $query['client_id']);
        $this->assertSame('https://www.googleapis.com/auth/webmasters.readonly', $query['scope']);
        $this->assertSame('https://monitor.example.test/integrations/google-search-console/callback', $query['redirect_uri']);
        $this->assertSame('offline', $query['access_type']);
        $this->assertSame('consent', $query['prompt']);
        $this->assertSame(session('google_search_console.oauth_state'), $query['state']);
        Http::assertNothingSent();
    }

    public function test_oauth_callback_rejects_a_state_that_does_not_match_the_session(): void
    {
        Http::preventStrayRequests();

        $this->actingAs(User::factory()->create())
            ->withSession(['google_search_console.oauth_state' => 'expected-state'])
            ->get(route('integrations.google-search-console.callback', [
                'state' => 'untrusted-state',
                'code' => 'authorization-code',
            ]))
            ->assertRedirect(route('integrations.google-search-console.edit'))
            ->assertSessionHasErrors(['google_search_console']);

        $this->assertDatabaseCount('google_search_console_connections', 0);
        Http::assertNothingSent();
    }

    public function test_oauth_callback_saves_an_encrypted_refresh_token(): void
    {
        config([
            'services.google_search_console.client_id' => 'google-client-id',
            'services.google_search_console.client_secret' => 'google-client-secret',
            'services.google_search_console.redirect_uri' => 'https://monitor.example.test/integrations/google-search-console/callback',
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'short-lived-access-token',
                'refresh_token' => 'private-refresh-token',
                'expires_in' => 3600,
            ]),
        ]);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withSession(['google_search_console.oauth_state' => 'trusted-state'])
            ->get(route('integrations.google-search-console.callback', [
                'state' => 'trusted-state',
                'code' => 'authorization-code',
            ]))
            ->assertRedirect(route('integrations.google-search-console.edit'))
            ->assertSessionHas('status', 'Google Search Console 已連結。請選擇要查看的網站。');

        $connection = GoogleSearchConsoleConnection::query()->sole();
        $storedToken = DB::table('google_search_console_connections')->where('id', $connection->id)->value('refresh_token');

        $this->assertSame($user->id, $connection->user_id);
        $this->assertSame('private-refresh-token', $connection->refresh_token);
        $this->assertStringNotContainsString('private-refresh-token', (string) $storedToken);
        Http::assertSent(fn (ClientRequest $request): bool => $request->url() === 'https://oauth2.googleapis.com/token'
            && $request->data()['grant_type'] === 'authorization_code'
            && $request->data()['code'] === 'authorization-code'
            && $request->data()['redirect_uri'] === 'https://monitor.example.test/integrations/google-search-console/callback');
    }

    public function test_oauth_callback_preserves_the_existing_refresh_token_when_google_does_not_return_a_new_one(): void
    {
        config([
            'services.google_search_console.client_id' => 'google-client-id',
            'services.google_search_console.client_secret' => 'google-client-secret',
            'services.google_search_console.redirect_uri' => 'https://monitor.example.test/integrations/google-search-console/callback',
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'reauthorized-access-token',
                'expires_in' => 3600,
            ]),
        ]);
        $user = User::factory()->create();
        $connection = GoogleSearchConsoleConnection::factory()->for($user)->create([
            'refresh_token' => 'existing-private-refresh-token',
        ]);

        $this->actingAs($user)
            ->withSession(['google_search_console.oauth_state' => 'reauthorize-state'])
            ->get(route('integrations.google-search-console.callback', [
                'state' => 'reauthorize-state',
                'code' => 'reauthorization-code',
            ]))
            ->assertRedirect(route('integrations.google-search-console.edit'))
            ->assertSessionHas('status', 'Google Search Console 已連結。請選擇要查看的網站。');

        $this->assertDatabaseCount('google_search_console_connections', 1);
        $this->assertSame('existing-private-refresh-token', $connection->fresh()->refresh_token);
        Http::assertSent(fn (ClientRequest $request): bool => $request->url() === 'https://oauth2.googleapis.com/token'
            && $request->data()['grant_type'] === 'authorization_code'
            && $request->data()['code'] === 'reauthorization-code');
    }

    public function test_workspace_member_cannot_manage_the_owners_search_console_connection(): void
    {
        Http::preventStrayRequests();
        $owner = User::factory()->create();
        $member = User::factory()->create();
        GoogleSearchConsoleConnection::factory()->for($owner)->create();
        WorkspaceMembership::factory()->create([
            'owner_user_id' => $owner->id,
            'member_user_id' => $member->id,
            'role' => WorkspaceMembership::ROLE_EDITOR,
        ]);

        $this->actingAs($member)
            ->get(route('integrations.google-search-console.edit'))
            ->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_site_update_saves_only_a_site_returned_by_google(): void
    {
        $user = User::factory()->create();
        $client = $user->clients()->create(['name' => 'Northwind']);
        $connection = GoogleSearchConsoleConnection::factory()->for($user)->create([
            'site_url' => 'https://old.example.com/',
            'refresh_token' => 'private-refresh-token',
        ]);
        $this->fakeConnectedSiteList();

        $this->actingAs($user)
            ->put(route('integrations.google-search-console.site.update'), [
                'client_id' => $client->getKey(),
                'site_url' => 'sc-domain:example.com',
            ])
            ->assertRedirect(route('integrations.google-search-console.edit', ['client_id' => $client->getKey()]))
            ->assertSessionHas('status', 'Northwind的 Search Console 網站已儲存。');

        $this->assertSame('sc-domain:example.com', $connection->fresh()->site_url);
        $this->assertSame('sc-domain:example.com', $connection->clientSites()->sole()->site_url);
    }

    public function test_site_update_rejects_a_site_not_accessible_to_the_google_account(): void
    {
        $user = User::factory()->create();
        $client = $user->clients()->create(['name' => 'Northwind']);
        $connection = GoogleSearchConsoleConnection::factory()->for($user)->create([
            'site_url' => 'https://example.com/',
            'refresh_token' => 'private-refresh-token',
        ]);
        $this->fakeConnectedSiteList();

        $this->actingAs($user)
            ->put(route('integrations.google-search-console.site.update'), [
                'client_id' => $client->getKey(),
                'site_url' => 'https://other.example/',
            ])
            ->assertRedirect(route('integrations.google-search-console.edit'))
            ->assertSessionHasErrors(['site_url']);

        $this->assertSame('https://example.com/', $connection->fresh()->site_url);
        $this->assertDatabaseCount('google_search_console_client_sites', 0);
    }

    public function test_site_update_rejects_a_client_from_another_workspace(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $foreignClient = $otherUser->clients()->create(['name' => 'Foreign client']);
        $connection = GoogleSearchConsoleConnection::factory()->for($user)->create([
            'site_url' => 'https://legacy.example.com/',
            'refresh_token' => 'private-refresh-token',
        ]);
        Http::preventStrayRequests();

        $this->actingAs($user)
            ->put(route('integrations.google-search-console.site.update'), [
                'client_id' => $foreignClient->getKey(),
                'site_url' => 'https://example.com/',
            ])
            ->assertNotFound();

        $this->assertSame('https://legacy.example.com/', $connection->fresh()->site_url);
        $this->assertDatabaseCount('google_search_console_client_sites', 0);
        Http::assertNothingSent();
    }

    public function test_integration_page_shows_each_clients_selected_site(): void
    {
        config([
            'services.google_search_console.client_id' => 'google-search-console-client',
            'services.google_search_console.client_secret' => 'google-search-console-secret',
        ]);
        $user = User::factory()->create();
        $northwind = $user->clients()->create(['name' => 'Northwind']);
        $contoso = $user->clients()->create(['name' => 'Contoso']);
        $connection = GoogleSearchConsoleConnection::factory()->for($user)->create([
            'site_url' => 'https://legacy.example.com/',
        ]);
        $connection->clientSites()->createMany([
            ['client_id' => $northwind->getKey(), 'site_url' => 'https://northwind.example/'],
            ['client_id' => $contoso->getKey(), 'site_url' => 'https://contoso.example/'],
        ]);
        $this->fakeConnectedSiteList([
            ['siteUrl' => 'https://northwind.example/', 'permissionLevel' => 'siteOwner'],
            ['siteUrl' => 'https://contoso.example/', 'permissionLevel' => 'siteFullUser'],
        ]);

        $this->actingAs($user)
            ->get(route('integrations.google-search-console.edit'))
            ->assertOk()
            ->assertSee('Northwind · Search Console 網站')
            ->assertSee('Contoso · Search Console 網站')
            ->assertSee('https://northwind.example/')
            ->assertSee('https://contoso.example/')
            ->assertSee('每個客戶選擇一個已授權網站。');
    }

    public function test_reports_and_csv_use_the_selected_clients_site(): void
    {
        $this->travelTo('2026-11-24 12:00:00');
        $user = User::factory()->create();
        $northwind = $user->clients()->create(['name' => 'Northwind']);
        $contoso = $user->clients()->create(['name' => 'Contoso']);
        $connection = GoogleSearchConsoleConnection::factory()->for($user)->create([
            'site_url' => null,
            'refresh_token' => 'private-search-console-refresh-token',
        ]);
        $connection->clientSites()->createMany([
            ['client_id' => $northwind->getKey(), 'site_url' => 'https://northwind.example/'],
            ['client_id' => $contoso->getKey(), 'site_url' => 'https://contoso.example/'],
        ]);
        $this->fakeClientSiteReports();
        $startsOn = CarbonImmutable::now('America/Los_Angeles')->subDays(13)->toDateString();
        $endsOn = CarbonImmutable::now('America/Los_Angeles')->subDays(7)->toDateString();
        $reportInputs = [
            'start_date' => $startsOn,
            'end_date' => $endsOn,
            'dimension' => 'query',
            'search_type' => 'web',
        ];

        $this->actingAs($user)
            ->get(route('integrations.google-search-console.report', $reportInputs + ['client_id' => $northwind->getKey()]))
            ->assertRedirect(route('integrations.google-search-console.edit'))
            ->assertSessionHas('searchConsoleReport');

        $northwindReport = session('searchConsoleReport');
        $this->assertSame('Northwind', $northwindReport['client_name']);
        $this->assertSame('https://northwind.example/', $northwindReport['site_url']);

        $this->get(route('integrations.google-search-console.report', $reportInputs + ['client_id' => $contoso->getKey()]))
            ->assertRedirect(route('integrations.google-search-console.edit'))
            ->assertSessionHas('searchConsoleReport');

        $contosoReport = session('searchConsoleReport');
        $this->assertSame('Contoso', $contosoReport['client_name']);
        $this->assertSame('https://contoso.example/', $contosoReport['site_url']);

        $this->get(route('integrations.google-search-console.report.export', $reportInputs + ['client_id' => $contoso->getKey()]))
            ->assertDownload('search-console-'.$startsOn.'-'.$endsOn.'.csv')
            ->assertStreamedContent("\xEF\xBB\xBFclient_name,site_url,start_date,end_date,dimension,search_type,dimension_value,clicks,impressions,ctr_percent,average_position\nContoso,https://contoso.example/,$startsOn,$endsOn,query,web,\"contoso search\",30,600,5,2\n");

        Http::assertSent(fn (ClientRequest $request): bool => str_contains($request->url(), rawurlencode('https://northwind.example/')));
        Http::assertSent(fn (ClientRequest $request): bool => str_contains($request->url(), rawurlencode('https://contoso.example/')));
    }

    public function test_unassigned_legacy_site_is_not_used_for_multiple_clients(): void
    {
        $this->travelTo('2026-11-24 12:00:00');
        $user = User::factory()->create();
        $northwind = $user->clients()->create(['name' => 'Northwind']);
        $user->clients()->create(['name' => 'Contoso']);
        GoogleSearchConsoleConnection::factory()->for($user)->create([
            'site_url' => 'https://legacy.example.com/',
            'refresh_token' => 'private-search-console-refresh-token',
        ]);
        Http::preventStrayRequests();

        $this->actingAs($user)
            ->from(route('integrations.google-search-console.edit'))
            ->get(route('integrations.google-search-console.report', [
                'client_id' => $northwind->getKey(),
                'start_date' => '2026-11-01',
                'end_date' => '2026-11-10',
                'dimension' => 'query',
                'search_type' => 'web',
            ]))
            ->assertRedirect(route('integrations.google-search-console.edit'))
            ->assertSessionHasErrors(['google_search_console' => '請先為所選客戶選擇 Search Console 網站。']);

        Http::assertNothingSent();
    }

    public function test_report_and_export_reject_another_workspaces_client(): void
    {
        $this->travelTo('2026-11-24 12:00:00');
        $user = User::factory()->create();
        $user->clients()->create(['name' => 'Northwind']);
        $otherUser = User::factory()->create();
        $foreignClient = $otherUser->clients()->create(['name' => 'Foreign client']);
        GoogleSearchConsoleConnection::factory()->for($user)->create([
            'site_url' => 'https://northwind.example/',
            'refresh_token' => 'private-search-console-refresh-token',
        ]);
        Http::preventStrayRequests();
        $parameters = [
            'client_id' => $foreignClient->getKey(),
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-10',
            'dimension' => 'query',
            'search_type' => 'web',
        ];

        $this->actingAs($user)
            ->from(route('integrations.google-search-console.edit'))
            ->get(route('integrations.google-search-console.report', $parameters))
            ->assertRedirect(route('integrations.google-search-console.edit'))
            ->assertSessionHasErrors(['client_id' => '請選擇工作區中可存取的客戶。']);

        $this->from(route('integrations.google-search-console.edit'))
            ->get(route('integrations.google-search-console.report.export', $parameters))
            ->assertRedirect(route('integrations.google-search-console.edit'))
            ->assertSessionHasErrors(['client_id' => '請選擇工作區中可存取的客戶。']);

        Http::assertNothingSent();
    }

    public function test_report_displays_search_metrics_and_compares_the_previous_period(): void
    {
        $user = User::factory()->create();
        GoogleSearchConsoleConnection::factory()->for($user)->create([
            'site_url' => 'https://example.com/',
            'refresh_token' => 'private-refresh-token',
        ]);
        $startsOn = CarbonImmutable::now('America/Los_Angeles')->subDays(13)->toDateString();
        $endsOn = CarbonImmutable::now('America/Los_Angeles')->subDays(7)->toDateString();
        $this->fakeReportRequests(true, true);

        $this->actingAs($user)
            ->get(route('integrations.google-search-console.report', [
                'start_date' => $startsOn,
                'end_date' => $endsOn,
                'dimension' => 'query',
                'search_type' => 'image',
                'compare' => '1',
            ]))
            ->assertRedirect(route('integrations.google-search-console.edit'))
            ->assertSessionHas('searchConsoleReport');

        $report = session('searchConsoleReport');
        $this->assertSame(120, $report['current']['totals']['clicks']);
        $this->assertSame(0.12, $report['current']['totals']['ctr']);
        $this->assertSame('available', $report['comparison']['status']);
        $this->assertSame(20, $report['comparison']['changes']['clicks']['difference']);

        $this->get(route('integrations.google-search-console.edit'))
            ->assertOk()
            ->assertSee('搜尋查詢成效')
            ->assertSee('自然搜尋成效')
            ->assertSee('search quality example')
            ->assertSee('圖片搜尋')
            ->assertSee('點閱率')
            ->assertSee('前期：');

        Http::assertSent(fn (ClientRequest $request): bool => str_ends_with($request->url(), '/searchAnalytics/query')
            && $request->data()['startDate'] === $startsOn
            && $request->data()['endDate'] === $endsOn
            && $request->data()['dataState'] === 'final'
            && $request->data()['type'] === 'image');
    }

    public function test_report_rejects_invalid_dimensions_and_dates_before_calling_google(): void
    {
        $user = User::factory()->create();
        GoogleSearchConsoleConnection::factory()->for($user)->create([
            'site_url' => 'https://example.com/',
            'refresh_token' => 'private-refresh-token',
        ]);
        Http::preventStrayRequests();

        $this->actingAs($user)
            ->from(route('integrations.google-search-console.edit'))
            ->get(route('integrations.google-search-console.report', [
                'start_date' => CarbonImmutable::now('America/Los_Angeles')->subMonthsNoOverflow(18)->toDateString(),
                'end_date' => CarbonImmutable::now('America/Los_Angeles')->subDays(5)->toDateString(),
                'dimension' => 'invalid',
                'search_type' => 'invalid',
            ]))
            ->assertRedirect(route('integrations.google-search-console.edit'))
            ->assertSessionHasErrors(['start_date', 'dimension', 'search_type']);

        Http::assertNothingSent();
    }

    #[DataProvider('unsupportedSearchTypeDimensions')]
    public function test_report_rejects_dimensions_not_supported_by_the_selected_search_type(
        string $searchType,
        string $dimension,
        string $expectedError,
    ): void {
        $this->travelTo(CarbonImmutable::parse('2026-11-24 12:00:00', 'America/Los_Angeles'));
        $user = User::factory()->create();
        GoogleSearchConsoleConnection::factory()->for($user)->create([
            'site_url' => 'https://example.com/',
            'refresh_token' => 'private-refresh-token',
        ]);
        Http::preventStrayRequests();

        $this->actingAs($user)
            ->from(route('integrations.google-search-console.edit'))
            ->get(route('integrations.google-search-console.report', [
                'start_date' => '2026-11-01',
                'end_date' => '2026-11-10',
                'dimension' => $dimension,
                'search_type' => $searchType,
            ]))
            ->assertRedirect(route('integrations.google-search-console.edit'))
            ->assertSessionHasErrors(['dimension' => $expectedError]);

        Http::assertNothingSent();
    }

    /** @return array<string, array{string, string, string}> */
    public static function unsupportedSearchTypeDimensions(): array
    {
        return [
            'Discover query dimension' => ['discover', 'query', 'Discover 報表僅支援頁面或國家／地區維度。'],
            'Discover device dimension' => ['discover', 'device', 'Discover 報表僅支援頁面或國家／地區維度。'],
            'Google News query dimension' => ['googleNews', 'query', 'Google News 報表不提供搜尋查詢維度，請選擇頁面、國家／地區或裝置。'],
            'Web multimodal query dimension' => ['webMultimodal', 'query', '多模態搜尋不提供搜尋查詢維度，請選擇頁面、國家／地區或裝置。'],
        ];
    }

    public function test_csv_export_downloads_rows_and_sanitizes_search_queries(): void
    {
        $user = User::factory()->create();
        GoogleSearchConsoleConnection::factory()->for($user)->create([
            'site_url' => 'https://example.com/',
            'refresh_token' => 'private-refresh-token',
        ]);
        $startsOn = CarbonImmutable::now('America/Los_Angeles')->subDays(10)->toDateString();
        $endsOn = CarbonImmutable::now('America/Los_Angeles')->subDays(5)->toDateString();
        $this->fakeExportReportRequests();

        $response = $this->actingAs($user)
            ->get(route('integrations.google-search-console.report.export', [
                'start_date' => $startsOn,
                'end_date' => $endsOn,
                'dimension' => 'query',
                'search_type' => 'web',
            ]));

        $response->assertDownload('search-console-'.$startsOn.'-'.$endsOn.'.csv')
            ->assertStreamedContent("\xEF\xBB\xBFclient_name,site_url,start_date,end_date,dimension,search_type,dimension_value,clicks,impressions,ctr_percent,average_position\n未分類,https://example.com/,$startsOn,$endsOn,query,web,\"ordinary SEO query\",25,500,5,4.2\n");

        $csv = app(GoogleSearchConsoleReportCsv::class)->build([
            'client_name' => 'Northwind',
            'site_url' => 'https://example.com/',
            'dimension' => 'query',
            'search_type' => 'web',
            'current' => [
                'starts_on' => $startsOn,
                'ends_on' => $endsOn,
                'rows' => [[
                    'label' => '=HYPERLINK("https://bad.example")',
                    'clicks' => 100.0,
                    'impressions' => 1200.0,
                    'ctr' => 0.0833,
                    'position' => 3.0,
                ]],
            ],
        ]);

        $this->assertSame("'=HYPERLINK(\"https://bad.example\")", $csv['rows'][0][6]);
        $this->assertSame('100', $csv['rows'][0][7]);
    }

    public function test_disconnect_revokes_google_access_before_deleting_the_connection(): void
    {
        $user = User::factory()->create();
        $connection = GoogleSearchConsoleConnection::factory()->for($user)->create([
            'refresh_token' => 'private-refresh-token',
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'https://oauth2.googleapis.com/revoke' => Http::response('', 200),
        ]);

        $this->actingAs($user)
            ->delete(route('integrations.google-search-console.disconnect'))
            ->assertRedirect(route('integrations.google-search-console.edit'))
            ->assertSessionHas('status', 'Google Search Console 已中斷連結。');

        $this->assertDatabaseMissing('google_search_console_connections', ['id' => $connection->id]);
        Http::assertSent(fn (ClientRequest $request): bool => $request->url() === 'https://oauth2.googleapis.com/revoke'
            && $request['token'] === 'private-refresh-token');
    }

    public function test_disconnect_keeps_the_connection_when_google_does_not_confirm_revocation(): void
    {
        $user = User::factory()->create();
        $connection = GoogleSearchConsoleConnection::factory()->for($user)->create([
            'refresh_token' => 'private-refresh-token',
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'https://oauth2.googleapis.com/revoke' => Http::response([
                'error' => 'temporarily_unavailable',
            ], 503),
        ]);

        $this->actingAs($user)
            ->delete(route('integrations.google-search-console.disconnect'))
            ->assertRedirect(route('integrations.google-search-console.edit'))
            ->assertSessionHasErrors([
                'google_search_console' => 'Google 尚未確認授權撤銷，連線仍保留，請稍後再試。',
            ]);

        $this->assertDatabaseHas('google_search_console_connections', ['id' => $connection->getKey()]);
        $this->assertSame('private-refresh-token', $connection->fresh()->refresh_token);
        Http::assertSent(fn (ClientRequest $request): bool => $request->url() === 'https://oauth2.googleapis.com/revoke'
            && $request['token'] === 'private-refresh-token');
    }

    /** @param list<array{siteUrl: string, permissionLevel: string}>|null $siteEntries */
    private function fakeConnectedSiteList(?array $siteEntries = null): void
    {
        $siteEntries ??= [
            ['siteUrl' => 'https://example.com/', 'permissionLevel' => 'siteOwner'],
            ['siteUrl' => 'sc-domain:example.com', 'permissionLevel' => 'siteFullUser'],
        ];
        Http::preventStrayRequests();
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'short-lived-access-token']),
            'https://www.googleapis.com/webmasters/v3/sites' => Http::response([
                'siteEntry' => $siteEntries,
            ]),
        ]);
    }

    private function fakeClientSiteReports(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'client-site-access-token']),
            'https://www.googleapis.com/webmasters/v3/sites' => Http::response([
                'siteEntry' => [
                    ['siteUrl' => 'https://northwind.example/', 'permissionLevel' => 'siteOwner'],
                    ['siteUrl' => 'https://contoso.example/', 'permissionLevel' => 'siteFullUser'],
                ],
            ]),
            'https://www.googleapis.com/webmasters/v3/sites/*/searchAnalytics/query' => Http::sequence()
                ->push(['rows' => [['clicks' => 10, 'impressions' => 200, 'ctr' => 0.05, 'position' => 4.0]]])
                ->push(['rows' => [['keys' => ['northwind search'], 'clicks' => 10, 'impressions' => 200, 'ctr' => 0.05, 'position' => 4.0]]])
                ->push(['rows' => [['clicks' => 30, 'impressions' => 600, 'ctr' => 0.05, 'position' => 2.0]]])
                ->push(['rows' => [['keys' => ['contoso search'], 'clicks' => 30, 'impressions' => 600, 'ctr' => 0.05, 'position' => 2.0]]])
                ->push(['rows' => [['clicks' => 30, 'impressions' => 600, 'ctr' => 0.05, 'position' => 2.0]]])
                ->push(['rows' => [['keys' => ['contoso search'], 'clicks' => 30, 'impressions' => 600, 'ctr' => 0.05, 'position' => 2.0]]]),
        ]);
    }

    private function fakeReportRequests(bool $compare, bool $loadPageAfterReport): void
    {
        Http::preventStrayRequests();
        $tokenResponses = Http::sequence()->push(['access_token' => 'report-access-token']);
        $siteResponses = Http::sequence()
            ->push(['siteEntry' => [['siteUrl' => 'https://example.com/', 'permissionLevel' => 'siteOwner']]]);

        if ($loadPageAfterReport) {
            $tokenResponses->push(['access_token' => 'page-access-token']);
            $siteResponses->push(['siteEntry' => [['siteUrl' => 'https://example.com/', 'permissionLevel' => 'siteOwner']]]);
        }

        $analyticsResponses = Http::sequence()
            ->push(['rows' => [['clicks' => 120, 'impressions' => 1000, 'ctr' => 0.12, 'position' => 4.5]]])
            ->push(['rows' => [['keys' => ['search quality example'], 'clicks' => 75, 'impressions' => 600, 'ctr' => 0.125, 'position' => 3.2]]]);

        if ($compare) {
            $analyticsResponses
                ->push(['rows' => [['clicks' => 100, 'impressions' => 800, 'ctr' => 0.125, 'position' => 5.1]]]);
        }

        Http::fake([
            'https://oauth2.googleapis.com/token' => $tokenResponses,
            'https://www.googleapis.com/webmasters/v3/sites' => $siteResponses,
            'https://www.googleapis.com/webmasters/v3/sites/*/searchAnalytics/query' => $analyticsResponses,
        ]);
    }

    private function fakeExportReportRequests(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'report-access-token']),
            'https://www.googleapis.com/webmasters/v3/sites' => Http::response([
                'siteEntry' => [['siteUrl' => 'https://example.com/', 'permissionLevel' => 'siteOwner']],
            ]),
            'https://www.googleapis.com/webmasters/v3/sites/*/searchAnalytics/query' => Http::sequence()
                ->push(['rows' => [['clicks' => 25, 'impressions' => 500, 'ctr' => 0.05, 'position' => 4.2]]])
                ->push(['rows' => [['keys' => ['ordinary SEO query'], 'clicks' => 25, 'impressions' => 500, 'ctr' => 0.05, 'position' => 4.2]]]),
        ]);
    }
}
