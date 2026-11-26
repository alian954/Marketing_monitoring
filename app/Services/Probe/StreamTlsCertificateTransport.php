<?php

namespace App\Services\Probe;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

class StreamTlsCertificateTransport implements TlsCertificateTransport
{
    public function inspect(array $target, int $timeoutSeconds): TlsCertificateResult
    {
        $ip = $target['ips'][0];
        $socketIp = str_contains($ip, ':') ? '['.$ip.']' : $ip;
        $timeoutSeconds = max(1, $timeoutSeconds);
        $context = stream_context_create([
            'ssl' => [
                'capture_peer_cert' => true,
                'verify_peer' => false,
                'verify_peer_name' => false,
                'peer_name' => $target['host'],
                'SNI_enabled' => true,
            ],
        ]);
        $connection = @stream_socket_client(
            'tcp://'.$socketIp.':'.$target['port'],
            $errorNumber,
            $errorMessage,
            $timeoutSeconds,
            STREAM_CLIENT_CONNECT,
            $context,
        );

        if ($connection === false) {
            return new TlsCertificateResult(null, 'tls_handshake', 'TLS 交握失敗或無法連接至憑證主機。');
        }

        try {
            if (! stream_set_timeout($connection, $timeoutSeconds)
                || @stream_socket_enable_crypto($connection, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) !== true) {
                return new TlsCertificateResult(null, 'tls_handshake', 'TLS 交握失敗或無法連接至憑證主機。');
            }

            $parameters = stream_context_get_params($connection);
            $certificate = $parameters['options']['ssl']['peer_certificate'] ?? null;

            if (! $certificate instanceof \OpenSSLCertificate) {
                return new TlsCertificateResult(null, 'tls_handshake', 'TLS 交握未提供伺服器憑證。');
            }

            $parsed = openssl_x509_parse($certificate);
            $expiresAt = $parsed['validTo_time_t'] ?? null;

            if (! is_int($expiresAt) && ! ctype_digit((string) $expiresAt)) {
                return new TlsCertificateResult(null, 'tls_handshake', '無法讀取伺服器憑證到期時間。');
            }

            $expiration = new DateTimeImmutable('@'.(int) $expiresAt);

            return new TlsCertificateResult($expiration->setTimezone(new DateTimeZone('UTC')), null, null);
        } catch (Throwable) {
            return new TlsCertificateResult(null, 'tls_handshake', '無法讀取伺服器憑證。');
        } finally {
            fclose($connection);
        }
    }
}
