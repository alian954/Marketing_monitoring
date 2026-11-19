<?php

namespace Tests\Unit;

use App\Services\Probe\RetryAfterParser;
use PHPUnit\Framework\TestCase;

class RetryAfterParserTest extends TestCase
{
    public function test_header_value_is_read_case_insensitively_and_ignores_other_headers(): void
    {
        $parser = new RetryAfterParser;

        $this->assertSame('120', $parser->headerValue("Retry-After:  120\r\n"));
        $this->assertSame('Fri, 20 Nov 2026 13:00:00 GMT', $parser->headerValue("retry-after: Fri, 20 Nov 2026 13:00:00 GMT\r\n"));
        $this->assertNull($parser->headerValue("X-Retry-After: 120\r\n"));
        $this->assertNull($parser->headerValue("Retry-Afterish: 120\r\n"));
    }

    public function test_numeric_delay_is_limited_to_one_day(): void
    {
        $parser = new RetryAfterParser;

        $this->assertSame(120, $parser->seconds('120'));
        $this->assertSame(RetryAfterParser::MAX_DELAY_SECONDS, $parser->seconds('999999999999999999999'));
        $this->assertSame(0, $parser->seconds('0'));
    }

    public function test_http_date_is_calculated_against_the_supplied_instant(): void
    {
        $parser = new RetryAfterParser;
        $asOf = new \DateTimeImmutable('2026-11-20T12:00:00+00:00');
        $retryAt = $asOf->modify('+1 hour');

        $this->assertSame(3600, $parser->seconds(gmdate(DATE_RFC7231, $retryAt->getTimestamp()), $asOf));
        $this->assertSame(
            RetryAfterParser::MAX_DELAY_SECONDS,
            $parser->seconds(gmdate(DATE_RFC7231, $asOf->modify('+2 days')->getTimestamp()), $asOf),
        );
    }

    public function test_invalid_negative_and_past_values_are_ignored(): void
    {
        $parser = new RetryAfterParser;
        $asOf = new \DateTimeImmutable('2026-11-20T12:00:00+00:00');

        $this->assertNull($parser->seconds(null, $asOf));
        $this->assertNull($parser->seconds('not-a-date', $asOf));
        $this->assertNull($parser->seconds('-10', $asOf));
        $this->assertNull($parser->seconds(gmdate(DATE_RFC7231, $asOf->modify('-1 second')->getTimestamp()), $asOf));
    }
}
