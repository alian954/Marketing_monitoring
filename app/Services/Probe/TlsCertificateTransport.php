<?php

namespace App\Services\Probe;

interface TlsCertificateTransport
{
    /** @param array{url: string, host: string, port: int, ips: list<string>} $target */
    public function inspect(array $target, int $timeoutSeconds): TlsCertificateResult;
}
