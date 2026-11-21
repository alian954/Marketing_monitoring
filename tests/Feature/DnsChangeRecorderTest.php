<?php

namespace Tests\Feature;

use App\Models\CheckRun;
use App\Models\DnsChangeEvent;
use App\Models\Monitor;
use App\Services\DnsChangeRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DnsChangeRecorderTest extends TestCase
{
    use RefreshDatabase;

    public function test_recorder_tracks_transition_from_public_addresses_to_a_dns_nxdomain_answer(): void
    {
        $monitor = Monitor::factory()->create();
        $this->createScheduledRun($monitor, ['93.184.216.34'], null, now()->subMinutes(2));
        $nxdomainRun = $this->createScheduledRun($monitor, [], 'dns_nxdomain', now()->subMinute());

        app(DnsChangeRecorder::class)->recordFor($nxdomainRun);

        $event = DnsChangeEvent::query()->sole();
        $this->assertSame(['93.184.216.34'], $event->previous_ips);
        $this->assertSame([], $event->current_ips);
        $this->assertSame('無公開 IP 回答', $event->currentIpsLabel());
    }

    public function test_recorder_tracks_recovery_from_a_dns_nxdomain_answer_to_public_addresses(): void
    {
        $monitor = Monitor::factory()->create();
        $this->createScheduledRun($monitor, [], 'dns_nxdomain', now()->subMinutes(2));
        $recoveredRun = $this->createScheduledRun($monitor, ['93.184.216.34'], null, now()->subMinute());

        app(DnsChangeRecorder::class)->recordFor($recoveredRun);

        $event = DnsChangeEvent::query()->sole();
        $this->assertSame([], $event->previous_ips);
        $this->assertSame(['93.184.216.34'], $event->current_ips);
        $this->assertSame('無公開 IP 回答', $event->previousIpsLabel());
    }

    public function test_recorder_tracks_a_cname_change_when_public_ip_addresses_are_unchanged(): void
    {
        $monitor = Monitor::factory()->create();
        $this->createScheduledRun($monitor, ['93.184.216.34'], null, now()->subMinutes(2), ['EDGE-OLD.example.net.']);
        $currentRun = $this->createScheduledRun($monitor, ['93.184.216.34'], null, now()->subMinute(), ['edge-new.example.net.']);

        app(DnsChangeRecorder::class)->recordFor($currentRun);

        $event = DnsChangeEvent::query()->sole();
        $this->assertSame(['93.184.216.34'], $event->previous_ips);
        $this->assertSame(['93.184.216.34'], $event->current_ips);
        $this->assertSame(['edge-old.example.net'], $event->previous_cnames);
        $this->assertSame(['edge-new.example.net'], $event->current_cnames);
        $this->assertSame('edge-old.example.net', $event->previousCnamesLabel());
        $this->assertSame('edge-new.example.net', $event->currentCnamesLabel());
    }

    public function test_recorder_tracks_mx_ns_txt_and_caa_changes_when_ip_and_cname_answers_are_unchanged(): void
    {
        $monitor = Monitor::factory()->create();
        $this->createScheduledRun($monitor, ['93.184.216.34'], null, now()->subMinutes(2), ['edge.example.net'], [
            ['type' => 'MX', 'value' => '10 mail-old.example.net'],
            ['type' => 'NS', 'value' => 'ns1.example.net'],
            ['type' => 'TXT', 'value' => 'v=spf1 include:old.example.net -all'],
            ['type' => 'CAA', 'value' => '0 issue old-ca.example'],
        ]);
        $currentRun = $this->createScheduledRun($monitor, ['93.184.216.34'], null, now()->subMinute(), ['edge.example.net'], [
            ['type' => 'MX', 'value' => '10 mail-new.example.net'],
            ['type' => 'NS', 'value' => 'ns1.example.net'],
            ['type' => 'TXT', 'value' => 'v=spf1 include:new.example.net -all'],
            ['type' => 'CAA', 'value' => '0 issue new-ca.example'],
        ]);

        app(DnsChangeRecorder::class)->recordFor($currentRun);

        $event = DnsChangeEvent::query()->sole();
        $this->assertSame([
            ['type' => 'CAA', 'value' => '0 issue old-ca.example'],
            ['type' => 'MX', 'value' => '10 mail-old.example.net'],
            ['type' => 'NS', 'value' => 'ns1.example.net'],
            ['type' => 'TXT', 'value' => 'v=spf1 include:old.example.net -all'],
        ], $event->previous_records);
        $this->assertSame([
            ['type' => 'CAA', 'value' => '0 issue new-ca.example'],
            ['type' => 'MX', 'value' => '10 mail-new.example.net'],
            ['type' => 'NS', 'value' => 'ns1.example.net'],
            ['type' => 'TXT', 'value' => 'v=spf1 include:new.example.net -all'],
        ], $event->current_records);
        $this->assertStringContainsString('MX: 10 mail-old.example.net', $event->previousRecordsLabel());
        $this->assertStringContainsString('TXT: v=spf1 include:new.example.net -all', $event->currentRecordsLabel());
    }

    public function test_recorder_ignores_record_order_and_type_case_when_dns_records_are_unchanged(): void
    {
        $monitor = Monitor::factory()->create();
        $records = [
            ['type' => 'MX', 'value' => '10 mail.example.net'],
            ['type' => 'NS', 'value' => 'ns1.example.net'],
        ];
        $this->createScheduledRun($monitor, ['93.184.216.34'], null, now()->subMinutes(2), [], $records);
        $currentRun = $this->createScheduledRun($monitor, ['93.184.216.34'], null, now()->subMinute(), [], [
            ['type' => 'ns', 'value' => 'ns1.example.net'],
            ['type' => 'mx', 'value' => '10 mail.example.net'],
        ]);

        app(DnsChangeRecorder::class)->recordFor($currentRun);

        $this->assertDatabaseCount('dns_change_events', 0);
    }

    public function test_recorder_does_not_infer_other_dns_record_changes_from_an_unknown_previous_snapshot(): void
    {
        $monitor = Monitor::factory()->create();
        $this->createScheduledRun($monitor, ['93.184.216.34'], null, now()->subMinutes(2));
        $currentRun = $this->createScheduledRun($monitor, ['93.184.216.34'], null, now()->subMinute(), null, [
            ['type' => 'MX', 'value' => '10 mail.example.net'],
        ]);

        app(DnsChangeRecorder::class)->recordFor($currentRun);

        $this->assertDatabaseCount('dns_change_events', 0);
    }

    public function test_recorder_ignores_dns_answer_order_and_case_changes_when_cname_sets_are_unchanged(): void
    {
        $monitor = Monitor::factory()->create();
        $this->createScheduledRun($monitor, ['93.184.216.34'], null, now()->subMinutes(2), ['edge-a.example.net', 'edge-b.example.net']);
        $currentRun = $this->createScheduledRun($monitor, ['93.184.216.34'], null, now()->subMinute(), ['EDGE-B.example.net.', 'EDGE-A.example.net.']);

        app(DnsChangeRecorder::class)->recordFor($currentRun);

        $this->assertDatabaseCount('dns_change_events', 0);
    }

    public function test_recorder_does_not_infer_a_cname_change_from_an_unknown_previous_snapshot(): void
    {
        $monitor = Monitor::factory()->create();
        $this->createScheduledRun($monitor, ['93.184.216.34'], null, now()->subMinutes(2));
        $currentRun = $this->createScheduledRun($monitor, ['93.184.216.34'], null, now()->subMinute(), ['edge.example.net']);

        app(DnsChangeRecorder::class)->recordFor($currentRun);

        $this->assertDatabaseCount('dns_change_events', 0);
    }

    public function test_recorder_does_not_treat_a_dns_timeout_as_an_empty_answer(): void
    {
        $monitor = Monitor::factory()->create();
        $this->createScheduledRun($monitor, ['93.184.216.34'], null, now()->subMinutes(2));
        $timedOutRun = $this->createScheduledRun($monitor, [], 'dns_timeout', now()->subMinute());

        app(DnsChangeRecorder::class)->recordFor($timedOutRun);

        $this->assertDatabaseCount('dns_change_events', 0);
    }

    public function test_recorder_uses_the_latest_known_dns_answer_after_a_transient_timeout(): void
    {
        $monitor = Monitor::factory()->create();
        $this->createScheduledRun($monitor, ['93.184.216.34'], null, now()->subMinutes(3));
        $this->createScheduledRun($monitor, [], 'dns_timeout', now()->subMinutes(2));
        $recoveredRun = $this->createScheduledRun($monitor, ['93.184.216.34'], null, now()->subMinute());

        app(DnsChangeRecorder::class)->recordFor($recoveredRun);

        $this->assertDatabaseCount('dns_change_events', 0);
    }

    public function test_recorder_does_not_repeat_an_event_for_an_unchanged_nxdomain_answer(): void
    {
        $monitor = Monitor::factory()->create();
        $this->createScheduledRun($monitor, [], 'dns_nxdomain', now()->subMinutes(2));
        $nxdomainRun = $this->createScheduledRun($monitor, [], 'dns_nxdomain', now()->subMinute());

        app(DnsChangeRecorder::class)->recordFor($nxdomainRun);

        $this->assertDatabaseCount('dns_change_events', 0);
    }

    /** @param list<string> $ips */
    private function createScheduledRun(
        Monitor $monitor,
        array $ips,
        ?string $errorType,
        \DateTimeInterface $finishedAt,
        ?array $cnames = null,
        ?array $records = null,
    ): CheckRun {
        return CheckRun::factory()->for($monitor)->create([
            'source' => 'scheduled',
            'status' => 'completed',
            'finished_at' => $finishedAt,
            'monitor_host_ips' => $ips,
            'monitor_host_cnames' => $cnames,
            'monitor_host_dns_records' => $records,
            'error_type' => $errorType,
        ]);
    }
}
