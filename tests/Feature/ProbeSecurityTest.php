<?php

namespace Tests\Feature;

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
        $this->assertSame(['93.184.216.34', '93.184.216.35'], $transport->targets[0]['ips']);
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
