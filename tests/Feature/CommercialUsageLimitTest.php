<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Monitor;
use App\Models\Organization;
use App\Models\SocialEngagementTarget;
use App\Models\User;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class CommercialUsageLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_limit_excludes_the_default_bucket_and_rejects_extra_clients(): void
    {
        $owner = User::factory()->create();
        $organization = Organization::factory()->for($owner, 'owner')->create(['client_limit' => 1]);
        Client::ensureDefaultFor($owner);

        $this->actingAs($owner)
            ->post(route('clients.store'), ['name' => '第一個客戶'])
            ->assertRedirect(route('clients.index'));

        $this->from(route('clients.index'))
            ->post(route('clients.store'), ['name' => '超額客戶'])
            ->assertSessionHasErrorsIn('createClient', ['name' => '此組織已達客戶數上限，請聯絡平台管理員調整方案。']);

        $this->assertDatabaseHas('clients', ['user_id' => $owner->id, 'name' => '第一個客戶']);
        $this->assertDatabaseMissing('clients', ['user_id' => $owner->id, 'name' => '超額客戶']);
        $this->assertSame(1, $organization->fresh()->clientUsageCount());
    }

    public function test_editor_client_creation_uses_the_organization_owner_quota(): void
    {
        $owner = User::factory()->create();
        Organization::factory()->for($owner, 'owner')->create(['client_limit' => 1]);
        $owner->clients()->create(['name' => '已佔用名額']);
        $editor = User::factory()->create();
        WorkspaceMembership::factory()->create([
            'owner_user_id' => $owner->id,
            'member_user_id' => $editor->id,
            'role' => WorkspaceMembership::ROLE_EDITOR,
            'client_scope' => WorkspaceMembership::CLIENT_SCOPE_ALL,
            'accepted_at' => now(),
        ]);

        $this->actingAs($editor)
            ->from(route('clients.index'))
            ->post(route('clients.store'), ['name' => '編輯者超額客戶'])
            ->assertSessionHasErrorsIn('createClient', [
                'name' => '此組織已達客戶數上限，請聯絡平台管理員調整方案。',
            ]);

        $this->assertDatabaseMissing('clients', ['user_id' => $owner->id, 'name' => '編輯者超額客戶']);
    }

    public function test_one_organization_does_not_consume_another_organizations_client_quota(): void
    {
        $firstOwner = User::factory()->create();
        Organization::factory()->for($firstOwner, 'owner')->create(['client_limit' => 1]);
        $firstOwner->clients()->create(['name' => '第一組織客戶']);

        $secondOwner = User::factory()->create();
        $secondOrganization = Organization::factory()->for($secondOwner, 'owner')->create(['client_limit' => 1]);

        $this->actingAs($secondOwner)
            ->post(route('clients.store'), ['name' => '第二組織客戶'])
            ->assertRedirect(route('clients.index'));

        $this->assertDatabaseHas('clients', ['user_id' => $secondOwner->id, 'name' => '第二組織客戶']);
        $this->assertSame(1, $secondOrganization->fresh()->clientUsageCount());
    }

    public function test_monitor_limit_applies_to_manual_creation_and_csv_import(): void
    {
        Config::set('monitoring.transport', 'fixture');
        $owner = User::factory()->create();
        $organization = Organization::factory()->for($owner, 'owner')->create(['monitor_limit' => 1]);
        $client = Client::ensureDefaultFor($owner);
        $this->actingAs($owner);
        Monitor::factory()->create([
            'client_id' => $client->id,
            'url' => 'https://example.com/already-monitored',
            'url_hash' => Monitor::fingerprintUrl('https://example.com/already-monitored'),
        ]);

        $this->from(route('monitors.create'))
            ->post(route('monitors.store'), [
                'name' => '第二個網站',
                'url' => 'https://fixture.test/second',
                'label' => '',
            ])
            ->assertSessionHasErrors(['name' => '此組織已達監測項目上限，請聯絡平台管理員調整方案。']);

        $csv = "name,url,label\n".'CSV 監測,https://fixture.test/csv,Campaign'."\n";
        $preview = $this->post(route('monitors.import.preview'), [
            'csv_file' => UploadedFile::fake()->createWithContent('monitors.csv', $csv),
        ])->assertOk();

        $this->post(route('monitors.import.commit'), [
            'preview_token' => $preview->viewData('previewToken'),
        ])
            ->assertRedirect(route('monitors.index'))
            ->assertSessionHas('status', '已匯入 0 個監測項目；略過 0 筆無效或重複資料。因達監測項目上限，另略過 1 筆。');

        $this->assertDatabaseCount('monitors', 1);
        $this->assertSame(1, $organization->fresh()->monitorUsageCount());
    }

    public function test_import_preview_reports_the_rows_the_monitor_limit_will_leave_out(): void
    {
        Config::set('monitoring.transport', 'fixture');
        $owner = User::factory()->create();
        Organization::factory()->for($owner, 'owner')->create(['monitor_limit' => 1]);
        $csv = "name,url,label\n第一筆,https://fixture.test/ok,qa\n第二筆,https://fixture.test/404,qa\n";

        $this->actingAs($owner)
            ->post(route('monitors.import.preview'), [
                'csv_file' => UploadedFile::fake()->createWithContent('monitors.csv', $csv),
            ])
            ->assertOk()
            ->assertViewHas('validCount', 2)
            ->assertViewHas('importableCount', 1)
            ->assertSee('確認並匯入 1 個監測項目')
            ->assertSee('另有 1 列可匯入資料會依列號順序略過');
    }

    public function test_campaign_limit_allows_editing_an_existing_target_but_rejects_a_new_target(): void
    {
        $owner = User::factory()->create();
        $organization = Organization::factory()->for($owner, 'owner')->create(['campaign_limit' => 1]);
        $client = Client::ensureDefaultFor($owner);
        $this->actingAs($owner);
        $firstMonitor = $this->createSocialMonitor($client, 'FACEBOOK-LIMIT-1', 'First launch');
        $this->createSocialMonitor($client, 'FACEBOOK-LIMIT-2', 'Second launch');

        $payload = [
            'client_id' => $client->id,
            'tag' => 'First launch',
            'social_platform' => 'facebook',
            'target_interactions' => 100,
        ];

        $this->actingAs($owner)
            ->post(route('social-engagement-targets.store'), $payload)
            ->assertRedirect();

        $this->post(route('social-engagement-targets.store'), [
            ...$payload,
            'tag' => 'Second launch',
        ])->assertSessionHasErrorsIn('socialEngagementTarget', [
            'tag' => '此組織已達活動數上限，請聯絡平台管理員調整方案。',
        ]);

        $this->post(route('social-engagement-targets.store'), [
            ...$payload,
            'target_interactions' => 250,
        ])->assertRedirect();

        $target = SocialEngagementTarget::query()->sole();
        $this->assertSame(250, $target->target_interactions);
        $this->assertSame($firstMonitor->client_id, $target->client_id);
        $this->assertSame(1, $organization->fresh()->campaignUsageCount());
    }

    public function test_plan_downgrade_preserves_existing_resources_and_enforces_lower_limits(): void
    {
        $owner = User::factory()->create();
        $organization = Organization::factory()->for($owner, 'owner')->create([
            'plan_name' => 'Agency Plus',
            'seat_limit' => 4,
            'client_limit' => 5,
            'monitor_limit' => 5,
            'campaign_limit' => 5,
        ]);
        $client = Client::ensureDefaultFor($owner);
        $owner->clients()->create(['name' => 'Existing client A']);
        $owner->clients()->create(['name' => 'Existing client B']);
        $this->actingAs($owner);
        $this->createSocialMonitor($client, 'DOWNGRADE-1', 'Launch one');
        $this->createSocialMonitor($client, 'DOWNGRADE-2', 'Launch two');
        $thirdMonitor = $this->createSocialMonitor($client, 'DOWNGRADE-3', 'Launch three');

        $campaignPayload = [
            'client_id' => $client->getKey(),
            'social_platform' => 'facebook',
            'target_interactions' => 100,
        ];
        $this->post(route('social-engagement-targets.store'), [
            ...$campaignPayload,
            'tag' => 'Launch one',
        ])->assertRedirect();
        $this->post(route('social-engagement-targets.store'), [
            ...$campaignPayload,
            'tag' => 'Launch two',
        ])->assertRedirect();

        $member = User::factory()->create();
        WorkspaceMembership::factory()->create([
            'owner_user_id' => $owner->getKey(),
            'member_user_id' => $member->getKey(),
            'accepted_at' => now(),
        ]);
        $platformAdmin = User::factory()->create();
        $platformAdmin->forceFill(['is_platform_admin' => true])->save();

        $this->actingAs($platformAdmin, 'platform')
            ->patch(route('platform.organizations.update', $organization), [
                'name' => $organization->name,
                'subscription_ends_on' => null,
                'seat_limit' => 1,
                'plan_name' => 'Agency Starter',
                'client_limit' => 1,
                'monitor_limit' => 2,
                'campaign_limit' => 1,
                'is_enabled' => true,
            ])
            ->assertRedirect(route('platform.index'));

        $organization->refresh();
        $this->assertSame('Agency Starter', $organization->plan_name);
        $this->assertSame(2, $organization->clientUsageCount());
        $this->assertSame(3, $organization->monitorUsageCount());
        $this->assertSame(2, $organization->campaignUsageCount());
        $this->assertSame(2, $organization->occupiedSeats());

        $this->actingAs($owner)
            ->from(route('clients.index'))
            ->post(route('clients.store'), ['name' => 'Additional client'])
            ->assertSessionHasErrorsIn('createClient', [
                'name' => '此組織已達客戶數上限，請聯絡平台管理員調整方案。',
            ]);

        $this->from(route('monitors.create'))
            ->post(route('monitors.store'), [
                'name' => 'Additional monitor',
                'url' => 'https://downgrade-monitor.example/landing',
                'label' => '',
            ])
            ->assertSessionHasErrors(['name' => '此組織已達監測項目上限，請聯絡平台管理員調整方案。']);

        $this->post(route('social-engagement-targets.store'), [
            ...$campaignPayload,
            'tag' => 'Launch three',
        ])->assertSessionHasErrorsIn('socialEngagementTarget', [
            'tag' => '此組織已達活動數上限，請聯絡平台管理員調整方案。',
        ]);

        $this->post(route('team.invitations.store'), ['email' => 'new-member@example.test'])
            ->assertSessionHasErrors('email');

        $this->post(route('social-engagement-targets.store'), [
            ...$campaignPayload,
            'tag' => 'Launch two',
            'target_interactions' => 350,
        ])->assertRedirect();

        $this->assertSame(2, $organization->fresh()->clientUsageCount());
        $this->assertSame(3, $organization->fresh()->monitorUsageCount());
        $this->assertSame(2, $organization->fresh()->campaignUsageCount());
        $this->assertSame(350, SocialEngagementTarget::query()->where('tag', 'Launch two')->sole()->target_interactions);
        $this->assertDatabaseHas('monitors', ['id' => $thirdMonitor->getKey()]);
        $this->assertDatabaseHas('clients', ['user_id' => $owner->getKey(), 'name' => 'Existing client A']);
    }

    private function createSocialMonitor(Client $client, string $postId, string $tag): Monitor
    {
        $url = 'https://www.facebook.com/share/p/'.$postId.'/';

        return Monitor::factory()->create([
            'client_id' => $client->id,
            'name' => $tag,
            'url' => $url,
            'url_hash' => Monitor::fingerprintUrl($url),
            'label' => $tag,
        ]);
    }
}
