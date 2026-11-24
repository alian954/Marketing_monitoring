<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientReportShare;
use App\Models\SocialEngagementTarget;
use App\Models\User;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ClientReportTemplateTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_save_a_template_without_creating_a_share(): void
    {
        $owner = User::factory()->create();
        $client = Client::factory()->for($owner)->create();
        $target = $this->createTarget($client, '月報活動');
        $reportSections = [
            ClientReportShare::SECTION_PUBLIC_ENGAGEMENT,
            ClientReportShare::SECTION_GOOGLE_ANALYTICS,
            ClientReportShare::SECTION_ESTIMATED_PROFITABILITY,
        ];

        $response = $this->actingAs($owner)
            ->post(route('clients.report-shares.templates.store', $client), [
                'template_name' => '  月報樣板  ',
                'report_brand_name' => '  好感行銷  ',
                'target_ids' => [$target->id],
                'report_sections' => $reportSections,
                'compare_previous_period' => '1',
                'from' => '2020-01-01',
                'to' => '2020-01-31',
                'expires_in_days' => '7',
            ]);

        $template = $client->reportTemplates()->firstOrFail();

        $response->assertRedirect(route('clients.report-shares.index', [
            'client' => $client,
            'template_id' => $template->id,
        ]));
        $this->assertSame('月報樣板', $template->name);
        $this->assertSame('好感行銷', $template->report_brand_name);
        $this->assertSame([$target->id], $template->campaign_target_ids);
        $this->assertSame($reportSections, $template->selectedReportSections());
        $this->assertTrue($template->compare_previous_period);
        $this->assertDatabaseCount('client_report_shares', 0);
    }

    public function test_applying_a_template_preserves_current_dates_and_expiration_defaults(): void
    {
        $this->travelTo(Carbon::parse('2026-11-24 12:00:00', 'UTC'));
        $owner = User::factory()->create();
        $client = Client::factory()->for($owner)->create();
        $target = $this->createTarget($client, '品牌活動');
        $template = $client->reportTemplates()->create([
            'name' => '月報樣板',
            'report_brand_name' => '好感行銷',
            'campaign_target_ids' => [$target->id],
            'report_sections' => [
                ClientReportShare::SECTION_GOOGLE_ANALYTICS,
                ClientReportShare::SECTION_ESTIMATED_PROFITABILITY,
            ],
            'compare_previous_period' => true,
        ]);

        $this->actingAs($owner)
            ->get(route('clients.report-shares.index', ['client' => $client, 'template_id' => $template->id]))
            ->assertOk()
            ->assertSee('月報樣板')
            ->assertSee('value="2026-10-25"', false)
            ->assertSee('value="2026-11-23"', false)
            ->assertSee('<option value="30" selected>30 天</option>', false)
            ->assertSee('value="好感行銷"', false)
            ->assertSee('name="target_ids[]" value="'.$target->id.'" checked', false)
            ->assertSee('name="report_sections[]" value="google_analytics" checked', false)
            ->assertSee('name="report_sections[]" value="estimated_profitability" checked', false)
            ->assertSee('name="compare_previous_period"', false)
            ->assertSee('value="1" checked', false);
    }

    public function test_template_can_only_be_applied_to_its_own_client(): void
    {
        $owner = User::factory()->create();
        $firstClient = Client::factory()->for($owner)->create();
        $secondClient = Client::factory()->for($owner)->create();
        $secondTarget = $this->createTarget($secondClient, '第二客戶活動');
        $template = $secondClient->reportTemplates()->create([
            'name' => '第二客戶樣板',
            'campaign_target_ids' => [$secondTarget->id],
            'report_sections' => [ClientReportShare::SECTION_PUBLIC_ENGAGEMENT],
        ]);

        $this->actingAs($owner)
            ->get(route('clients.report-shares.index', $firstClient))
            ->assertOk()
            ->assertDontSee('第二客戶樣板');

        $this->get(route('clients.report-shares.index', ['client' => $firstClient, 'template_id' => $template->id]))
            ->assertNotFound();
    }

    public function test_template_rejects_an_activity_from_another_client(): void
    {
        $owner = User::factory()->create();
        $client = Client::factory()->for($owner)->create();
        $otherClient = Client::factory()->for($owner)->create();
        $otherTarget = $this->createTarget($otherClient, '其他客戶活動');

        $this->actingAs($owner)
            ->from(route('clients.report-shares.index', $client))
            ->post(route('clients.report-shares.templates.store', $client), [
                'template_name' => '跨客戶樣板',
                'target_ids' => [$otherTarget->id],
                'report_sections' => [ClientReportShare::SECTION_PUBLIC_ENGAGEMENT],
            ])
            ->assertRedirect(route('clients.report-shares.index', $client))
            ->assertSessionHasErrors(['target_ids.0' => '所選活動不存在或不屬於此客戶。']);

        $this->assertDatabaseCount('client_report_templates', 0);
    }

    public function test_template_rejects_comparison_without_marketing_sections(): void
    {
        $owner = User::factory()->create();
        $client = Client::factory()->for($owner)->create();
        $target = $this->createTarget($client, '互動活動');

        $this->actingAs($owner)
            ->from(route('clients.report-shares.index', $client))
            ->post(route('clients.report-shares.templates.store', $client), [
                'template_name' => '只看互動樣板',
                'target_ids' => [$target->id],
                'report_sections' => [ClientReportShare::SECTION_PUBLIC_ENGAGEMENT],
                'compare_previous_period' => '1',
            ])
            ->assertRedirect(route('clients.report-shares.index', $client))
            ->assertSessionHasErrors(['compare_previous_period' => '前期比較需至少選擇一個 GA4 或廣告數據區塊。']);

        $this->assertDatabaseCount('client_report_templates', 0);
    }

    public function test_template_requires_a_name_and_rejects_unknown_report_sections(): void
    {
        $owner = User::factory()->create();
        $client = Client::factory()->for($owner)->create();
        $target = $this->createTarget($client, '品牌活動');

        $this->actingAs($owner)
            ->from(route('clients.report-shares.index', $client))
            ->post(route('clients.report-shares.templates.store', $client), [
                'target_ids' => [$target->id],
                'report_sections' => ['internal_connection_data'],
            ])
            ->assertRedirect(route('clients.report-shares.index', $client))
            ->assertSessionHasErrors([
                'template_name' => '請輸入樣板名稱。',
                'report_sections.0' => '所選報表區塊無效。',
            ]);

        $this->assertDatabaseCount('client_report_templates', 0);
    }

    public function test_template_names_are_escaped_in_the_management_page(): void
    {
        $owner = User::factory()->create();
        $client = Client::factory()->for($owner)->create();
        $target = $this->createTarget($client, '品牌活動');
        $template = $client->reportTemplates()->create([
            'name' => '<script>alert(1)</script>',
            'campaign_target_ids' => [$target->id],
            'report_sections' => [ClientReportShare::SECTION_PUBLIC_ENGAGEMENT],
        ]);

        $this->actingAs($owner)
            ->get(route('clients.report-shares.index', $client))
            ->assertOk()
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_template_deletion_is_scoped_to_the_requested_client(): void
    {
        $owner = User::factory()->create();
        $firstClient = Client::factory()->for($owner)->create();
        $secondClient = Client::factory()->for($owner)->create();
        $template = $secondClient->reportTemplates()->create([
            'name' => '保留樣板',
            'campaign_target_ids' => [],
            'report_sections' => [ClientReportShare::SECTION_PUBLIC_ENGAGEMENT],
        ]);

        $this->actingAs($owner)
            ->delete(route('clients.report-shares.templates.destroy', [$firstClient, $template]))
            ->assertNotFound();

        $this->assertModelExists($template);
    }

    public function test_owner_can_delete_a_template(): void
    {
        $owner = User::factory()->create();
        $client = Client::factory()->for($owner)->create();
        $template = $client->reportTemplates()->create([
            'name' => '要刪除的樣板',
            'campaign_target_ids' => [],
            'report_sections' => [ClientReportShare::SECTION_PUBLIC_ENGAGEMENT],
        ]);

        $this->actingAs($owner)
            ->delete(route('clients.report-shares.templates.destroy', [$client, $template]))
            ->assertRedirect(route('clients.report-shares.index', $client))
            ->assertSessionHas('status', '報表樣板已刪除。');

        $this->assertDatabaseMissing('client_report_templates', ['id' => $template->id]);
    }

    public function test_unauthenticated_users_cannot_save_templates(): void
    {
        $client = Client::factory()->create();
        $target = $this->createTarget($client, '未登入活動');

        $this->post(route('clients.report-shares.templates.store', $client), [
            'template_name' => '未登入樣板',
            'target_ids' => [$target->id],
        ])->assertRedirect(route('login'));

        $this->assertDatabaseCount('client_report_templates', 0);
    }

    public function test_read_only_members_cannot_save_or_delete_templates(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $client = Client::factory()->for($owner)->create();
        $target = $this->createTarget($client, '唯讀可見活動');
        $template = $client->reportTemplates()->create([
            'name' => '唯讀不可刪除',
            'campaign_target_ids' => [$target->id],
            'report_sections' => [ClientReportShare::SECTION_PUBLIC_ENGAGEMENT],
        ]);
        WorkspaceMembership::factory()->create([
            'owner_user_id' => $owner->id,
            'member_user_id' => $member->id,
            'role' => WorkspaceMembership::ROLE_READ_ONLY,
            'client_scope' => WorkspaceMembership::CLIENT_SCOPE_ALL,
        ]);

        $this->actingAs($member)
            ->get(route('clients.report-shares.index', $client))
            ->assertOk()
            ->assertSee('套用')
            ->assertDontSee('儲存樣板')
            ->assertDontSee('建立連結')
            ->assertDontSee('刪除唯讀不可刪除樣板');

        $this->actingAs($member)
            ->post(route('clients.report-shares.templates.store', $client), [
                'template_name' => '不應建立',
                'target_ids' => [$target->id],
            ])
            ->assertForbidden();

        $this->delete(route('clients.report-shares.templates.destroy', [$client, $template]))
            ->assertForbidden();

        $this->assertDatabaseCount('client_report_templates', 1);
    }

    private function createTarget(Client $client, string $tag): SocialEngagementTarget
    {
        return SocialEngagementTarget::factory()->create([
            'client_id' => $client->id,
            'tag' => $tag,
            'social_platform' => 'facebook',
        ]);
    }
}
