<?php

namespace Tests\Feature;

use App\Models\Incident;
use App\Models\Monitor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IncidentExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login_before_exporting_incident_history(): void
    {
        $this->get(route('incidents.export'))->assertRedirect(route('login'));
    }

    public function test_export_contains_column_headings_when_no_incidents_exist(): void
    {
        $response = $this->actingAs(User::factory()->create())
            ->get(route('incidents.export'))
            ->assertOk();

        $headers = str_getcsv(trim($response->streamedContent()));

        $this->assertSame([
            'incident_id',
            'monitor_name',
            'rule_key',
            'severity',
            'state',
            'first_seen_at_utc',
            'classification',
            'failure_count',
            'recovery_count',
            'opened_at_utc',
            'resolved_at_utc',
            'confirmed_duration_seconds',
            'acknowledged_by',
            'acknowledged_at_utc',
            'notifications_snoozed_until_utc',
            'last_seen_at_utc',
            'note',
        ], $headers);
    }

    public function test_dashboard_links_to_history_and_history_exports_formula_safe_csv_without_monitor_urls(): void
    {
        $this->freezeTime();
        $monitorUrl = 'https://example.test/post?token=private-url-value';
        $monitor = Monitor::factory()->create([
            'name' => '=HYPERLINK("https://evil.example")',
            'url' => $monitorUrl,
            'url_hash' => Monitor::fingerprintUrl($monitorUrl),
        ]);
        $user = User::factory()->create(['name' => '@Operations manager']);
        $resolvedIncident = Incident::factory()->for($monitor)->create([
            'rule_key' => 'http',
            'state' => Incident::STATE_RESOLVED,
            'classification' => 'true_issue',
            'opened_at' => now()->subHours(2),
            'resolved_at' => now()->subHour(),
            'acknowledged_at' => now()->subMinutes(70),
            'acknowledged_by_id' => $user->id,
            'snoozed_until' => now()->addHour(),
            'note' => '=SUM(1+1)',
        ]);
        $falseAlarmIncident = Incident::factory()->for($monitor)->create([
            'state' => Incident::STATE_RESOLVED,
            'classification' => 'false_alarm',
            'opened_at' => now()->subMinutes(10),
            'resolved_at' => now(),
        ]);
        $openIncident = Incident::factory()->for($monitor)->create([
            'state' => Incident::STATE_OPEN,
            'opened_at' => now()->subMinutes(20),
            'resolved_at' => null,
        ]);
        $suspectIncident = Incident::factory()->for($monitor)->create([
            'state' => Incident::STATE_SUSPECT,
            'opened_at' => null,
            'resolved_at' => null,
        ]);
        $pausedIncident = Incident::factory()->for($monitor)->create([
            'state' => Incident::STATE_PAUSED,
            'opened_at' => now()->subHour(),
            'resolved_at' => null,
        ]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('查看所有事件')
            ->assertSee(route('incidents.index'));

        $this->actingAs($user)
            ->get(route('incidents.index'))
            ->assertOk()
            ->assertSee('匯出符合條件的 CSV')
            ->assertSee(route('incidents.export'));

        $response = $this->actingAs($user)
            ->get(route('incidents.export'))
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('incidents.csv', (string) $response->headers->get('content-disposition'));

        $content = $response->streamedContent();
        $lines = preg_split('/\r\n|\n|\r/', trim($content));
        $headers = str_getcsv(array_shift($lines));
        $rows = array_map(static fn (string $line): array => str_getcsv($line), $lines);
        $rowsByIncidentId = collect($rows)->keyBy(static fn (array $row): string => $row[0]);
        $columnIndexes = array_flip($headers);

        $this->assertCount(5, $rows);
        $this->assertStringContainsString("'=HYPERLINK", $content);
        $this->assertStringContainsString("'=SUM", $content);
        $this->assertStringContainsString("'@Operations manager", $content);
        $this->assertStringNotContainsString('private-url-value', $content);
        $this->assertSame('3600', $rowsByIncidentId->get((string) $resolvedIncident->id)[$columnIndexes['confirmed_duration_seconds']]);
        $this->assertSame('', $rowsByIncidentId->get((string) $falseAlarmIncident->id)[$columnIndexes['confirmed_duration_seconds']]);
        $this->assertSame('1200', $rowsByIncidentId->get((string) $openIncident->id)[$columnIndexes['confirmed_duration_seconds']]);
        $this->assertSame(now()->addHour()->utc()->toIso8601String(), $rowsByIncidentId->get((string) $resolvedIncident->id)[$columnIndexes['notifications_snoozed_until_utc']]);
        $this->assertSame('', $rowsByIncidentId->get((string) $suspectIncident->id)[$columnIndexes['confirmed_duration_seconds']]);
        $this->assertSame('', $rowsByIncidentId->get((string) $pausedIncident->id)[$columnIndexes['confirmed_duration_seconds']]);
    }
}
