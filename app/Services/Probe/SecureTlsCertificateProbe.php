<?php

namespace App\Services\Probe;

class SecureTlsCertificateProbe implements TlsCertificateProbe
{
    public function __construct(
        private readonly UrlSafetyPolicy $policy,
        private readonly TlsCertificateTransport $transport,
    ) {}

    public function inspect(string $url): TlsCertificateResult
    {
        try {
            $target = $this->policy->validateAndResolve($url);
        } catch (ProbeException $exception) {
            return new TlsCertificateResult(null, $exception->type, $exception->getMessage());
        }

        if (! str_starts_with($target['url'], 'https://')) {
            return new TlsCertificateResult(null, null, null);
        }

        $timeoutSeconds = max(1, min(
            (int) config('monitoring.request_timeout_seconds'),
            (int) config('monitoring.connect_timeout_seconds'),
        ));

        unset($target['cnames']);

        return $this->transport->inspect($target, $timeoutSeconds);
    }
}
