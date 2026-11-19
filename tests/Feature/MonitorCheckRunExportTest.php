<?php

namespace Tests\Feature;

use App\Models\CheckRun;
use App\Models\Monitor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MonitorCheckRunExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_export_a_monitors_check_history(): void
    {
        $monitor = Monitor::factory()->create();

        $this->get(route('monitors.checks.export', $monitor))
            ->assertRedirect(route('login'));
    }

    public function test_authenticated_user_exports_scoped_formula_safe_check_history_without_sensitive_details(): void
    {
        $user = User::factory()->create();
        $privateUrl = 'https://private.example/post?token=secret-url-value';
        $monitor = Monitor::factory()->create([
            'url' => $privateUrl,
            'url_hash' => Monitor::fingerprintUrl($privateUrl),
        ]);
        $otherMonitor = Monitor::factory()->create([
            'url' => 'https://other.example/post',
            'url_hash' => Monitor::fingerprintUrl('https://other.example/post'),
        ]);
        $run = CheckRun::factory()->for($monitor)->create([
            'config_snapshot' => json_encode([
                'url' => $privateUrl,
                'expected_text' => 'private expected phrase',
                'expected_text_mode' => 'present_any',
            ], JSON_THROW_ON_ERROR),
            'source' => 'scheduled',
            'status' => 'failed',
            'scheduled_at' => '2026-11-18 01:02:03',
            'started_at' => '2026-11-18 01:02:04',
            'finished_at' => '2026-11-18 01:02:08',
            'normal_status' => 503,
            'adsbot_status' => null,
            'error_type' => "\u{00A0}＝HYPERLINK(\"https://evil.example\")",
            'adsbot_error_type' => '@SUM(1+1)',
            'elapsed_ms' => 8,
            'normal_elapsed_ms' => 4,
            'error_detail' => 'private transport error detail',
            'final_url' => 'https://private.example/redirected?secret=private-redirect-value',
            'redirects' => [['url' => 'https://private.example/redirected?secret=private-redirect-value']],
            'expected_text_found' => false,
            'tls_expires_at' => '2027-01-01 00:00:00',
        ]);
        CheckRun::factory()->for($otherMonitor)->create([
            'source' => 'manual',
            'error_type' => 'other monitor private run',
        ]);

        $this->actingAs($user)
            ->get(route('monitors.show', $monitor))
            ->assertOk()
            ->assertSee('匯出檢查 CSV')
            ->assertSee(route('monitors.checks.export', $monitor));

        $response = $this->actingAs($user)
            ->get(route('monitors.checks.export', $monitor))
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $this->assertStringContainsString('check-history-'.$monitor->id.'.csv', (string) $response->headers->get('content-disposition'));

        $content = $response->streamedContent();
        $lines = preg_split('/\r\n|\n|\r/', trim($content));
        $headers = str_getcsv(array_shift($lines));
        $rows = array_map(static fn (string $line): array => str_getcsv($line), $lines);
        $row = $rows[0];
        $columns = array_flip($headers);

        $this->assertSame([
            'check_run_id',
            'scheduled_at_utc',
            'started_at_utc',
            'finished_at_utc',
            'source',
            'status',
            'http_status',
            'adsbot_status',
            'http_elapsed_ms',
            'total_elapsed_ms',
            'error_type',
            'adsbot_error_type',
            'expected_text_found',
            'expected_response_header_found',
            'expected_text_mode',
            'tls_expires_at_utc',
        ], $headers);
        $this->assertCount(1, $rows);
        $this->assertSame((string) $run->id, $row[$columns['check_run_id']]);
        $this->assertSame('2026-11-18T01:02:03+00:00', $row[$columns['scheduled_at_utc']]);
        $this->assertSame('2026-11-18T01:02:04+00:00', $row[$columns['started_at_utc']]);
        $this->assertSame('2026-11-18T01:02:08+00:00', $row[$columns['finished_at_utc']]);
        $this->assertSame('scheduled', $row[$columns['source']]);
        $this->assertSame('failed', $row[$columns['status']]);
        $this->assertSame('503', $row[$columns['http_status']]);
        $this->assertSame('', $row[$columns['adsbot_status']]);
        $this->assertSame('4', $row[$columns['http_elapsed_ms']]);
        $this->assertSame('8', $row[$columns['total_elapsed_ms']]);
        $this->assertSame("'\u{00A0}＝HYPERLINK(\"https://evil.example\")", $row[$columns['error_type']]);
        $this->assertSame("'@SUM(1+1)", $row[$columns['adsbot_error_type']]);
        $this->assertSame('', $row[$columns['expected_text_found']]);
        $this->assertSame('', $row[$columns['expected_response_header_found']]);
        $this->assertSame('present_any', $row[$columns['expected_text_mode']]);
        $this->assertSame('2027-01-01T00:00:00+00:00', $row[$columns['tls_expires_at_utc']]);
        $this->assertStringNotContainsString('secret-url-value', $content);
        $this->assertStringNotContainsString('private expected phrase', $content);
        $this->assertStringNotContainsString('private transport error detail', $content);
        $this->assertStringNotContainsString('private-redirect-value', $content);
        $this->assertStringNotContainsString('other monitor private run', $content);
    }

    public function test_authenticated_user_exports_every_check_run_across_streaming_batches(): void
    {
        $user = User::factory()->create();
        $monitor = Monitor::factory()->create();
        CheckRun::factory()->for($monitor)->count(501)->create();

        $response = $this->actingAs($user)
            ->get(route('monitors.checks.export', $monitor))
            ->assertOk();
        $lines = preg_split('/\r\n|\n|\r/', trim($response->streamedContent()));

        $this->assertCount(502, $lines);
    }
}
