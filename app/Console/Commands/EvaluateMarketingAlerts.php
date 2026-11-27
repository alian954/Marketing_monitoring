<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Services\MarketingAlertEvaluator;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

#[Signature('marketing-alerts:evaluate')]
#[Description('依客戶的行銷警示規則檢查前一日廣告數據並通知。')]
class EvaluateMarketingAlerts extends Command
{
    public function handle(MarketingAlertEvaluator $evaluator): int
    {
        $now = CarbonImmutable::now();
        $alertCount = 0;
        $failedClientCount = 0;

        Client::withoutGlobalScopes()
            ->whereHas('marketingAlertRules', static fn (Builder $query): Builder => $query->whereNull('paused_at'))
            ->chunkById(50, function (Collection $clients) use ($evaluator, $now, &$alertCount, &$failedClientCount): void {
                foreach ($clients as $client) {
                    try {
                        $alertCount += $evaluator->evaluateClient($client, $now);
                    } catch (Throwable $exception) {
                        $failedClientCount++;
                        Log::warning('Marketing alerts could not be evaluated.', [
                            'client_id' => $client->getKey(),
                            'exception' => $exception::class,
                        ]);
                    }
                }
            });

        $this->info("已觸發 {$alertCount} 則行銷警示，{$failedClientCount} 個客戶檢查失敗。");

        return $failedClientCount === 0 ? self::SUCCESS : self::FAILURE;
    }
}
