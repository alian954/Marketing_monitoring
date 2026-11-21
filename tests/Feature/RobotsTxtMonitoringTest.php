<?php

namespace Tests\Feature;

use App\Jobs\CheckRobotsTxt;
use App\Models\Monitor;
use App\Models\RobotsObservation;
use App\Models\User;
use App\Services\Probe\HopResponse;
use App\Services\Probe\HostResolver;
use App\Services\Probe\ProbeTransport;
use App\Services\Probe\RobotsTxtParser;
use App\Services\Probe\RobotsTxtProbe;
use App\Services\Probe\RobotsTxtResult;
use App\Services\Probe\SecureRobotsTxtProbe;
use App\Services\Probe\UrlSafetyPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RobotsTxtMonitoringTest extends TestCase
{
    use RefreshDatabase;

    public function test_daily_dispatch_only_queues_enabled_website_monitors_without_todays_observation(): void
    {
        Queue::fake([CheckRobotsTxt::class]);
        $websiteUrl = 'https://example.com/robots-website';
        $websiteMonitor = Monitor::factory()->create([
            'url' => $websiteUrl,
            'url_hash' => Monitor::fingerprintUrl($websiteUrl),
        ]);
        $disabledUrl = 'https://example.com/robots-disabled';
        $disabledMonitor = Monitor::factory()->create([
            'url' => $disabledUrl,
            'url_hash' => Monitor::fingerprintUrl($disabledUrl),
            'enabled' => false,
        ]);
        $socialMonitorUrl = 'https://www.facebook.com/example/posts/123456';
        $socialMonitor = Monitor::factory()->create([
            'url' => $socialMonitorUrl,
            'url_hash' => Monitor::fingerprintUrl($socialMonitorUrl),
        ]);
        $alreadyCheckedUrl = 'https://example.com/robots-checked';
        $alreadyCheckedMonitor = Monitor::factory()->create([
            'url' => $alreadyCheckedUrl,
            'url_hash' => Monitor::fingerprintUrl($alreadyCheckedUrl),
        ]);
        RobotsObservation::factory()->for($alreadyCheckedMonitor)->create(['observed_on' => today()]);

        $this->artisan('monitors:dispatch-robots')->assertExitCode(0);

        Queue::assertPushedTimes(CheckRobotsTxt::class, 1);
        Queue::assertPushed(CheckRobotsTxt::class, fn (CheckRobotsTxt $job): bool => $job->monitorId === $websiteMonitor->id);
        Queue::assertNotPushed(CheckRobotsTxt::class, fn (CheckRobotsTxt $job): bool => in_array($job->monitorId, [$disabledMonitor->id, $socialMonitor->id, $alreadyCheckedMonitor->id], true));
    }

    public function test_job_saves_crawler_decisions_and_policy_changes_without_creating_incidents_or_notifications(): void
    {
        $monitor = Monitor::factory()->create();
        RobotsObservation::factory()->for($monitor)->create([
            'observed_on' => today()->subDay(),
            'config_version' => $monitor->config_version,
            'policy_hash' => str_repeat('a', 64),
        ]);
        $probe = new class implements RobotsTxtProbe
        {
            public int $calls = 0;

            public function inspect(string $url, array $allowedRedirectHosts = []): RobotsTxtResult
            {
                $this->calls++;

                return new RobotsTxtResult('available', 200, null, str_repeat('b', 64), [
                    'Googlebot' => ['allowed' => false, 'matching_rule' => 'Disallow: /campaign'],
                    'AdsBot-Google' => ['allowed' => true, 'matching_rule' => null],
                    'FacebookExternalHit' => ['allowed' => true, 'matching_rule' => null],
                    'Twitterbot' => ['allowed' => true, 'matching_rule' => null],
                ], 1, 2);
            }
        };

        (new CheckRobotsTxt($monitor->id))->handle($probe);
        (new CheckRobotsTxt($monitor->id))->handle($probe);

        $observation = RobotsObservation::query()
            ->where('monitor_id', $monitor->id)
            ->whereDate('observed_on', today())
            ->firstOrFail();

        $this->assertSame(1, $probe->calls);
        $this->assertTrue($observation->policy_changed);
        $this->assertFalse($observation->crawler_decisions['Googlebot']['allowed']);
        $this->assertSame(1, $observation->rule_count);
        $this->assertSame(2, $observation->sitemap_count);
        $this->assertDatabaseCount('incidents', 0);
        $this->assertDatabaseCount('notification_outbox', 0);
        $this->assertDatabaseCount('check_runs', 0);
    }

    public function test_failed_robots_fetch_is_recorded_without_guessing_crawler_access(): void
    {
        $monitor = Monitor::factory()->create();
        $probe = new class implements RobotsTxtProbe
        {
            public function inspect(string $url, array $allowedRedirectHosts = []): RobotsTxtResult
            {
                return RobotsTxtResult::failed(null, 'dns_nxdomain');
            }
        };

        (new CheckRobotsTxt($monitor->id))->handle($probe);

        $observation = RobotsObservation::query()->where('monitor_id', $monitor->id)->firstOrFail();

        $this->assertSame('error', $observation->file_state);
        $this->assertSame('dns_nxdomain', $observation->error_type);
        $this->assertNull($observation->crawler_decisions);
        $this->assertNull($observation->policy_hash);
    }

    public function test_secure_probe_fetches_root_robots_file_and_simulates_rules_for_target_path(): void
    {
        $resolver = new class implements HostResolver
        {
            public function resolve(string $hostname): array
            {
                return ['93.184.216.34'];
            }
        };
        $transport = new class implements ProbeTransport
        {
            /** @var list<array{url: string, host: string, port: int, ips: list<string>}> */
            public array $targets = [];

            public function get(array $target, string $userAgent, int $timeoutMs): HopResponse
            {
                $this->targets[] = $target;

                return new HopResponse(200, null, "User-agent: FacebookExternalHit\nDisallow: /campaign\n", 5, null, null, null);
            }
        };
        $probe = new SecureRobotsTxtProbe(new UrlSafetyPolicy($resolver), $transport, new RobotsTxtParser);

        $result = $probe->inspect('https://example.com/campaign/launch?source=email');

        $this->assertSame('available', $result->fileState);
        $this->assertSame(200, $result->httpStatus);
        $this->assertFalse($result->crawlerDecisions['FacebookExternalHit']['allowed']);
        $this->assertTrue($result->crawlerDecisions['Googlebot']['allowed']);
        $this->assertSame('https://example.com/robots.txt', $transport->targets[0]['url']);
    }

    public function test_secure_probe_does_not_follow_a_redirect_to_an_unapproved_host(): void
    {
        $resolver = new class implements HostResolver
        {
            public function resolve(string $hostname): array
            {
                return ['93.184.216.34'];
            }
        };
        $transport = new class implements ProbeTransport
        {
            public int $requests = 0;

            public function get(array $target, string $userAgent, int $timeoutMs): HopResponse
            {
                $this->requests++;

                return new HopResponse(302, 'https://other.example/robots.txt', '', 5, null, null, null);
            }
        };
        $probe = new SecureRobotsTxtProbe(new UrlSafetyPolicy($resolver), $transport, new RobotsTxtParser);

        $result = $probe->inspect('https://example.com/campaign/launch');

        $this->assertSame('redirect_host_not_allowed', $result->errorType);
        $this->assertSame(1, $transport->requests);
        $this->assertNull($result->crawlerDecisions);
    }

    public function test_robots_review_displays_recent_daily_results_without_revealing_monitor_query_parameters(): void
    {
        $url = 'https://www.example.com/campaign/page?token=must-stay-private';
        $monitor = Monitor::factory()->create([
            'url' => $url,
            'url_hash' => Monitor::fingerprintUrl($url),
        ]);
        RobotsObservation::factory()->for($monitor)->create([
            'crawler_decisions' => [
                'Googlebot' => ['allowed' => false, 'matching_rule' => 'Disallow: /campaign'],
                'AdsBot-Google' => ['allowed' => true, 'matching_rule' => null],
                'FacebookExternalHit' => ['allowed' => true, 'matching_rule' => null],
                'Twitterbot' => ['allowed' => true, 'matching_rule' => null],
            ],
            'policy_changed' => true,
        ]);

        $this->actingAs(User::factory()->create())
            ->get(route('monitors.robots', $monitor))
            ->assertOk()
            ->assertSee('每日爬蟲可讀性')
            ->assertSee('Google 搜尋')
            ->assertSee('封鎖')
            ->assertSee('Disallow: /campaign')
            ->assertSee('robots.txt 政策與前一次有效觀測不同')
            ->assertSee('https://www.example.com/robots.txt', false)
            ->assertDontSee('must-stay-private');
    }

    public function test_social_post_robots_page_explains_that_website_crawler_analysis_does_not_apply(): void
    {
        $url = 'https://www.instagram.com/p/ExamplePost/';
        $monitor = Monitor::factory()->create([
            'url' => $url,
            'url_hash' => Monitor::fingerprintUrl($url),
        ]);

        $this->actingAs(User::factory()->create())
            ->get(route('monitors.robots', $monitor))
            ->assertOk()
            ->assertSee('每日 robots.txt 分析只適用一般網站監測')
            ->assertDontSee('每日爬蟲可讀性');
    }
}
