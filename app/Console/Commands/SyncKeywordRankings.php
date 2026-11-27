<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\Organization;
use App\Services\ClientKeywordRankingSync;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

#[Signature('keywords:sync-rankings')]
#[Description('從 Search Console 更新各客戶追蹤關鍵字的每週平均排名。')]
class SyncKeywordRankings extends Command
{
    public function handle(ClientKeywordRankingSync $rankingSync): int
    {
        $now = CarbonImmutable::now();
        $keywordCount = 0;
        $failedClientCount = 0;

        Client::withoutGlobalScopes()
            ->whereHas('trackedKeywords')
            ->chunkById(50, function (Collection $clients) use ($rankingSync, $now, &$keywordCount, &$failedClientCount): void {
                foreach ($clients as $client) {
                    if (! Organization::isAccessibleForOwner($client->user_id === null ? null : (int) $client->user_id)) {
                        continue;
                    }

                    try {
                        $keywordCount += $rankingSync->sync($client, $now);
                    } catch (Throwable $exception) {
                        $failedClientCount++;
                        Log::warning('Keyword rankings could not be synced.', [
                            'client_id' => $client->getKey(),
                            'exception' => $exception::class,
                        ]);
                    }
                }
            });

        $this->info("已更新 {$keywordCount} 個關鍵字排名，{$failedClientCount} 個客戶失敗。");

        return $failedClientCount === 0 ? self::SUCCESS : self::FAILURE;
    }
}
