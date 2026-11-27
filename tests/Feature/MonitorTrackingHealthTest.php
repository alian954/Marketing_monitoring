<?php

namespace Tests\Feature;

use App\Jobs\RunMonitorCheck;
use App\Models\CheckRun;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\User;
use App\Services\IncidentManager;
use App\Services\Probe\ProbeClient;
use App\Services\Probe\TrackingTagExtractor;
use App\Services\RunScheduler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MonitorTrackingHealthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('monitoring.transport', 'fixture');
        Queue::fake();
    }

    public function test_extractor_finds_google_and_meta_tags_and_normalizes_input(): void
    {
        $html = <<<'HTML'
            <script async src="https://www.googletagmanager.com/gtag/js?id=G-ABC123XYZ"></script>
            <script>gtag('config', 'AW-123456789'); (function(w,d,s,l,i){})(window,document,'script','dataLayer','GTM-K9ZQ7P');</script>
            <script>fbq('init', '987654321012345');</script>
            <noscript><img src="https://www.facebook.com/tr?id=111122223333&amp;ev=PageView"></noscript>
            HTML;

        $this->assertSame(
            ['AW-123456789', 'G-ABC123XYZ', 'GTM-K9ZQ7P', 'META-111122223333', 'META-987654321012345'],
            TrackingTagExtractor::extract($html),
        );
        $this->assertSame([], TrackingTagExtractor::extract('<html>Plain page mentioning G-1 and GTM</html>'));
        $this->assertSame('META-987654321012345', TrackingTagExtractor::normalize(' 987654321012345 '));
        $this->assertSame('G-ABC123XYZ', TrackingTagExtractor::normalize('g-abc123xyz'));
        $this->assertNull(TrackingTagExtractor::normalize('UA-12345-1'));
        $this->assertSame('Meta Pixel 987654321012345', TrackingTagExtractor::label('META-987654321012345'));
    }

    public function test_tracking_tags_and_utm_option_are_validated_and_saved_as_a_new_configuration(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(route('monitors.create'))
            ->post(route('monitors.store'), $this->monitorInput(['expected_tracking_tags' => 'G-ABC123XYZ, UA-1234-1']))
            ->assertRedirect(route('monitors.create'))
            ->assertSessionHasErrors('expected_tracking_tags');
        $this->assertDatabaseCount('monitors', 0);

        $this->post(route('monitors.store'), $this->monitorInput([
            'expected_tracking_tags' => "g-abc123xyz\n987654321012345, G-ABC123XYZ",
            'alert_on_utm_loss' => '1',
        ]))->assertRedirect();

        $monitor = Monitor::query()->sole();
        $this->assertSame(['G-ABC123XYZ', 'META-987654321012345'], $monitor->expected_tracking_tags);
        $this->assertTrue($monitor->alert_on_utm_loss);

        $this->get(route('monitors.edit', $monitor))
            ->assertOk()
            ->assertSee('G-ABC123XYZ, META-987654321012345');

        $this->put(route('monitors.update', $monitor), $this->monitorInput([
            'name' => $monitor->name,
            'url' => '',
            'expected_tracking_tags' => '',
            'alert_on_utm_loss' => '0',
        ]))->assertRedirect(route('monitors.show', $monitor));

        $monitor->refresh();
        $this->assertNull($monitor->expected_tracking_tags);
        $this->assertFalse($monitor->alert_on_utm_loss);
        $this->assertSame(2, $monitor->config_version);
    }

    public function test_check_records_detected_tags_and_opens_incident_for_missing_tags(): void
    {
        $user = User::factory()->create();
        $monitor = $this->createMonitor('https://fixture.test/tracked', [
            'expected_tracking_tags' => ['G-FIXTURE123', 'GTM-MISSING1'],
        ]);

        $run = $this->runCheck($monitor);

        $this->assertSame(['G-FIXTURE123', 'META-123456789012345'], $run->response_tracking_tags);
        $incident = Incident::query()->where('rule_key', 'tracking_tags')->sole();
        $this->assertSame(['GTM-MISSING1'], $incident->events()->firstOrFail()->evidence['missing_tracking_tags']);

        $this->actingAs($user)
            ->get(route('monitors.show', $monitor))
            ->assertOk()
            ->assertSee('頁面缺少追蹤碼')
            ->assertSee('缺少：Google Tag Manager GTM-MISSING1')
            ->assertSee('最近偵測到：GA4 G-FIXTURE123、Meta Pixel 123456789012345');
    }

    public function test_present_tracking_tags_do_not_open_an_incident(): void
    {
        $monitor = $this->createMonitor('https://fixture.test/tracked', [
            'expected_tracking_tags' => ['G-FIXTURE123', 'META-123456789012345'],
        ]);

        $this->runCheck($monitor);

        $this->assertSame(0, Incident::query()->where('rule_key', 'tracking_tags')->count());
    }

    public function test_redirect_that_drops_utm_parameters_opens_incident_only_when_enabled(): void
    {
        $monitor = $this->createMonitor('https://fixture.test/redirect-https?utm_source=facebook&utm_campaign=year_end', [
            'alert_on_utm_loss' => true,
        ]);

        $this->runCheck($monitor);

        $incident = Incident::query()->where('rule_key', 'utm_lost')->sole();
        $this->assertSame(['utm_source', 'utm_campaign'], $incident->events()->firstOrFail()->evidence['lost_utm_parameters']);

        $disabledMonitor = $this->createMonitor('https://fixture.test/redirect-https?utm_source=line', [
            'alert_on_utm_loss' => false,
        ]);
        $this->runCheck($disabledMonitor);
        $keptMonitor = $this->createMonitor('https://fixture.test/ok?utm_source=line', [
            'alert_on_utm_loss' => true,
        ]);
        $this->runCheck($keptMonitor);

        $this->assertSame(1, Incident::query()->where('rule_key', 'utm_lost')->count());
    }

    /** @param array<string, mixed> $attributes */
    private function createMonitor(string $url, array $attributes): Monitor
    {
        return Monitor::factory()->create([
            'url' => $url,
            'url_hash' => Monitor::fingerprintUrl($url),
            ...$attributes,
        ]);
    }

    private function runCheck(Monitor $monitor): CheckRun
    {
        $run = app(RunScheduler::class)->enqueue($monitor, 'scheduled');
        $this->assertNotNull($run);

        (new RunMonitorCheck($run->id))->handle(
            app(ProbeClient::class),
            app(IncidentManager::class),
            app(RunScheduler::class),
        );

        return $run->fresh();
    }

    /** @return array<string, string> */
    private function monitorInput(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Tracking health check',
            'url' => 'https://fixture.test/tracked',
            'label' => '',
            'expected_text' => '',
            'expected_text_mode' => 'present',
            'redirect_hosts' => '',
            'request_headers' => '',
        ], $overrides);
    }
}
