<?php

namespace Tests\Feature;

use App\Jobs\GenerateClientMarketingInsight;
use App\Models\ClientMarketingAnnotation;
use App\Models\ClientMarketingInsight;
use App\Models\User;
use App\Models\WorkspaceMembership;
use App\Services\ClaudeMarketingInsightWriter;
use App\Services\MarketingInsightException;
use App\Services\MarketingInsightWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ClientMarketingInsightTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array<string, mixed>> */
    private array $receivedPayloads = [];

    private ?string $failureMessage = null;

    private bool $writerConfigured = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-10 12:00:00');
        $test = $this;
        $this->app->instance(MarketingInsightWriter::class, new class($test) implements MarketingInsightWriter
        {
            public function __construct(private ClientMarketingInsightTest $test) {}

            public function isConfigured(): bool
            {
                return $this->test->writerConfigured();
            }

            public function write(array $payload): array
            {
                return $this->test->fakeWrite($payload);
            }
        });
    }

    public function writerConfigured(): bool
    {
        return $this->writerConfigured;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function fakeWrite(array $payload): array
    {
        $this->receivedPayloads[] = $payload;

        if ($this->failureMessage !== null) {
            throw new MarketingInsightException($this->failureMessage);
        }

        return [
            'content' => [
                'summary' => '本期花費持平，ROAS 小幅上升。',
                'highlights' => ['Meta 購買成本下降 12%。'],
                'issues' => [['title' => '追蹤碼遺失', 'detail' => '11/5 官網缺少追蹤碼，當日轉換偏低。']],
                'recommendations' => ['把 10% 預算移到 Meta 再行銷。'],
            ],
            'model' => 'claude-opus-5-5',
            'input_tokens' => 1200,
            'output_tokens' => 300,
        ];
    }

    public function test_requesting_a_summary_captures_the_report_and_queues_generation(): void
    {
        Queue::fake();
        $owner = User::factory()->create();
        $client = $owner->clients()->create(['name' => 'AI 客戶']);
        ClientMarketingAnnotation::factory()->create(['client_id' => $client->id, 'occurred_on' => '2026-11-05', 'title' => '雙十一預熱']);

        $this->actingAs($owner)
            ->post(route('clients.insights.store', $client), ['from' => '2026-11-01', 'to' => '2026-11-09'])
            ->assertRedirect(route('clients.cross-platform', ['client' => $client, 'from' => '2026-11-01', 'to' => '2026-11-09']));

        $insight = $client->marketingInsights()->sole();
        $this->assertSame(ClientMarketingInsight::STATUS_PENDING, $insight->status);
        $this->assertSame('AI 客戶', $insight->input_payload['client_name']);
        $this->assertSame('雙十一預熱', $insight->input_payload['events'][0]['title']);
        $this->assertSame(['from' => '2026-10-23', 'to' => '2026-10-31'], $insight->input_payload['previous_period']);
        $this->assertStringNotContainsString('雙十一預熱', (string) DB::table('client_marketing_insights')->value('input_payload'), 'The captured payload is encrypted at rest.');
        Queue::assertPushed(GenerateClientMarketingInsight::class, fn (GenerateClientMarketingInsight $job): bool => $job->insightId === $insight->id);

        $this->post(route('clients.insights.store', $client), ['from' => '2026-11-01', 'to' => '2026-11-09'])
            ->assertSessionHas('status', 'AI 摘要產生中，請稍後重新整理頁面。');
        $this->assertSame(1, $client->marketingInsights()->count(), 'A pending summary for the same period is not duplicated.');

        $this->get(route('clients.cross-platform', ['client' => $client, 'from' => '2026-11-01', 'to' => '2026-11-09']))
            ->assertOk()
            ->assertSee('AI 摘要產生中');
    }

    public function test_job_stores_the_summary_and_the_overview_renders_it(): void
    {
        $owner = User::factory()->create();
        $client = $owner->clients()->create(['name' => 'AI 客戶']);
        $insight = $client->marketingInsights()->create([
            'from_on' => '2026-11-01',
            'to_on' => '2026-11-09',
            'status' => ClientMarketingInsight::STATUS_PENDING,
            'input_payload' => ['client_name' => 'AI 客戶'],
        ]);

        (new GenerateClientMarketingInsight($insight->id))->handle($this->app->make(MarketingInsightWriter::class));

        $insight->refresh();
        $this->assertSame(ClientMarketingInsight::STATUS_COMPLETED, $insight->status);
        $this->assertSame(1200, $insight->input_tokens);
        $this->assertSame([['client_name' => 'AI 客戶']], $this->receivedPayloads);

        $this->actingAs($owner)
            ->get(route('clients.cross-platform', ['client' => $client, 'from' => '2026-11-01', 'to' => '2026-11-09']))
            ->assertOk()
            ->assertSee('本期花費持平，ROAS 小幅上升。')
            ->assertSee('追蹤碼遺失')
            ->assertSee('把 10% 預算移到 Meta 再行銷。');
    }

    public function test_job_records_writer_failures(): void
    {
        $this->failureMessage = 'Claude API 目前請求過多，請稍後再試。';
        $owner = User::factory()->create();
        $client = $owner->clients()->create(['name' => 'AI 客戶']);
        $insight = $client->marketingInsights()->create([
            'from_on' => '2026-11-01',
            'to_on' => '2026-11-09',
            'status' => ClientMarketingInsight::STATUS_PENDING,
            'input_payload' => [],
        ]);

        (new GenerateClientMarketingInsight($insight->id))->handle($this->app->make(MarketingInsightWriter::class));

        $this->assertSame(ClientMarketingInsight::STATUS_FAILED, $insight->refresh()->status);
        $this->actingAs($owner)
            ->get(route('clients.cross-platform', ['client' => $client, 'from' => '2026-11-01', 'to' => '2026-11-09']))
            ->assertSee('Claude API 目前請求過多，請稍後再試。');
    }

    public function test_requests_are_rejected_without_credentials_for_read_only_members_and_other_workspaces(): void
    {
        Queue::fake();
        $owner = User::factory()->create();
        $client = $owner->clients()->create(['name' => 'AI 客戶']);
        $member = User::factory()->create();
        WorkspaceMembership::factory()->create([
            'owner_user_id' => $owner->id,
            'member_user_id' => $member->id,
            'role' => WorkspaceMembership::ROLE_READ_ONLY,
            'client_scope' => WorkspaceMembership::CLIENT_SCOPE_ALL,
        ]);

        $this->actingAs($member)
            ->post(route('clients.insights.store', $client), ['from' => '2026-11-01', 'to' => '2026-11-09'])
            ->assertForbidden();
        $this->actingAs(User::factory()->create())
            ->post(route('clients.insights.store', $client), ['from' => '2026-11-01', 'to' => '2026-11-09'])
            ->assertNotFound();

        $this->writerConfigured = false;
        $this->actingAs($owner)
            ->post(route('clients.insights.store', $client), ['from' => '2026-11-01', 'to' => '2026-11-09'])
            ->assertSessionHasErrors('insight');
        $this->post(route('clients.insights.store', $client), ['from' => '2026-11-01', 'to' => '2026-11-10'])
            ->assertSessionHasErrors('insight');

        $this->assertDatabaseCount('client_marketing_insights', 0);
        Queue::assertNothingPushed();
    }

    public function test_claude_writer_requires_an_api_key(): void
    {
        config(['services.anthropic.api_key' => null]);
        $writer = new ClaudeMarketingInsightWriter;

        $this->assertFalse($writer->isConfigured());
        $this->expectException(MarketingInsightException::class);
        $writer->write([]);
    }
}
