<?php

namespace Tests\Feature;

use App\Mail\ClientReportDelivered;
use App\Models\Client;
use App\Models\ClientReportSchedule;
use App\Models\ClientReportShare;
use App\Models\ClientReportTemplate;
use App\Models\Organization;
use App\Models\SocialEngagementTarget;
use App\Models\User;
use App\Models\WorkspaceMembership;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ClientReportScheduleTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_create_a_weekly_schedule_from_a_template(): void
    {
        $this->travelTo(Carbon::parse('2026-11-25 10:00:00', 'Asia/Taipei'));
        ['owner' => $owner, 'client' => $client, 'template' => $template] = $this->createClientWithTemplate();

        $this->actingAs($owner)
            ->post(route('clients.report-schedules.store', $client), [
                'template_id' => $template->id,
                'frequency' => ClientReportSchedule::FREQUENCY_WEEKLY,
                'recipients' => "client@example.com, Client@example.com\nboss@example.com",
            ])
            ->assertRedirect(route('clients.report-shares.index', $client));

        $schedule = $client->reportSchedules()->firstOrFail();

        $this->assertSame(['client@example.com', 'boss@example.com'], $schedule->recipientList());
        $this->assertSame('2026-11-30', $schedule->next_run_on->toDateString());
        $this->assertStringNotContainsString(
            'client@example.com',
            (string) DB::table('client_report_schedules')->value('recipients'),
        );

        $this->actingAs($owner)
            ->get(route('clients.report-shares.index', $client))
            ->assertOk()
            ->assertSee('自動寄送')
            ->assertSee('client@example.com')
            ->assertSee('2026/11/30');
    }

    public function test_schedule_rejects_invalid_recipients_and_templates_of_other_clients(): void
    {
        ['owner' => $owner, 'client' => $client, 'template' => $template] = $this->createClientWithTemplate();
        $otherClient = Client::factory()->for($owner)->create();
        $otherTemplate = ClientReportTemplate::factory()->create(['client_id' => $otherClient->id]);

        $this->actingAs($owner)
            ->post(route('clients.report-schedules.store', $client), [
                'template_id' => $template->id,
                'frequency' => ClientReportSchedule::FREQUENCY_MONTHLY,
                'recipients' => 'not-an-email',
            ])
            ->assertSessionHasErrors(['recipients.0'], errorBag: 'reportSchedule');

        $this->actingAs($owner)
            ->post(route('clients.report-schedules.store', $client), [
                'template_id' => $otherTemplate->id,
                'frequency' => ClientReportSchedule::FREQUENCY_MONTHLY,
                'recipients' => 'client@example.com',
            ])
            ->assertSessionHasErrors(['template_id'], errorBag: 'reportSchedule');

        $this->actingAs($owner)
            ->post(route('clients.report-schedules.store', $client), [
                'template_id' => $template->id,
                'frequency' => 'daily',
                'recipients' => implode(',', array_map(static fn (int $index): string => "user{$index}@example.com", range(1, 11))),
            ])
            ->assertSessionHasErrors(['frequency', 'recipients'], errorBag: 'reportSchedule');

        $this->assertDatabaseCount('client_report_schedules', 0);
    }

    public function test_read_only_members_cannot_manage_schedules(): void
    {
        ['owner' => $owner, 'client' => $client, 'template' => $template] = $this->createClientWithTemplate();
        $schedule = ClientReportSchedule::factory()->create(['client_report_template_id' => $template->id]);
        $member = User::factory()->create();
        WorkspaceMembership::factory()->create([
            'owner_user_id' => $owner->id,
            'member_user_id' => $member->id,
            'role' => WorkspaceMembership::ROLE_READ_ONLY,
            'client_scope' => WorkspaceMembership::CLIENT_SCOPE_ALL,
        ]);

        $this->actingAs($member)
            ->get(route('clients.report-shares.index', $client))
            ->assertOk()
            ->assertDontSee('建立自動寄送');

        $this->actingAs($member)
            ->post(route('clients.report-schedules.store', $client), [
                'template_id' => $template->id,
                'frequency' => ClientReportSchedule::FREQUENCY_WEEKLY,
                'recipients' => 'client@example.com',
            ])
            ->assertForbidden();
        $this->patch(route('clients.report-schedules.update', [$client, $schedule]), ['paused' => '1'])
            ->assertForbidden();
        $this->delete(route('clients.report-schedules.destroy', [$client, $schedule]))
            ->assertForbidden();

        $this->assertDatabaseCount('client_report_schedules', 1);
    }

    public function test_owner_can_pause_resume_and_delete_a_schedule_but_not_through_another_client(): void
    {
        $this->travelTo(Carbon::parse('2026-11-25 10:00:00', 'Asia/Taipei'));
        ['owner' => $owner, 'client' => $client, 'template' => $template] = $this->createClientWithTemplate();
        $schedule = ClientReportSchedule::factory()->create([
            'client_report_template_id' => $template->id,
            'next_run_on' => '2026-11-02',
            'consecutive_failures' => 2,
        ]);
        $otherClient = Client::factory()->for($owner)->create();

        $this->actingAs($owner)
            ->patch(route('clients.report-schedules.update', [$otherClient, $schedule]), ['paused' => '1'])
            ->assertNotFound();

        $this->patch(route('clients.report-schedules.update', [$client, $schedule]), ['paused' => '1'])
            ->assertRedirect(route('clients.report-shares.index', $client));
        $this->assertTrue($schedule->refresh()->isPaused());

        $this->patch(route('clients.report-schedules.update', [$client, $schedule]), ['paused' => '0']);
        $schedule->refresh();
        $this->assertFalse($schedule->isPaused());
        $this->assertSame('2026-11-30', $schedule->next_run_on->toDateString());
        $this->assertSame(0, $schedule->consecutive_failures);

        $this->delete(route('clients.report-schedules.destroy', [$client, $schedule]))
            ->assertRedirect(route('clients.report-shares.index', $client));
        $this->assertDatabaseCount('client_report_schedules', 0);
    }

    public function test_command_delivers_the_previous_week_report_with_link_and_csv(): void
    {
        Mail::fake();
        $this->travelTo(Carbon::parse('2026-11-30 08:41:00', 'Asia/Taipei'));
        ['client' => $client, 'template' => $template, 'target' => $target] = $this->createClientWithTemplate('好感行銷');
        $schedule = ClientReportSchedule::factory()->create([
            'client_report_template_id' => $template->id,
            'recipients' => "client@example.com\nboss@example.com",
            'next_run_on' => '2026-11-30',
        ]);

        $this->artisan('client-reports:send-scheduled')->assertExitCode(0);

        $share = ClientReportShare::query()->sole();
        $this->assertSame($client->id, $share->client_id);
        $this->assertSame('2026-11-23', $share->report_starts_on->toDateString());
        $this->assertSame('2026-11-29', $share->report_ends_on->toDateString());
        $this->assertSame([$target->id], $share->campaign_target_ids);
        $this->assertSame('好感行銷', $share->report_brand_name);
        $this->assertTrue($share->expires_at->equalTo(now()->addDays(30)));
        $this->assertSame(1, $share->snapshots()->count());

        Mail::assertSent(ClientReportDelivered::class, function (ClientReportDelivered $mail): bool {
            return $mail->hasTo('client@example.com')
                && $mail->hasTo('boss@example.com')
                && $mail->hasSubject('[好感行銷] 南區客戶｜週報 2026/11/23–2026/11/29')
                && str_starts_with($mail->reportUrl, url('/shared/client-reports').'/')
                && $mail->csvContent !== ''
                && $mail->hasAttachment(
                    Attachment::fromData(fn (): string => $mail->csvContent, 'client-report-2026-11-23-to-2026-11-29.csv')->withMime('text/csv'),
                );
        });

        $this->get(Mail::sent(ClientReportDelivered::class)->first()->reportUrl)->assertOk();

        $schedule->refresh();
        $this->assertSame('2026-12-07', $schedule->next_run_on->toDateString());
        $this->assertSame('2026-11-29', $schedule->last_delivered_period_ends_on->toDateString());
        $this->assertSame($share->id, $schedule->last_report_share_id);
        $this->assertNull($schedule->last_failure_reason);
    }

    public function test_command_delivers_the_previous_calendar_month_for_monthly_schedules(): void
    {
        Mail::fake();
        $this->travelTo(Carbon::parse('2026-12-01 08:41:00', 'Asia/Taipei'));
        ['template' => $template] = $this->createClientWithTemplate();
        $schedule = ClientReportSchedule::factory()->monthly()->create([
            'client_report_template_id' => $template->id,
            'next_run_on' => '2026-12-01',
        ]);

        $this->artisan('client-reports:send-scheduled')->assertExitCode(0);

        $share = ClientReportShare::query()->sole();
        $this->assertSame('2026-11-01', $share->report_starts_on->toDateString());
        $this->assertSame('2026-11-30', $share->report_ends_on->toDateString());
        $this->assertTrue($share->expires_at->equalTo(now()->addDays(90)));
        $this->assertSame('2027-01-01', $schedule->refresh()->next_run_on->toDateString());
        Mail::assertSent(ClientReportDelivered::class, 1);
    }

    public function test_command_skips_paused_future_already_delivered_and_inaccessible_schedules(): void
    {
        Mail::fake();
        $this->travelTo(Carbon::parse('2026-11-30 08:41:00', 'Asia/Taipei'));
        ['template' => $pausedTemplate] = $this->createClientWithTemplate();
        ClientReportSchedule::factory()->paused()->create([
            'client_report_template_id' => $pausedTemplate->id,
            'next_run_on' => '2026-11-30',
        ]);
        ['template' => $futureTemplate] = $this->createClientWithTemplate();
        ClientReportSchedule::factory()->create([
            'client_report_template_id' => $futureTemplate->id,
            'next_run_on' => '2026-12-07',
        ]);
        ['template' => $deliveredTemplate] = $this->createClientWithTemplate();
        $deliveredSchedule = ClientReportSchedule::factory()->create([
            'client_report_template_id' => $deliveredTemplate->id,
            'next_run_on' => '2026-11-30',
        ]);
        $deliveredSchedule->forceFill(['last_delivered_period_ends_on' => '2026-11-29'])->save();
        ['owner' => $disabledOwner, 'template' => $disabledTemplate] = $this->createClientWithTemplate();
        Organization::query()->updateOrCreate(
            ['owner_user_id' => $disabledOwner->id],
            ['name' => '停用組織', 'is_enabled' => false],
        );
        ClientReportSchedule::factory()->create([
            'client_report_template_id' => $disabledTemplate->id,
            'next_run_on' => '2026-11-30',
        ]);

        $this->artisan('client-reports:send-scheduled')->assertExitCode(0);

        Mail::assertNothingSent();
        $this->assertDatabaseCount('client_report_shares', 0);
        $this->assertSame('2026-12-07', $deliveredSchedule->refresh()->next_run_on->toDateString());
    }

    public function test_failed_delivery_retries_the_next_day_then_moves_to_the_next_period(): void
    {
        Mail::fake();
        $this->travelTo(Carbon::parse('2026-11-30 08:41:00', 'Asia/Taipei'));
        ['template' => $template, 'target' => $target] = $this->createClientWithTemplate();
        $target->delete();
        $schedule = ClientReportSchedule::factory()->create([
            'client_report_template_id' => $template->id,
            'next_run_on' => '2026-11-30',
        ]);

        $this->artisan('client-reports:send-scheduled')->assertExitCode(1);

        $schedule->refresh();
        $this->assertSame('樣板沒有可寄送的活動，請更新樣板。', $schedule->last_failure_reason);
        $this->assertSame(1, $schedule->consecutive_failures);
        $this->assertSame('2026-12-01', $schedule->next_run_on->toDateString());

        foreach (['2026-12-01', '2026-12-02'] as $retryDay) {
            $this->travelTo(Carbon::parse($retryDay.' 08:41:00', 'Asia/Taipei'));
            $this->artisan('client-reports:send-scheduled')->assertExitCode(1);
        }

        $schedule->refresh();
        $this->assertSame(0, $schedule->consecutive_failures);
        $this->assertSame('2026-12-07', $schedule->next_run_on->toDateString());
        Mail::assertNothingSent();
        $this->assertDatabaseCount('client_report_shares', 0);
    }

    public function test_mail_failure_revokes_the_generated_share(): void
    {
        $this->travelTo(Carbon::parse('2026-11-30 08:41:00', 'Asia/Taipei'));
        ['template' => $template] = $this->createClientWithTemplate();
        $schedule = ClientReportSchedule::factory()->create([
            'client_report_template_id' => $template->id,
            'next_run_on' => '2026-11-30',
        ]);
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP unavailable'));

        $this->artisan('client-reports:send-scheduled')->assertExitCode(1);

        $this->assertNotNull(ClientReportShare::query()->sole()->revoked_at);
        $schedule->refresh();
        $this->assertSame('報表產生或寄送失敗，系統將於明天重試。', $schedule->last_failure_reason);
        $this->assertNull($schedule->last_delivered_at);
    }

    public function test_periods_cover_the_last_complete_week_or_month(): void
    {
        $sunday = CarbonImmutable::parse('2026-12-06', 'Asia/Taipei');
        $weekly = ClientReportSchedule::periodForDeliveryOn(ClientReportSchedule::FREQUENCY_WEEKLY, $sunday);
        $monthly = ClientReportSchedule::periodForDeliveryOn(ClientReportSchedule::FREQUENCY_MONTHLY, CarbonImmutable::parse('2027-03-15', 'Asia/Taipei'));

        $this->assertSame(['2026-11-23', '2026-11-29'], [$weekly['from']->toDateString(), $weekly['to']->toDateString()]);
        $this->assertSame(['2027-02-01', '2027-02-28'], [$monthly['from']->toDateString(), $monthly['to']->toDateString()]);
        $this->assertSame('2027-02-01', ClientReportSchedule::nextRunAfter(ClientReportSchedule::FREQUENCY_MONTHLY, CarbonImmutable::parse('2027-01-31', 'Asia/Taipei'))->toDateString());
    }

    /**
     * @return array{owner: User, client: Client, template: ClientReportTemplate, target: SocialEngagementTarget}
     */
    private function createClientWithTemplate(?string $brandName = null): array
    {
        $owner = User::factory()->create();
        $client = Client::factory()->for($owner)->create(['name' => '南區客戶']);
        $target = SocialEngagementTarget::factory()->create([
            'client_id' => $client->id,
            'tag' => '週報活動',
            'social_platform' => 'facebook',
        ]);
        $template = $client->reportTemplates()->create([
            'name' => '週報樣板',
            'report_brand_name' => $brandName,
            'campaign_target_ids' => [$target->id],
            'report_sections' => ClientReportShare::DEFAULT_REPORT_SECTIONS,
        ]);

        return compact('owner', 'client', 'template', 'target');
    }
}
