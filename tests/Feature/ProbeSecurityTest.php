<?php

namespace Tests\Feature;

use App\Services\Probe\ExpectedTextMatcher;
use App\Services\Probe\FixtureProbeClient;
use App\Services\Probe\HopResponse;
use App\Services\Probe\HostResolver;
use App\Services\Probe\ProbeTransport;
use App\Services\Probe\SecureProbeClient;
use App\Services\Probe\UrlSafetyPolicy;
use App\Services\UrlMasker;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class ProbeSecurityTest extends TestCase
{
    public function test_mixed_public_and_private_dns_answers_are_blocked_before_any_dial(): void
    {
        $resolver = new class implements HostResolver
        {
            public function resolve(string $hostname): array
            {
                return ['93.184.216.34', '10.12.0.7'];
            }
        };
        $transport = new class implements ProbeTransport
        {
            public int $calls = 0;

            public function get(array $target, string $userAgent, int $timeoutMs): HopResponse
            {
                $this->calls++;

                return new HopResponse(200, null, '', 1, null, null, null);
            }
        };
        $client = new SecureProbeClient(new UrlSafetyPolicy($resolver), $transport, new UrlMasker);

        $result = $client->inspect('https://landing.example', [], 'MonitorTest/1.0');

        $this->assertSame('blocked_ssrf', $result->errorType);
        $this->assertSame(0, $transport->calls);
    }

    public function test_ipv6_private_redirect_is_rejected_before_a_second_dial(): void
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
            public int $calls = 0;

            public function get(array $target, string $userAgent, int $timeoutMs): HopResponse
            {
                $this->calls++;

                return new HopResponse(302, 'http://[fd00::1]/admin', '', 1, null, null, null);
            }
        };
        $client = new SecureProbeClient(new UrlSafetyPolicy($resolver), $transport, new UrlMasker);

        $result = $client->inspect('https://landing.example', [], 'MonitorTest/1.0');

        $this->assertSame('blocked_ssrf', $result->errorType);
        $this->assertSame(1, $transport->calls);
    }

    public function test_metadata_redirect_is_rejected_before_a_second_dial(): void
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
            public int $calls = 0;

            public function get(array $target, string $userAgent, int $timeoutMs): HopResponse
            {
                $this->calls++;

                return new HopResponse(302, 'http://169.254.169.254/latest/meta-data/', '', 1, null, null, null);
            }
        };
        $client = new SecureProbeClient(new UrlSafetyPolicy($resolver), $transport, new UrlMasker);

        $result = $client->inspect('https://landing.example', [], 'MonitorTest/1.0');

        $this->assertSame('blocked_ssrf', $result->errorType);
        $this->assertSame(1, $transport->calls);
    }

    public function test_vetted_ip_is_passed_to_transport_while_url_keeps_public_hostname(): void
    {
        $resolver = new class implements HostResolver
        {
            public function resolve(string $hostname): array
            {
                return ['93.184.216.34', '93.184.216.35'];
            }
        };
        $transport = new class implements ProbeTransport
        {
            /** @var list<array{url: string, host: string, port: int, ips: list<string>}> */
            public array $targets = [];

            public function get(array $target, string $userAgent, int $timeoutMs): HopResponse
            {
                $this->targets[] = $target;

                return new HopResponse(200, null, '<p>healthy</p>', 1, null, null, null);
            }
        };
        $client = new SecureProbeClient(new UrlSafetyPolicy($resolver), $transport, new UrlMasker);

        $result = $client->inspect('https://landing.example/path?email=owner%40example.com', [], 'MonitorTest/1.0');

        $this->assertSame(200, $result->statusCode);
        $this->assertSame('https://landing.example/path?email=REDACTED', $result->finalUrl);
        $this->assertSame('https://landing.example/path?email=owner%40example.com', $transport->targets[0]['url']);
        $this->assertSame(['93.184.216.34'], $transport->targets[0]['ips']);
        $this->assertSame(['93.184.216.34', '93.184.216.35'], $result->monitorHostIps);
    }

    public function test_social_platform_first_party_redirects_are_followed_without_a_user_allowlist(): void
    {
        $redirectCases = [
            ['https://fb.watch/ABC123/', 'https://www.facebook.com/watch/?v=ABC123'],
            ['https://threads.net/@creator/post/ABC123', 'https://www.threads.com/@creator/post/ABC123'],
            ['https://instagram.com/p/ABC123/', 'https://www.instagram.com/p/ABC123/'],
        ];

        foreach ($redirectCases as [$sourceUrl, $redirectUrl]) {
            $resolver = new class implements HostResolver
            {
                public function resolve(string $hostname): array
                {
                    return ['93.184.216.34'];
                }
            };
            $transport = new class($redirectUrl) implements ProbeTransport
            {
                public int $calls = 0;

                public function __construct(private readonly string $redirectUrl) {}

                public function get(array $target, string $userAgent, int $timeoutMs): HopResponse
                {
                    $this->calls++;

                    if ($this->calls === 1) {
                        return new HopResponse(302, $this->redirectUrl, '', 1, null, null, null);
                    }

                    return new HopResponse(200, null, '<article>Campaign headline</article>', 1, null, null, null);
                }
            };
            $client = new SecureProbeClient(new UrlSafetyPolicy($resolver), $transport, new UrlMasker);

            $result = $client->inspect($sourceUrl, [], 'MonitorTest/1.0', 'Campaign headline');

            $this->assertSame(200, $result->statusCode);
            $this->assertTrue($result->succeeded());
            $this->assertSame(true, $result->expectedTextFound);
            $this->assertCount(1, $result->redirects);
            $this->assertSame(2, $transport->calls);
        }
    }

    public function test_social_platform_redirects_to_unrelated_hosts_remain_blocked(): void
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
            public int $calls = 0;

            public function get(array $target, string $userAgent, int $timeoutMs): HopResponse
            {
                $this->calls++;

                return new HopResponse(302, 'https://attacker.example/collect', '', 1, null, null, null);
            }
        };
        $client = new SecureProbeClient(new UrlSafetyPolicy($resolver), $transport, new UrlMasker);

        $result = $client->inspect('https://www.facebook.com/story.php?story_fbid=123', [], 'MonitorTest/1.0');

        $this->assertSame('redirect_host_not_allowed', $result->errorType);
        $this->assertSame(1, $transport->calls);
    }

    public function test_transport_failure_falls_back_to_the_next_validated_ip(): void
    {
        $resolver = new class implements HostResolver
        {
            public function resolve(string $hostname): array
            {
                return ['93.184.216.34', '93.184.216.35'];
            }
        };
        $transport = new class implements ProbeTransport
        {
            /** @var list<array{url: string, host: string, port: int, ips: list<string>}> */
            public array $targets = [];

            /** @var list<int> */
            public array $timeouts = [];

            public function get(array $target, string $userAgent, int $timeoutMs): HopResponse
            {
                $this->targets[] = $target;
                $this->timeouts[] = $timeoutMs;

                if ($target['ips'][0] === '93.184.216.34') {
                    usleep(20_000);

                    return new HopResponse(null, null, '', 12, 'connection_error', 'connection failed', null);
                }

                return new HopResponse(200, null, '<p>healthy</p>', 8, null, null, null);
            }
        };
        $client = new SecureProbeClient(new UrlSafetyPolicy($resolver), $transport, new UrlMasker);

        $result = $client->inspect('https://landing.example', [], 'MonitorTest/1.0', timeoutSeconds: 1);

        $this->assertSame(200, $result->statusCode);
        $this->assertSame(20, $result->elapsedMs);
        $this->assertSame([['93.184.216.34'], ['93.184.216.35']], array_column($transport->targets, 'ips'));
        $this->assertLessThan($transport->timeouts[0], $transport->timeouts[1]);
        $this->assertSame(['93.184.216.34', '93.184.216.35'], $result->resolvedPublicIps);
    }

    public function test_http_error_responses_do_not_fall_back_to_another_ip(): void
    {
        foreach ([429, 503] as $statusCode) {
            $resolver = new class implements HostResolver
            {
                public function resolve(string $hostname): array
                {
                    return ['93.184.216.34', '93.184.216.35'];
                }
            };
            $transport = new class($statusCode) implements ProbeTransport
            {
                public int $calls = 0;

                public function __construct(private readonly int $statusCode) {}

                public function get(array $target, string $userAgent, int $timeoutMs): HopResponse
                {
                    $this->calls++;

                    return new HopResponse($this->statusCode, null, '', 1, null, null, null);
                }
            };
            $client = new SecureProbeClient(new UrlSafetyPolicy($resolver), $transport, new UrlMasker);

            $result = $client->inspect('https://landing.example', [], 'MonitorTest/1.0');

            $this->assertSame($statusCode, $result->statusCode);
            $this->assertSame(1, $transport->calls);
        }
    }

    public function test_all_transport_failures_are_aggregated_and_return_the_last_error(): void
    {
        $resolver = new class implements HostResolver
        {
            public function resolve(string $hostname): array
            {
                return ['93.184.216.34', '93.184.216.35', '93.184.216.36'];
            }
        };
        $transport = new class implements ProbeTransport
        {
            /** @var list<array{url: string, host: string, port: int, ips: list<string>}> */
            public array $targets = [];

            public function get(array $target, string $userAgent, int $timeoutMs): HopResponse
            {
                $this->targets[] = $target;

                return new HopResponse(null, null, '', 7, 'connection_error', 'connection failed', null);
            }
        };
        $client = new SecureProbeClient(new UrlSafetyPolicy($resolver), $transport, new UrlMasker);

        $result = $client->inspect('https://landing.example', [], 'MonitorTest/1.0');

        $this->assertSame('connection_error', $result->errorType);
        $this->assertSame('connection failed', $result->errorDetail);
        $this->assertSame(21, $result->elapsedMs);
        $this->assertSame(3, count($transport->targets));
    }

    public function test_expected_text_matches_content_encoded_as_html_entities(): void
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
            public function get(array $target, string $userAgent, int $timeoutMs): HopResponse
            {
                return new HopResponse(200, null, '<article>Campaign &amp; special offer</article>', 1, null, null, null);
            }
        };
        $client = new SecureProbeClient(new UrlSafetyPolicy($resolver), $transport, new UrlMasker);

        $result = $client->inspect('https://landing.example', [], 'MonitorTest/1.0', 'Campaign & special offer');

        $this->assertTrue($result->expectedTextFound);
    }

    public function test_expected_text_matches_when_html_markup_splits_visible_text(): void
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
            public function get(array $target, string $userAgent, int $timeoutMs): HopResponse
            {
                return new HopResponse(200, null, '<article>Campaign <strong>headline</strong></article>', 1, null, null, null);
            }
        };
        $client = new SecureProbeClient(new UrlSafetyPolicy($resolver), $transport, new UrlMasker);

        $result = $client->inspect('https://landing.example', [], 'MonitorTest/1.0', 'Campaign headline');

        $this->assertTrue($result->expectedTextFound);
    }

    public function test_expected_text_matches_json_unicode_escapes_in_raw_response(): void
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
            public function get(array $target, string $userAgent, int $timeoutMs): HopResponse
            {
                return new HopResponse(200, null, '<script>{"caption":"\\u71b1\\u9580\\u512a\\u60e0"}</script>', 1, null, null, null);
            }
        };
        $client = new SecureProbeClient(new UrlSafetyPolicy($resolver), $transport, new UrlMasker);

        $result = $client->inspect('https://landing.example', [], 'MonitorTest/1.0', '熱門優惠');

        $this->assertTrue($result->expectedTextFound);
    }

    public function test_expected_text_matches_json_unicode_escapes_with_unescaped_slashes(): void
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
            public function get(array $target, string $userAgent, int $timeoutMs): HopResponse
            {
                return new HopResponse(200, null, '<script>{"caption":"\\u512a\\u60e0/\\u6298\\u6263"}</script>', 1, null, null, null);
            }
        };
        $client = new SecureProbeClient(new UrlSafetyPolicy($resolver), $transport, new UrlMasker);

        $result = $client->inspect('https://landing.example', [], 'MonitorTest/1.0', '優惠/折扣');

        $this->assertTrue($result->expectedTextFound);
    }

    public function test_oversized_response_can_confirm_expected_text_from_its_bounded_prefix(): void
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
            public function get(array $target, string $userAgent, int $timeoutMs): HopResponse
            {
                return new HopResponse(200, null, '<article>Campaign &amp; special offer', 1, 'body_too_large', 'body limit', null);
            }
        };
        $client = new SecureProbeClient(new UrlSafetyPolicy($resolver), $transport, new UrlMasker);

        $result = $client->inspect('https://landing.example', [], 'MonitorTest/1.0', 'Campaign & special offer');

        $this->assertSame(200, $result->statusCode);
        $this->assertNull($result->errorType);
        $this->assertTrue($result->expectedTextFound);
    }

    public function test_oversized_response_without_a_match_stays_indeterminate(): void
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
            public function get(array $target, string $userAgent, int $timeoutMs): HopResponse
            {
                return new HopResponse(200, null, '<article>Other content</article>', 1, 'body_too_large', 'body limit', null);
            }
        };
        $client = new SecureProbeClient(new UrlSafetyPolicy($resolver), $transport, new UrlMasker);

        $result = $client->inspect('https://landing.example', [], 'MonitorTest/1.0', 'Expected campaign phrase');

        $this->assertSame('body_too_large', $result->errorType);
        $this->assertNull($result->expectedTextFound);
    }

    public function test_oversized_response_without_a_content_condition_still_confirms_http_availability(): void
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
            public function get(array $target, string $userAgent, int $timeoutMs): HopResponse
            {
                return new HopResponse(200, null, '<article>Large response prefix</article>', 1, 'body_too_large', 'body limit', null);
            }
        };
        $client = new SecureProbeClient(new UrlSafetyPolicy($resolver), $transport, new UrlMasker);

        $result = $client->inspect('https://landing.example', [], 'MonitorTest/1.0');

        $this->assertSame(200, $result->statusCode);
        $this->assertNull($result->errorType);
        $this->assertNull($result->expectedTextFound);
    }

    public function test_secure_probe_preserves_retry_after_from_the_response(): void
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
            public function get(array $target, string $userAgent, int $timeoutMs): HopResponse
            {
                return new HopResponse(429, null, '', 1, null, null, null, 1800);
            }
        };
        $client = new SecureProbeClient(new UrlSafetyPolicy($resolver), $transport, new UrlMasker);

        $result = $client->inspect('https://landing.example', [], 'MonitorTest/1.0');

        $this->assertSame(429, $result->statusCode);
        $this->assertSame(1800, $result->retryAfterSeconds);
    }

    public function test_oversized_redirect_can_follow_its_location_header(): void
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
            public int $calls = 0;

            public function get(array $target, string $userAgent, int $timeoutMs): HopResponse
            {
                $this->calls++;

                return $this->calls === 1
                    ? new HopResponse(302, '/final', '<html>long redirect body', 1, 'body_too_large', 'body limit', null)
                    : new HopResponse(200, null, '<article>Campaign headline</article>', 1, null, null, null);
            }
        };
        $client = new SecureProbeClient(new UrlSafetyPolicy($resolver), $transport, new UrlMasker);

        $result = $client->inspect('https://landing.example', [], 'MonitorTest/1.0', 'Campaign headline');

        $this->assertSame(2, $transport->calls);
        $this->assertSame(['https://landing.example/final'], $result->redirects);
        $this->assertSame(200, $result->statusCode);
        $this->assertTrue($result->expectedTextFound);
    }

    public function test_fixture_probe_matches_expected_text_after_html_entity_decoding(): void
    {
        $result = (new FixtureProbeClient)->inspect(
            'https://fixture.test/html-entities',
            [],
            'MonitorTest/1.0',
            'Campaign & special offer',
        );

        $this->assertTrue($result->expectedTextFound);
    }

    public function test_expected_text_matcher_requires_all_present_terms_and_matches_any_absent_term(): void
    {
        $expectedText = "\n Campaign headline \n\nSpring sale\nCampaign headline\n";
        $body = '<article><p>Campaign</p><p>headline</p><p>Spring sale</p></article>';

        $this->assertSame("Campaign headline\nSpring sale", ExpectedTextMatcher::normalize($expectedText));
        $this->assertSame(['Campaign headline', 'Spring sale'], ExpectedTextMatcher::terms($expectedText));
        $this->assertTrue(ExpectedTextMatcher::matches($body, $expectedText));
        $this->assertFalse(ExpectedTextMatcher::matches('<p>Campaign headline</p>', $expectedText));
        $this->assertTrue(ExpectedTextMatcher::matches('<p>Public launch. Embargoed details.</p>', "Internal draft\nEmbargoed", 'absent'));
        $this->assertFalse(ExpectedTextMatcher::matches('<p>Public launch.</p>', "Internal draft\nEmbargoed", 'absent'));
    }

    public function test_oversized_response_only_confirms_text_when_the_bounded_prefix_proves_the_condition(): void
    {
        $makeProbe = static function (string $body): SecureProbeClient {
            $resolver = new class implements HostResolver
            {
                public function resolve(string $hostname): array
                {
                    return ['93.184.216.34'];
                }
            };
            $transport = new class($body) implements ProbeTransport
            {
                public function __construct(private readonly string $body) {}

                public function get(array $target, string $userAgent, int $timeoutMs): HopResponse
                {
                    return new HopResponse(200, null, $this->body, 40, 'body_too_large', '回應超過讀取上限。', null);
                }
            };

            return new SecureProbeClient(new UrlSafetyPolicy($resolver), $transport, new UrlMasker);
        };

        $allPresent = $makeProbe('<p>Campaign headline</p><p>Spring sale</p>')
            ->inspect('https://landing.example', [], 'MonitorTest/1.0', "Campaign headline\nSpring sale");
        $partialPresent = $makeProbe('<p>Campaign headline</p>')
            ->inspect('https://landing.example', [], 'MonitorTest/1.0', "Campaign headline\nSpring sale");
        $forbiddenFound = $makeProbe('<p>Embargoed</p>')
            ->inspect('https://landing.example', [], 'MonitorTest/1.0', "Internal draft\nEmbargoed", null, 'absent');
        $forbiddenNotSeen = $makeProbe('<p>Public launch</p>')
            ->inspect('https://landing.example', [], 'MonitorTest/1.0', "Internal draft\nEmbargoed", null, 'absent');

        $this->assertNull($allPresent->errorType);
        $this->assertTrue($allPresent->expectedTextFound);
        $this->assertSame('body_too_large', $partialPresent->errorType);
        $this->assertNull($partialPresent->expectedTextFound);
        $this->assertNull($forbiddenFound->errorType);
        $this->assertTrue($forbiddenFound->expectedTextFound);
        $this->assertSame('body_too_large', $forbiddenNotSeen->errorType);
        $this->assertNull($forbiddenNotSeen->expectedTextFound);
    }

    public function test_iana_special_use_ranges_are_blocked_for_literals_and_dns_answers(): void
    {
        $addresses = [
            '100.64.0.1', '192.0.0.1', '192.0.2.1', '192.31.196.1', '192.52.193.1',
            '192.88.99.1', '192.175.48.1', '198.18.0.1', '198.51.100.1', '203.0.113.1',
            '224.0.0.1', '240.0.0.1', '100::1', '100:0:0:1::1', '2001:2::1',
            '2001:db8::1', '2002::1', '3fff::1', '5f00::1', '64:ff9b::a00:1',
        ];

        foreach ($addresses as $address) {
            $resolver = new class($address) implements HostResolver
            {
                public function __construct(private readonly string $address) {}

                public function resolve(string $hostname): array
                {
                    return [$this->address];
                }
            };
            $transport = new class implements ProbeTransport
            {
                public int $calls = 0;

                public function get(array $target, string $userAgent, int $timeoutMs): HopResponse
                {
                    $this->calls++;

                    return new HopResponse(200, null, '', 1, null, null, null);
                }
            };
            $client = new SecureProbeClient(new UrlSafetyPolicy($resolver), $transport, new UrlMasker);

            $literal = $client->inspect((str_contains($address, ':') ? 'http://['.$address.']/' : 'http://'.$address.'/'), [], 'MonitorTest/1.0');
            $dnsAnswer = $client->inspect('https://landing.example', [], 'MonitorTest/1.0');

            $this->assertSame('blocked_ssrf', $literal->errorType, 'Direct IP was not blocked: '.$address);
            $this->assertSame('blocked_ssrf', $dnsAnswer->errorType, 'DNS answer was not blocked: '.$address);
            $this->assertSame(0, $transport->calls, 'A forbidden destination reached the transport: '.$address);
        }
    }

    public function test_loopback_and_unlisted_redirect_hosts_are_blocked_before_transport(): void
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
            public int $calls = 0;

            public function get(array $target, string $userAgent, int $timeoutMs): HopResponse
            {
                $this->calls++;

                return new HopResponse(302, 'https://other.example/private', '', 1, null, null, null);
            }
        };
        $client = new SecureProbeClient(new UrlSafetyPolicy($resolver), $transport, new UrlMasker);

        $loopback = $client->inspect('http://127.0.0.1/admin', [], 'MonitorTest/1.0');
        $redirect = $client->inspect('https://landing.example', [], 'MonitorTest/1.0');

        $this->assertSame('blocked_ssrf', $loopback->errorType);
        $this->assertSame('redirect_host_not_allowed', $redirect->errorType);
        $this->assertSame(1, $transport->calls);
    }

    public function test_unlisted_redirect_host_is_rejected_without_resolving_it(): void
    {
        $resolver = new class implements HostResolver
        {
            /** @var list<string> */
            public array $resolvedHosts = [];

            public function resolve(string $hostname): array
            {
                $this->resolvedHosts[] = $hostname;

                return ['93.184.216.34'];
            }
        };
        $transport = new class implements ProbeTransport
        {
            public int $calls = 0;

            public function get(array $target, string $userAgent, int $timeoutMs): HopResponse
            {
                $this->calls++;

                return new HopResponse(302, 'https://other.example/private', '', 1, null, null, null);
            }
        };
        $client = new SecureProbeClient(new UrlSafetyPolicy($resolver), $transport, new UrlMasker);

        $result = $client->inspect('https://landing.example', [], 'MonitorTest/1.0');

        $this->assertSame('redirect_host_not_allowed', $result->errorType);
        $this->assertSame(['landing.example'], $resolver->resolvedHosts);
        $this->assertSame(1, $transport->calls);
    }

    public function test_all_redirect_hops_share_one_overall_timeout_budget(): void
    {
        Config::set('monitoring.request_timeout_seconds', 2);
        $resolver = new class implements HostResolver
        {
            public int $calls = 0;

            public function resolve(string $hostname): array
            {
                $this->calls++;

                return ['93.184.216.34'];
            }
        };
        $transport = new class implements ProbeTransport
        {
            /** @var list<int> */
            public array $budgets = [];

            public function get(array $target, string $userAgent, int $timeoutMs): HopResponse
            {
                $this->budgets[] = $timeoutMs;

                if (count($this->budgets) === 1) {
                    usleep(1_100_000);
                } else {
                    usleep($timeoutMs * 1000);
                }

                return new HopResponse(302, '/hop-'.count($this->budgets), '', 1, null, null, null);
            }
        };
        $client = new SecureProbeClient(new UrlSafetyPolicy($resolver), $transport, new UrlMasker);

        $result = $client->inspect('https://landing.example/start', [], 'MonitorTest/1.0');

        $this->assertSame('timeout', $result->errorType);
        $this->assertCount(2, $transport->budgets);
        $this->assertGreaterThan($transport->budgets[1], $transport->budgets[0]);
        $this->assertLessThan(1000, $transport->budgets[1]);
        $this->assertSame(2, $resolver->calls);
    }
}
