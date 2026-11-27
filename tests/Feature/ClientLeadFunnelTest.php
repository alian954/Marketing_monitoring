<?php

namespace Tests\Feature;

use App\Models\ClientAdPlatformDailyMetric;
use App\Models\ClientLead;
use App\Models\User;
use App\Models\WorkspaceMembership;
use App\Services\ClientLeadImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ClientLeadFunnelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-10 12:00:00');
    }

    public function test_crm_export_is_imported_with_hashed_contacts_and_updates_on_reimport(): void
    {
        $owner = User::factory()->create();
        $client = $owner->clients()->create(['name' => '建案客戶']);
        $csv = "建立日期,Email,電話,來源,活動名稱,狀態,成交金額,gclid\n"
            ."2026-11-02,Amy@Example.com,,facebook,看屋團,有效,,\n"
            ."2026-11-03,,+886 912-345-678,google / cpc,品牌字,成交,\"1,200,000\",Cj0KCQ-abc\n"
            ."2026-11-04,bob@example.com,,tiktok,短影音,垃圾名單,,\n"
            ."2026-11-05,carol@example.com,,,,,,\n";

        $this->actingAs($owner)
            ->post(route('clients.leads.store', $client), [
                'csv_file' => UploadedFile::fake()->createWithContent('crm.csv', $csv),
                'currency_code' => 'TWD',
            ])
            ->assertRedirect(route('clients.leads.index', $client))
            ->assertSessionHas('importResult', fn (array $result): bool => $result['imported'] === 4
                && $result['stages'] === ['new' => 1, 'qualified' => 1, 'won' => 1, 'lost' => 1]);

        $this->assertSame(
            ['meta_marketing', 'google_ads', 'tiktok_ads', 'other'],
            $client->leads()->orderBy('created_on')->pluck('platform')->all(),
        );
        $this->assertSame('email:'.hash('sha256', 'amy@example.com'), $client->leads()->whereDate('created_on', '2026-11-02')->value('lead_reference'));
        $this->assertSame('phone:'.hash('sha256', '0912345678'), $client->leads()->whereDate('created_on', '2026-11-03')->value('lead_reference'));
        $this->assertStringNotContainsString('example.com', (string) DB::table('client_leads')->pluck('lead_reference')->implode(','));
        $this->assertStringNotContainsString('Cj0KCQ', (string) DB::table('client_leads')->pluck('google_click_id')->implode(','));

        $this->post(route('clients.leads.store', $client), [
            'csv_file' => UploadedFile::fake()->createWithContent('crm.csv', "created date,email,status,deal amount,close date\n2026-11-02,amy@example.com,Closed Won,800000,2026-11-08\n"),
            'currency_code' => 'TWD',
        ]);

        $amy = $client->leads()->whereDate('created_on', '2026-11-02')->sole();
        $this->assertSame(ClientLead::STAGE_WON, $amy->stage);
        $this->assertSame('800000.00', $amy->deal_value);
        $this->assertSame('2026-11-08', $amy->won_on->toDateString());
        $this->assertSame(4, $client->leads()->count());
    }

    public function test_funnel_reports_cost_per_qualified_lead_and_deal_by_platform(): void
    {
        $owner = User::factory()->create();
        $client = $owner->clients()->create(['name' => '建案客戶']);
        ClientAdPlatformDailyMetric::factory()->create(['client_id' => $client->id, 'platform' => 'tiktok_ads', 'metric_on' => '2026-11-03', 'campaign_name' => 'A', 'spend' => '9000']);
        ClientLead::factory()->count(6)->create(['client_id' => $client->id, 'platform' => 'tiktok_ads', 'created_on' => '2026-11-03', 'stage' => ClientLead::STAGE_LOST]);
        ClientLead::factory()->count(2)->create(['client_id' => $client->id, 'platform' => 'tiktok_ads', 'created_on' => '2026-11-03', 'stage' => ClientLead::STAGE_QUALIFIED]);
        ClientLead::factory()->create(['client_id' => $client->id, 'platform' => 'tiktok_ads', 'created_on' => '2026-11-04', 'stage' => ClientLead::STAGE_WON, 'deal_value' => '45000', 'won_on' => '2026-11-08']);
        ClientLead::factory()->create(['client_id' => $client->id, 'platform' => 'other', 'created_on' => '2026-11-04']);
        ClientLead::factory()->create(['client_id' => $client->id, 'platform' => 'tiktok_ads', 'created_on' => '2026-10-01']);

        $response = $this->actingAs($owner)
            ->get(route('clients.leads.index', ['client' => $client, 'from' => '2026-11-01', 'to' => '2026-11-09']))
            ->assertOk()
            ->assertSee('名單漏斗（TWD）');

        $funnel = $response->viewData('funnel');
        $tiktok = $funnel['rows'][0];
        $this->assertSame('tiktok_ads', $tiktok['platform']);
        $this->assertSame(9, $tiktok['leads']);
        $this->assertSame(3, $tiktok['qualified']);
        $this->assertSame(1, $tiktok['won']);
        $this->assertSame(1000.0, $tiktok['cost_per_lead']);
        $this->assertSame(3000.0, $tiktok['cost_per_qualified_lead']);
        $this->assertSame(9000.0, $tiktok['cost_per_deal']);
        $this->assertSame(5.0, $tiktok['deal_roas']);
        $this->assertSame('other', $funnel['rows'][1]['platform']);
        $this->assertNull($funnel['rows'][1]['spend']);
        $this->assertSame(10, $funnel['total']['leads']);

        $this->get(route('clients.cross-platform', ['client' => $client, 'from' => '2026-11-01', 'to' => '2026-11-09']))
            ->assertOk()
            ->assertSee('名單品質（TWD）');
    }

    public function test_offline_conversion_export_uses_google_ads_format(): void
    {
        $owner = User::factory()->create();
        $client = $owner->clients()->create(['name' => '建案客戶']);
        ClientLead::factory()->create([
            'client_id' => $client->id, 'platform' => 'google_ads', 'created_on' => '2026-11-01', 'stage' => ClientLead::STAGE_WON,
            'deal_value' => '1200000', 'won_on' => '2026-11-05', 'google_click_id' => 'Cj0KCQ-abc',
        ]);
        ClientLead::factory()->create(['client_id' => $client->id, 'stage' => ClientLead::STAGE_WON, 'won_on' => '2026-11-05', 'google_click_id' => null]);

        $csv = $this->actingAs($owner)
            ->get(route('clients.leads.offline-conversions', ['client' => $client, 'from' => '2026-11-01', 'to' => '2026-11-09', 'conversion_name' => 'CRM 成交']))
            ->assertOk()
            ->streamedContent();

        $this->assertSame(
            "Parameters:TimeZone=+0800\n\"Google Click ID\",\"Conversion Name\",\"Conversion Time\",\"Conversion Value\",\"Conversion Currency\"\nCj0KCQ-abc,\"CRM 成交\",\"2026-11-05 12:00:00\",1200000.00,TWD\n",
            $csv,
        );

        $this->get(route('clients.leads.offline-conversions', ['client' => $client, 'from' => '2026-11-01', 'to' => '2026-11-09']))
            ->assertSessionHasErrors('conversion_name');
    }

    public function test_imports_need_an_identifier_and_access_is_scoped(): void
    {
        $owner = User::factory()->create();
        $client = $owner->clients()->create(['name' => '建案客戶']);
        $member = User::factory()->create();
        WorkspaceMembership::factory()->create([
            'owner_user_id' => $owner->id,
            'member_user_id' => $member->id,
            'role' => WorkspaceMembership::ROLE_READ_ONLY,
            'client_scope' => WorkspaceMembership::CLIENT_SCOPE_ALL,
        ]);

        $this->actingAs($owner)
            ->post(route('clients.leads.store', $client), [
                'csv_file' => UploadedFile::fake()->createWithContent('crm.csv', "建立日期,來源\n2026-11-01,facebook\n"),
                'currency_code' => 'TWD',
            ])
            ->assertSessionHasErrors('csv_file');

        $this->actingAs($member)->get(route('clients.leads.index', $client))->assertOk()->assertDontSee('匯入名單 CSV');
        $this->post(route('clients.leads.store', $client), [])->assertForbidden();
        $this->actingAs(User::factory()->create())->get(route('clients.leads.index', $client))->assertNotFound();
        $this->assertDatabaseCount('client_leads', 0);
    }

    public function test_platform_and_stage_classification(): void
    {
        $this->assertSame('google_ads', ClientLeadImporter::platformFor('google / cpc'));
        $this->assertSame('meta_marketing', ClientLeadImporter::platformFor('IG 限動'));
        $this->assertSame('line_ads', ClientLeadImporter::platformFor('LINE'));
        $this->assertSame('other', ClientLeadImporter::platformFor('online'), '"online" must not be read as LINE.');
        $this->assertSame(ClientLead::STAGE_WON, ClientLeadImporter::stageFor('', 5000.0));
        $this->assertSame(ClientLead::STAGE_LOST, ClientLeadImporter::stageFor('Unqualified', null));
        $this->assertSame(ClientLead::STAGE_QUALIFIED, ClientLeadImporter::stageFor('SQL', null));
        $this->assertSame(ClientLead::STAGE_NEW, ClientLeadImporter::stageFor('待聯絡', null));
    }
}
