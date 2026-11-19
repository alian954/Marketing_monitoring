<?php

namespace Tests\Feature;

use App\Services\Probe\DomainExpiryProbe;
use App\Services\Probe\DomainExpiryResult;
use App\Services\Probe\HeaderAwareProbeTransport;
use App\Services\Probe\HopResponse;
use App\Services\Probe\HostResolver;
use App\Services\Probe\UrlSafetyPolicy;
use Closure;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class DomainExpiryProbeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget('monitoring.rdap.dns-bootstrap.v1');
    }

    public function test_probe_follows_rdap_bootstrap_and_falls_back_to_registered_parent_domain(): void
    {
        $requestedUrls = [];
        $bootstrap = $this->bootstrapResponse([[['com'], ['https://rdap.verisign.test/com/v1/']]]);
        $responses = [
            'https://data.iana.org/rdap/dns.json' => $bootstrap,
            'https://rdap.verisign.test/com/v1/domain/www.example.com' => $this->response(404, ''),
            'https://rdap.verisign.test/com/v1/domain/example.com' => $this->response(200, json_encode([
                'ldhName' => 'example.com',
                'events' => [['eventAction' => 'expiration', 'eventDate' => '2027-03-10T12:00:00Z']],
            ], JSON_THROW_ON_ERROR)),
        ];
        $probe = $this->makeProbe(
            fn (string $url): HopResponse => $responses[$url] ?? $this->response(404, ''),
            static fn (string $hostname): array => ['93.184.216.34'],
            $requestedUrls,
        );

        $result = $probe->inspect('https://www.example.com/landing');
        $cachedResult = $probe->inspect('https://www.example.com/landing');

        $this->assertInstanceOf(DomainExpiryResult::class, $result);
        $this->assertTrue($result->succeeded());
        $this->assertSame('example.com', $result->domainName);
        $this->assertSame('2027-03-10T12:00:00+00:00', $result->expiresAt?->format(DATE_ATOM));
        $this->assertEquals($result->expiresAt, $cachedResult->expiresAt);
        $this->assertSame(1, count(array_filter($requestedUrls, static fn (string $url): bool => $url === 'https://data.iana.org/rdap/dns.json')));
        $this->assertContains('https://rdap.verisign.test/com/v1/domain/example.com', $requestedUrls);
    }

    public function test_probe_returns_unknown_for_tlds_without_an_iana_rdap_service(): void
    {
        $requestedUrls = [];
        $probe = $this->makeProbe(
            fn (string $url): HopResponse => $url === 'https://data.iana.org/rdap/dns.json'
                ? $this->bootstrapResponse([[['com'], ['https://rdap.verisign.test/com/v1/']]])
                : $this->response(404, ''),
            static fn (string $hostname): array => ['93.184.216.34'],
            $requestedUrls,
        );

        $result = $probe->inspect('https://example.invalid/');

        $this->assertFalse($result->succeeded());
        $this->assertSame('rdap_unsupported', $result->errorType);
        $this->assertCount(1, $requestedUrls);
    }

    public function test_probe_accepts_an_authoritative_parent_domain_for_a_subdomain_query(): void
    {
        $requestedUrls = [];
        $probe = $this->makeProbe(
            fn (string $url): HopResponse => $url === 'https://data.iana.org/rdap/dns.json'
                ? $this->bootstrapResponse([[['com'], ['https://rdap.verisign.test/com/v1/']]])
                : $this->response(200, json_encode([
                    'ldhName' => 'example.com',
                    'events' => [['eventAction' => 'expiration', 'eventDate' => '2027-03-10T12:00:00Z']],
                ], JSON_THROW_ON_ERROR)),
            static fn (string $hostname): array => ['93.184.216.34'],
            $requestedUrls,
        );

        $result = $probe->inspect('https://www.example.com/');

        $this->assertTrue($result->succeeded());
        $this->assertSame('example.com', $result->domainName);
        $this->assertCount(2, $requestedUrls);
    }

    public function test_probe_does_not_use_http_only_registry_endpoints(): void
    {
        $requestedUrls = [];
        $probe = $this->makeProbe(
            fn (string $url): HopResponse => $this->bootstrapResponse([[['com'], ['http://rdap.verisign.test/com/v1/']]]),
            static fn (string $hostname): array => ['93.184.216.34'],
            $requestedUrls,
        );

        $result = $probe->inspect('https://example.com/');

        $this->assertSame('rdap_bootstrap_unavailable', $result->errorType);
        $this->assertCount(1, $requestedUrls);
    }

    public function test_probe_rejects_rdap_responses_for_a_different_domain(): void
    {
        $requestedUrls = [];
        $probe = $this->makeProbe(
            fn (string $url): HopResponse => $url === 'https://data.iana.org/rdap/dns.json'
                ? $this->bootstrapResponse([[['com'], ['https://rdap.verisign.test/com/v1/']]])
                : $this->response(200, json_encode([
                    'ldhName' => 'attacker.com',
                    'events' => [['eventAction' => 'expiration', 'eventDate' => '2027-03-10T12:00:00Z']],
                ], JSON_THROW_ON_ERROR)),
            static fn (string $hostname): array => ['93.184.216.34'],
            $requestedUrls,
        );

        $result = $probe->inspect('https://example.com/');

        $this->assertSame('rdap_domain_mismatch', $result->errorType);
        $this->assertNull($result->expiresAt);
    }

    public function test_probe_does_not_send_requests_to_private_rdap_hosts(): void
    {
        $requestedUrls = [];
        $probe = $this->makeProbe(
            fn (string $url): HopResponse => $this->bootstrapResponse([[['com'], ['https://rdap.internal.test/v1/']]]),
            static fn (string $hostname): array => $hostname === 'data.iana.org' ? ['93.184.216.34'] : ['127.0.0.1'],
            $requestedUrls,
        );

        $result = $probe->inspect('https://example.com/');

        $this->assertSame('rdap_unavailable', $result->errorType);
        $this->assertSame(['https://data.iana.org/rdap/dns.json'], $requestedUrls);
    }

    public function test_probe_reports_when_rdap_does_not_publish_an_expiration_event(): void
    {
        $requestedUrls = [];
        $probe = $this->makeProbe(
            fn (string $url): HopResponse => $url === 'https://data.iana.org/rdap/dns.json'
                ? $this->bootstrapResponse([[['com'], ['https://rdap.verisign.test/com/v1/']]])
                : $this->response(200, json_encode(['ldhName' => 'example.com', 'events' => []], JSON_THROW_ON_ERROR)),
            static fn (string $hostname): array => ['93.184.216.34'],
            $requestedUrls,
        );

        $result = $probe->inspect('https://www.example.com/');

        $this->assertSame('rdap_expiration_unavailable', $result->errorType);
        $this->assertSame('example.com', $result->domainName);
        $this->assertNull($result->expiresAt);
    }

    public function test_probe_stops_after_a_transient_registry_failure_instead_of_querying_parent_names(): void
    {
        $requestedUrls = [];
        $probe = $this->makeProbe(
            fn (string $url): HopResponse => $url === 'https://data.iana.org/rdap/dns.json'
                ? $this->bootstrapResponse([[['com'], ['https://rdap.verisign.test/com/v1/']]])
                : $this->response(429, ''),
            static fn (string $hostname): array => ['93.184.216.34'],
            $requestedUrls,
        );

        $result = $probe->inspect('https://www.example.com/');

        $this->assertSame('rdap_unavailable', $result->errorType);
        $this->assertCount(2, $requestedUrls);
    }

    /**
     * @param  array<array{0: list<string>, 1: list<string>}>  $services
     */
    private function bootstrapResponse(array $services): HopResponse
    {
        return $this->response(200, json_encode(['services' => $services], JSON_THROW_ON_ERROR));
    }

    private function response(int $statusCode, string $body): HopResponse
    {
        return new HopResponse($statusCode, null, $body, 10, null, null, null);
    }

    /**
     * @param  Closure(string): HopResponse  $responseForUrl
     * @param  Closure(string): list<string>  $ipsForHost
     * @param  list<string>  $requestedUrls
     */
    private function makeProbe(Closure $responseForUrl, Closure $ipsForHost, array &$requestedUrls): DomainExpiryProbe
    {
        $resolver = $this->mock(HostResolver::class);
        $resolver->shouldReceive('resolve')->andReturnUsing($ipsForHost);

        $transport = $this->mock(HeaderAwareProbeTransport::class);
        $transport->shouldReceive('getWithHeaders')->andReturnUsing(function (array $target) use ($responseForUrl, &$requestedUrls): HopResponse {
            $requestedUrls[] = $target['url'];

            return $responseForUrl($target['url']);
        });

        return new DomainExpiryProbe(new UrlSafetyPolicy($resolver), $transport);
    }
}
