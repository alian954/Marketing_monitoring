<?php

namespace Tests\Feature;

use App\Services\Probe\HostResolver;
use App\Services\Probe\SecureTlsCertificateProbe;
use App\Services\Probe\StreamTlsCertificateTransport;
use App\Services\Probe\TlsCertificateResult;
use App\Services\Probe\TlsCertificateTransport;
use App\Services\Probe\UrlSafetyPolicy;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class SecureTlsCertificateProbeTest extends TestCase
{
    public function test_probe_pins_a_public_dns_address_and_preserves_tls_server_name(): void
    {
        $resolver = new class implements HostResolver
        {
            public function resolve(string $hostname): array
            {
                return ['93.184.216.34'];
            }
        };
        $transport = new class implements TlsCertificateTransport
        {
            /** @var array{url: string, host: string, port: int, ips: list<string>}|null */
            public ?array $target = null;

            public ?int $timeout = null;

            public function inspect(array $target, int $timeoutSeconds): TlsCertificateResult
            {
                $this->target = $target;
                $this->timeout = $timeoutSeconds;

                return new TlsCertificateResult(new \DateTimeImmutable('2030-01-01T00:00:00+00:00'), null, null);
            }
        };
        Config::set('monitoring.request_timeout_seconds', 15);
        Config::set('monitoring.connect_timeout_seconds', 5);
        $probe = new SecureTlsCertificateProbe(new UrlSafetyPolicy($resolver), $transport);

        $result = $probe->inspect('https://certificate.example/path');

        $this->assertNotNull($transport->target);
        $this->assertSame('certificate.example', $transport->target['host']);
        $this->assertSame(['93.184.216.34'], $transport->target['ips']);
        $this->assertSame(5, $transport->timeout);
        $this->assertSame('2030-01-01', $result->expiresAt?->format('Y-m-d'));
    }

    public function test_probe_blocks_private_dns_answers_before_connecting(): void
    {
        $resolver = new class implements HostResolver
        {
            public function resolve(string $hostname): array
            {
                return ['93.184.216.34', '10.0.0.7'];
            }
        };
        $transport = new class implements TlsCertificateTransport
        {
            public int $calls = 0;

            public function inspect(array $target, int $timeoutSeconds): TlsCertificateResult
            {
                $this->calls++;

                return new TlsCertificateResult(null, null, null);
            }
        };
        $probe = new SecureTlsCertificateProbe(new UrlSafetyPolicy($resolver), $transport);

        $result = $probe->inspect('https://private-answer.example');

        $this->assertSame('blocked_ssrf', $result->errorType);
        $this->assertSame(0, $transport->calls);
    }

    public function test_http_urls_do_not_attempt_a_tls_connection(): void
    {
        $resolver = new class implements HostResolver
        {
            public function resolve(string $hostname): array
            {
                return ['93.184.216.34'];
            }
        };
        $transport = new class implements TlsCertificateTransport
        {
            public int $calls = 0;

            public function inspect(array $target, int $timeoutSeconds): TlsCertificateResult
            {
                $this->calls++;

                return new TlsCertificateResult(null, null, null);
            }
        };
        $probe = new SecureTlsCertificateProbe(new UrlSafetyPolicy($resolver), $transport);

        $result = $probe->inspect('http://certificate.example/path');

        $this->assertNull($result->expiresAt);
        $this->assertNull($result->errorType);
        $this->assertSame(0, $transport->calls);
    }

    public function test_stream_transport_reports_a_local_tls_handshake_failure(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorMessage);

        $this->assertIsResource($server);
        $address = stream_socket_get_name($server, false);
        $this->assertIsString($address);
        $port = (int) substr(strrchr($address, ':'), 1);
        $startedAt = hrtime(true);

        try {
            $result = (new StreamTlsCertificateTransport)->inspect([
                'url' => 'https://certificate.example/',
                'host' => 'certificate.example',
                'port' => $port,
                'ips' => ['127.0.0.1'],
            ], 1);
        } finally {
            fclose($server);
        }

        $this->assertSame('tls_handshake', $result->errorType);
        $this->assertNull($result->expiresAt);
        $elapsedSeconds = (hrtime(true) - $startedAt) / 1_000_000_000;
        $this->assertLessThan(3, $elapsedSeconds);
    }
}
