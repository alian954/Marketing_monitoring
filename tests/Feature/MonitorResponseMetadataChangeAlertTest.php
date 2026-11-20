<?php

namespace Tests\Feature;

use App\Models\CheckRun;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\NotificationOutbox;
use App\Models\User;
use App\Services\IncidentManager;
use App\Services\Probe\ProbeResult;
use DateTimeInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class MonitorResponseMetadataChangeAlertTest extends TestCase
{
    use RefreshDatabase;

    public function test_changed_sharing_metadata_opens_a_warning_with_field_names_only(): void
    {
        $this->freezeTime();
        Queue::fake();
        $monitor = $this->createMonitor(alertOnResponseMetadataChange: true, notificationEmail: 'owner@example.test');
        $previousRun = $this->createRun($monitor, now()->subMinute(), [
            'response_title' => 'Campaign post',
            'response_description' => 'Original campaign summary',
            'response_image_reference_hash' => str_repeat('a', 64),
            'response_open_graph_type' => 'article',
            'response_canonical_reference_hash' => str_repeat('c', 64),
            'response_published_at' => now()->subDays(2),
            'response_modified_at' => now()->subDay(),
        ]);
        $manager = app(IncidentManager::class);
        $manager->record($monitor, $previousRun, $this->probe($monitor), null);
        $this->assertDatabaseMissing('incidents', ['rule_key' => 'response_content_change']);
        $currentRun = $this->createRun($monitor, now(), [
            'response_title' => 'Updated campaign post',
            'response_description' => 'Updated campaign summary',
            'response_image_reference_hash' => str_repeat('b', 64),
            'response_open_graph_type' => 'website',
            'response_canonical_reference_hash' => str_repeat('d', 64),
            'response_published_at' => now()->subDays(3),
            'response_modified_at' => now(),
        ]);

        $manager->record($monitor, $currentRun, $this->probe($monitor), null);

        $incident = Incident::query()->sole();
        $this->assertSame('response_content_change', $incident->rule_key);
        $this->assertSame(Incident::STATE_OPEN, $incident->state);
        $event = $incident->events()->where('check_run_id', $currentRun->id)->where('event_type', 'failure_seen')->sole();
        $this->assertSame(['response_metadata'], $event->evidence['comparisons']);
        $this->assertSame([
            'title',
            'description',
            'image_reference',
            'open_graph_type',
            'canonical_reference',
            'published_at',
            'modified_at',
        ], $event->evidence['response_metadata_fields']);

        $evidenceJson = json_encode($event->evidence, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('Updated campaign post', $evidenceJson);
        $this->assertStringNotContainsString('Updated campaign summary', $evidenceJson);
        $this->assertStringNotContainsString(str_repeat('b', 64), $evidenceJson);
        $this->assertStringNotContainsString(str_repeat('d', 64), $evidenceJson);

        $outbox = NotificationOutbox::query()->where('incident_id', $incident->id)->sole();
        $this->assertSame('opened', $outbox->event_type);
        $this->assertSame('owner@example.test', $outbox->recipient);
        $this->assertStringContainsString('分享預覽資訊已變更', $outbox->body);
        $this->assertStringNotContainsString('Updated campaign post', $outbox->body);
        $this->assertStringNotContainsString(str_repeat('b', 64), $outbox->body);

        $this->actingAs(User::factory()->create())
            ->get(route('monitors.show', $monitor))
            ->assertOk()
            ->assertSee('分享預覽資訊變更警示')
            ->assertSee('變更欄位：分享標題、分享摘要、分享圖片參照、Open Graph 類型、標準網址參照、發佈時間、修改時間')
            ->assertDontSee(str_repeat('b', 64))
            ->assertDontSee(str_repeat('d', 64));
    }

    public function test_simultaneous_text_and_metadata_changes_create_one_incident_and_one_notification(): void
    {
        Queue::fake();
        $monitor = $this->createMonitor(
            alertOnVisibleTextChange: true,
            alertOnResponseMetadataChange: true,
            notificationEmail: 'owner@example.test',
        );
        $previousHash = hash('sha256', 'Original post text');
        $currentHash = hash('sha256', 'Updated post text');
        $manager = app(IncidentManager::class);
        $previousRun = $this->createRun($monitor, now()->subMinute(), [
            'response_visible_text_hash' => $previousHash,
            'response_title' => 'Original title',
        ]);
        $manager->record($monitor, $previousRun, $this->probe($monitor), null);
        $currentRun = $this->createRun($monitor, now(), [
            'response_visible_text_hash' => $currentHash,
            'response_title' => 'Updated title',
        ]);

        $manager->record($monitor, $currentRun, $this->probe($monitor), null);

        $incident = Incident::query()->sole();
        $this->assertSame(['visible_text_hash', 'response_metadata'], $incident->events()->where('check_run_id', $currentRun->id)->where('event_type', 'failure_seen')->sole()->evidence['comparisons']);
        $this->assertSame(1, $incident->events()->where('check_run_id', $currentRun->id)->where('event_type', 'failure_seen')->count());
        $this->assertSame(1, NotificationOutbox::query()->where('incident_id', $incident->id)->count());
    }

    public function test_metadata_alert_is_opt_in_and_the_first_response_only_sets_a_baseline(): void
    {
        $monitor = $this->createMonitor();
        $manager = app(IncidentManager::class);
        $previousRun = $this->createRun($monitor, now()->subMinute(), ['response_title' => 'Original title']);
        $manager->record($monitor, $previousRun, $this->probe($monitor), null);
        $currentRun = $this->createRun($monitor, now(), ['response_title' => 'Updated title']);
        $manager->record($monitor, $currentRun, $this->probe($monitor), null);

        $this->assertDatabaseMissing('incidents', ['rule_key' => 'response_content_change']);

        $enabledMonitor = $this->createMonitor(alertOnResponseMetadataChange: true);
        $baselineRun = $this->createRun($enabledMonitor, now(), ['response_title' => 'First title']);
        $manager->record($enabledMonitor, $baselineRun, $this->probe($enabledMonitor), null);

        $this->assertDatabaseMissing('incidents', ['rule_key' => 'response_content_change']);
    }

    public function test_stable_metadata_resolves_the_warning_without_a_recovery_notification(): void
    {
        $this->freezeTime();
        Queue::fake();
        $monitor = $this->createMonitor(alertOnResponseMetadataChange: true, notificationEmail: 'owner@example.test');
        $manager = app(IncidentManager::class);
        $manager->record($monitor, $this->createRun($monitor, now()->subMinutes(2), ['response_title' => 'Original title']), $this->probe($monitor), null);
        $manager->record($monitor, $this->createRun($monitor, now()->subMinute(), ['response_title' => 'Updated title']), $this->probe($monitor), null);

        $this->travel(1)->second();
        $manager->record($monitor, $this->createRun($monitor, now(), ['response_title' => 'Updated title']), $this->probe($monitor), null);

        $this->travel(61)->seconds();
        $manager->record($monitor, $this->createRun($monitor, now(), ['response_title' => 'Updated title']), $this->probe($monitor), null);

        $incident = Incident::query()->sole();
        $this->assertSame(Incident::STATE_RESOLVED, $incident->state);
        $this->assertSame(2, $incident->recovery_count);
        $this->assertSame(1, NotificationOutbox::query()->where('incident_id', $incident->id)->count());
    }

    public function test_metadata_from_an_older_configuration_is_not_used_as_a_baseline(): void
    {
        $monitor = $this->createMonitor(alertOnResponseMetadataChange: true);
        $monitor->forceFill(['config_version' => 2])->save();
        $this->createRun($monitor, now()->subMinute(), ['response_title' => 'Old title'], configVersion: 1);
        $currentRun = $this->createRun($monitor, now(), ['response_title' => 'New title']);

        app(IncidentManager::class)->record($monitor, $currentRun, $this->probe($monitor), null);

        $this->assertDatabaseMissing('incidents', ['rule_key' => 'response_content_change']);
    }

    private function createMonitor(
        bool $alertOnVisibleTextChange = false,
        bool $alertOnResponseMetadataChange = false,
        ?string $notificationEmail = null,
    ): Monitor {
        $url = 'https://www.instagram.com/p/'.Str::upper(Str::random(12)).'/';

        return Monitor::factory()->create([
            'url' => $url,
            'url_hash' => Monitor::fingerprintUrl($url),
            'alert_on_visible_text_change' => $alertOnVisibleTextChange,
            'alert_on_response_metadata_change' => $alertOnResponseMetadataChange,
            'notification_email' => $notificationEmail,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function createRun(
        Monitor $monitor,
        DateTimeInterface $scheduledAt,
        array $attributes = [],
        ?int $configVersion = null,
    ): CheckRun {
        return CheckRun::factory()->for($monitor)->create([
            'config_version' => $configVersion ?? $monitor->config_version,
            'scheduled_at' => $scheduledAt,
            'finished_at' => $scheduledAt,
            ...$attributes,
        ]);
    }

    private function probe(Monitor $monitor): ProbeResult
    {
        return new ProbeResult(
            statusCode: 200,
            elapsedMs: 40,
            finalUrl: $monitor->url,
            redirects: [],
            resolvedPublicIps: ['93.184.216.34'],
            errorType: null,
            errorDetail: null,
            expectedTextFound: null,
            tlsExpiresAt: null,
        );
    }
}
