<?php

namespace Tests\Feature;

use App\Models\CheckRun;
use App\Models\Client;
use App\Models\ClientReportShare;
use App\Models\ClientReportSnapshot;
use App\Models\Monitor;
use App\Models\SocialEngagementTarget;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ClientReportSnapshotControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_create_encrypted_snapshot_and_download_it(): void
    {
        $setup = $this->createReportShare();

        $this->actingAs($setup['owner'])
            ->post(route('clients.report-shares.snapshots.store', [$setup['client'], $setup['share']]))
            ->assertRedirect(route('clients.report-shares.index', $setup['client']))
            ->assertSessionHas('status', '已建立報表快照。');

        $snapshot = ClientReportSnapshot::query()->sole();
        $storedCiphertext = DB::table('client_report_snapshots')->where('id', $snapshot->id)->value('csv_content');

        $this->assertSame([ClientReportShare::SECTION_PUBLIC_ENGAGEMENT], $snapshot->report_sections);
        $this->assertSame(now()->toDateString(), $snapshot->report_starts_on->toDateString());
        $this->assertSame(now()->toDateString(), $snapshot->report_ends_on->toDateString());
        $this->assertSame($setup['owner']->id, $snapshot->created_by_user_id);
        $this->assertIsString($storedCiphertext);
        $this->assertStringStartsNotWith("\xEF\xBB\xBF", $storedCiphertext);
        $this->assertSame(hash('sha256', $snapshot->csv_content), $snapshot->csv_sha256);

        $download = $this->get(route('clients.report-shares.snapshots.export', [$setup['client'], $setup['share'], $snapshot]))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->assertStringContainsString('private', (string) $download->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', (string) $download->headers->get('Cache-Control'));
        $this->assertSame($snapshot->csv_content, $download->streamedContent());

        $managementPage = $this->get(route('clients.report-shares.index', $setup['client']))
            ->assertOk()
            ->assertSee('建立快照')
            ->assertSee('1 份')
            ->assertSee(route('clients.report-shares.snapshots.export', [$setup['client'], $setup['share'], $snapshot]), false);
        $this->assertArrayNotHasKey('csv_content', $managementPage->viewData('shares')->sole()->snapshots->sole()->getAttributes());

        $publicPage = $this->get(route('client-report-shares.public', ['token' => $setup['token']]))
            ->assertOk()
            ->assertSee('封存報表')
            ->assertSee(route('client-report-shares.public.snapshots.export', [
                'token' => $setup['token'],
                'snapshot' => $snapshot->id,
            ]), false);
        $this->assertArrayNotHasKey('csv_content', $publicPage->viewData('share')->snapshots->sole()->getAttributes());
    }

    public function test_snapshot_preserves_the_opted_in_profitability_section(): void
    {
        $setup = $this->createReportShare();
        $setup['share']->forceFill([
            'report_sections' => [ClientReportShare::SECTION_ESTIMATED_PROFITABILITY],
        ])->save();

        $this->actingAs($setup['owner'])
            ->post(route('clients.report-shares.snapshots.store', [$setup['client'], $setup['share']]))
            ->assertRedirect(route('clients.report-shares.index', $setup['client']));

        $snapshot = ClientReportSnapshot::query()->sole();

        $this->assertSame([ClientReportShare::SECTION_ESTIMATED_PROFITABILITY], $snapshot->report_sections);
        $this->assertStringContainsString('估算毛利', $snapshot->csv_content);
        $this->assertStringContainsString('獲利估算狀態', $snapshot->csv_content);
        $this->assertSame(hash('sha256', $snapshot->csv_content), $snapshot->csv_sha256);
    }

    public function test_archived_csv_is_unchanged_when_live_public_engagement_changes(): void
    {
        $setup = $this->createReportShare();

        $this->actingAs($setup['owner'])
            ->post(route('clients.report-shares.snapshots.store', [$setup['client'], $setup['share']]))
            ->assertRedirect();

        $snapshot = ClientReportSnapshot::query()->sole();
        $archivedCsv = $snapshot->csv_content;
        $run = CheckRun::query()->where('monitor_id', $setup['monitor']->id)->sole();
        $run->forceFill([
            'response_public_engagement_metrics' => [
                'views' => 1000,
                'likes' => 50,
                'comments' => 6,
                'shares' => 4,
            ],
        ])->save();

        $liveCsv = $this->get(route('client-report-shares.public.export', ['token' => $setup['token']]))
            ->assertOk()
            ->streamedContent();
        $publicSnapshot = $this->get(route('client-report-shares.public.snapshots.export', [
            'token' => $setup['token'],
            'snapshot' => $snapshot->id,
        ]))->assertOk();

        $this->assertSame($archivedCsv, $publicSnapshot->streamedContent());
        $this->assertStringContainsString('13', $archivedCsv);
        $this->assertStringContainsString('60', $liveCsv);
        $this->assertStringNotContainsString('60', $archivedCsv);
        $this->assertSame('noindex, nofollow', $publicSnapshot->headers->get('X-Robots-Tag'));
        $this->assertSame('no-referrer', $publicSnapshot->headers->get('Referrer-Policy'));
    }

    public function test_public_snapshot_download_requires_the_active_share_and_own_snapshot(): void
    {
        $setup = $this->createReportShare();
        $snapshot = $setup['share']->snapshots()->create([
            'created_by_user_id' => $setup['owner']->id,
            'report_sections' => [ClientReportShare::SECTION_PUBLIC_ENGAGEMENT],
            'report_starts_on' => now()->toDateString(),
            'report_ends_on' => now()->toDateString(),
            'csv_content' => "\xEF\xBB\xBFarchive\n",
            'csv_sha256' => hash('sha256', "\xEF\xBB\xBFarchive\n"),
        ]);

        $otherSetup = $this->createReportShare();
        $otherSnapshot = $otherSetup['share']->snapshots()->create([
            'created_by_user_id' => $otherSetup['owner']->id,
            'report_sections' => [ClientReportShare::SECTION_PUBLIC_ENGAGEMENT],
            'csv_content' => "\xEF\xBB\xBFother\n",
            'csv_sha256' => hash('sha256', "\xEF\xBB\xBFother\n"),
        ]);

        $this->get(route('client-report-shares.public.snapshots.export', [
            'token' => $setup['token'],
            'snapshot' => $otherSnapshot->id,
        ]))->assertNotFound();

        $setup['share']->forceFill(['revoked_at' => now()])->save();

        $this->get(route('client-report-shares.public.snapshots.export', [
            'token' => $setup['token'],
            'snapshot' => $snapshot->id,
        ]))->assertNotFound();

        $this->actingAs($setup['owner'])
            ->get(route('clients.report-shares.snapshots.export', [$setup['client'], $setup['share'], $snapshot]))
            ->assertOk();

        $expiredSetup = $this->createReportShare();
        $expiredSnapshot = $expiredSetup['share']->snapshots()->create([
            'report_sections' => [ClientReportShare::SECTION_PUBLIC_ENGAGEMENT],
            'csv_content' => "\xEF\xBB\xBFexpired\n",
            'csv_sha256' => hash('sha256', "\xEF\xBB\xBFexpired\n"),
        ]);
        $expiredSetup['share']->forceFill(['expires_at' => now()->subSecond()])->save();

        $this->get(route('client-report-shares.public.snapshots.export', [
            'token' => $expiredSetup['token'],
            'snapshot' => $expiredSnapshot->id,
        ]))->assertNotFound();
    }

    public function test_internal_snapshot_routes_require_authentication_and_stay_client_scoped(): void
    {
        $setup = $this->createReportShare();
        $snapshot = $setup['share']->snapshots()->create([
            'created_by_user_id' => $setup['owner']->id,
            'report_sections' => [ClientReportShare::SECTION_PUBLIC_ENGAGEMENT],
            'csv_content' => "\xEF\xBB\xBFarchive\n",
            'csv_sha256' => hash('sha256', "\xEF\xBB\xBFarchive\n"),
        ]);

        auth()->logout();

        $this->get(route('clients.report-shares.snapshots.export', [$setup['client'], $setup['share'], $snapshot]))
            ->assertRedirect(route('login'));

        $otherOwner = User::factory()->create();

        $this->actingAs($otherOwner)
            ->get(route('clients.report-shares.snapshots.export', [$setup['client']->id, $setup['share']->id, $snapshot->id]))
            ->assertNotFound();
    }

    public function test_snapshot_download_rejects_a_csv_that_fails_its_integrity_hash(): void
    {
        $setup = $this->createReportShare();
        $snapshot = $setup['share']->snapshots()->create([
            'created_by_user_id' => $setup['owner']->id,
            'report_sections' => [ClientReportShare::SECTION_PUBLIC_ENGAGEMENT],
            'csv_content' => "\xEF\xBB\xBFarchive\n",
            'csv_sha256' => str_repeat('0', 64),
        ]);

        $this->actingAs($setup['owner'])
            ->get(route('clients.report-shares.snapshots.export', [$setup['client'], $setup['share'], $snapshot]))
            ->assertNotFound();
    }

    /**
     * @return array{owner: User, client: Client, target: SocialEngagementTarget, monitor: Monitor, share: ClientReportShare, token: string}
     */
    private function createReportShare(): array
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);
        $client = Client::factory()->for($owner)->create();
        $target = SocialEngagementTarget::factory()->create([
            'client_id' => $client->id,
            'tag' => 'Snapshot Campaign',
            'social_platform' => 'facebook',
            'starts_on' => now()->toDateString(),
            'ends_on' => now()->toDateString(),
        ]);
        $url = 'https://www.facebook.com/posts/client-report-snapshot';
        $monitor = Monitor::factory()->create([
            'client_id' => $client->id,
            'name' => 'Snapshot post',
            'url' => $url,
            'url_hash' => Monitor::fingerprintUrl($url),
            'label' => $target->tag,
        ]);
        CheckRun::factory()->for($monitor)->create([
            'config_version' => $monitor->config_version,
            'normal_status' => 200,
            'scheduled_at' => now()->subMinute(),
            'finished_at' => now(),
            'response_published_at' => now(),
            'response_public_engagement_metrics' => [
                'views' => 1000,
                'likes' => 10,
                'comments' => 2,
                'shares' => 1,
            ],
        ]);
        $token = bin2hex(random_bytes(32));
        $share = $client->reportShares()->create([
            'name' => 'Snapshot share',
            'token_hash' => hash('sha256', $token),
            'campaign_target_ids' => [$target->id],
            'report_sections' => [ClientReportShare::SECTION_PUBLIC_ENGAGEMENT],
            'report_starts_on' => now()->toDateString(),
            'report_ends_on' => now()->toDateString(),
            'compare_previous_period' => false,
            'expires_at' => now()->addDays(30),
        ]);

        return compact('owner', 'client', 'target', 'monitor', 'share', 'token');
    }
}
