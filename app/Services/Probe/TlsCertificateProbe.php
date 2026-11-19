<?php

namespace App\Services\Probe;

interface TlsCertificateProbe
{
    public function inspect(string $url): TlsCertificateResult;
}
