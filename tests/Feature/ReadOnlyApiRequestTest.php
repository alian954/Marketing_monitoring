<?php

namespace Tests\Feature;

use App\Services\ReadOnlyApiRequest;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class ReadOnlyApiRequestTest extends TestCase
{
    public function test_read_only_api_request_retries_a_rate_limit_and_honors_retry_after(): void
    {
        Http::fake([
            'https://metrics.example.test/report' => Http::sequence()
                ->push(['error' => ['message' => 'quota exceeded']], 429, ['Retry-After' => '1'])
                ->push(['rows' => []]),
        ]);

        $response = ReadOnlyApiRequest::forBearerToken('temporary-token', 10)
            ->get('https://metrics.example.test/report');

        $this->assertSame(200, $response->status());
        Http::assertSentCount(2);
        Http::assertSent(static fn (ClientRequest $request): bool => $request->hasHeader('Authorization', 'Bearer temporary-token'));
        Sleep::assertSequence([Sleep::for(1)->second()]);
    }

    public function test_read_only_api_request_retries_a_connection_failure(): void
    {
        Http::fake([
            'https://metrics.example.test/report' => Http::sequence()
                ->pushFailedConnection()
                ->push(['rows' => []]),
        ]);

        $response = ReadOnlyApiRequest::forBearerToken('temporary-token', 10)
            ->get('https://metrics.example.test/report');

        $this->assertSame(200, $response->status());
        Http::assertSentCount(2);
        Sleep::assertSequence([Sleep::for(250)->milliseconds()]);
    }

    public function test_read_only_api_request_does_not_retry_permission_errors(): void
    {
        Http::fake([
            'https://metrics.example.test/report' => Http::response(['error' => ['message' => 'permission denied']], 403),
        ]);

        $response = ReadOnlyApiRequest::forBearerToken('temporary-token', 10)
            ->get('https://metrics.example.test/report');

        $this->assertSame(403, $response->status());
        Http::assertSentCount(1);
        Sleep::assertNeverSlept();
    }

    public function test_read_only_api_request_does_not_wait_longer_than_its_retry_after_limit(): void
    {
        Http::fake([
            'https://metrics.example.test/report' => Http::response(
                ['error' => ['message' => 'quota exceeded']],
                429,
                ['Retry-After' => '5'],
            ),
        ]);

        $response = ReadOnlyApiRequest::forBearerToken('temporary-token', 10)
            ->get('https://metrics.example.test/report');

        $this->assertSame(429, $response->status());
        Http::assertSentCount(1);
        Sleep::assertNeverSlept();
    }
}
