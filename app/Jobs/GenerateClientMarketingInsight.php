<?php

namespace App\Jobs;

use App\Models\ClientMarketingInsight;
use App\Services\MarketingInsightException;
use App\Services\MarketingInsightWriter;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class GenerateClientMarketingInsight implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public int $uniqueFor = 600;

    public function __construct(public readonly int $insightId) {}

    public function uniqueId(): string
    {
        return (string) $this->insightId;
    }

    public function handle(MarketingInsightWriter $writer): void
    {
        $insight = ClientMarketingInsight::query()->find($this->insightId);

        if ($insight === null || ! $insight->isPending()) {
            return;
        }

        try {
            $result = $writer->write($insight->input_payload);
        } catch (MarketingInsightException $exception) {
            $this->markFailed($insight, $exception->getMessage());

            return;
        } catch (Throwable $exception) {
            Log::warning('AI marketing insight generation failed.', ['insight_id' => $insight->getKey(), 'exception' => $exception::class]);
            $this->markFailed($insight, 'AI 摘要產生失敗，請稍後再試。');

            return;
        }

        $insight->forceFill([
            'status' => ClientMarketingInsight::STATUS_COMPLETED,
            'content' => $result['content'],
            'model' => $result['model'],
            'input_tokens' => $result['input_tokens'],
            'output_tokens' => $result['output_tokens'],
            'error_message' => null,
            'completed_at' => now(),
        ])->save();
    }

    public function failed(?Throwable $exception): void
    {
        $insight = ClientMarketingInsight::query()->find($this->insightId);

        if ($insight !== null && $insight->isPending()) {
            $this->markFailed($insight, 'AI 摘要產生逾時或中斷，請稍後再試。');
        }
    }

    private function markFailed(ClientMarketingInsight $insight, string $message): void
    {
        $insight->forceFill([
            'status' => ClientMarketingInsight::STATUS_FAILED,
            'error_message' => mb_substr($message, 0, 500),
            'completed_at' => now(),
        ])->save();
    }
}
