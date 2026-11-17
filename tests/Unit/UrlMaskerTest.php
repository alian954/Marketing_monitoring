<?php

namespace Tests\Unit;

use App\Services\UrlMasker;
use PHPUnit\Framework\TestCase;

class UrlMaskerTest extends TestCase
{
    public function test_masks_sensitive_query_values_and_preserves_non_sensitive_parameters(): void
    {
        $masker = new UrlMasker;

        $masked = $masker->mask('https://landing.example/path?email=private%40example.test&campaign=spring&api_key=secret');

        $this->assertSame('https://landing.example/path?email=REDACTED&campaign=spring&api_key=REDACTED', $masked);
    }
}
