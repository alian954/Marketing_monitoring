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
    private function createScheduledRun(Monitor $monitor, array $ips, ?string $errorType, \DateTimeInterface $finishedAt): CheckRun
    {
        return CheckRun::factory()->for($monitor)->create([
            'source' => 'scheduled',
            'status' => 'completed',
            'finished_at' => $finishedAt,
            'monitor_host_ips' => $ips,
            'error_type' => $errorType,
        ]);
    }
}
