<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientTrackedKeyword;
use App\Models\GoogleSearchConsoleClientSite;
use App\Models\GoogleSearchConsoleConnection;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;

/**
 * Records the weekly Search Console average position of each tracked keyword.
 *
 * Search Console finalises data about three days late (Pacific time), so each run measures the
 * seven days ending three days ago. A keyword with no impressions is stored with no position,
 * meaning it did not appear in Google results that week.
 */
class ClientKeywordRankingSync
{
    private const DATA_DELAY_DAYS = 3;

    private const WINDOW_DAYS = 7;

    public function __construct(private readonly GoogleSearchConsoleDataApi $dataApi) {}

    /**
     * @return array{starts_on: string, ends_on: string}
     */
    public static function window(CarbonImmutable $now): array
    {
        $endsOn = $now->setTimezone('America/Los_Angeles')->startOfDay()->subDays(self::DATA_DELAY_DAYS);

        return [
            'starts_on' => $endsOn->subDays(self::WINDOW_DAYS - 1)->toDateString(),
            'ends_on' => $endsOn->toDateString(),
        ];
    }

    /**
     * @return array{connection: GoogleSearchConsoleConnection, site_url: string}|null
     */
    public function siteFor(Client $client): ?array
    {
        $clientSite = GoogleSearchConsoleClientSite::withoutGlobalScopes()
            ->where('client_id', $client->getKey())
            ->first();
        $connection = $clientSite === null
            ? null
            : GoogleSearchConsoleConnection::withoutGlobalScopes()->find($clientSite->google_search_console_connection_id);

        if ($connection === null || ! is_string($clientSite->site_url) || $clientSite->site_url === '') {
            return null;
        }

        return ['connection' => $connection, 'site_url' => $clientSite->site_url];
    }

    /**
     * @throws GoogleSearchConsoleApiException When the client has no usable Search Console site.
     */
    public function sync(Client $client, CarbonImmutable $now): int
    {
        $keywords = ClientTrackedKeyword::query()->where('client_id', $client->getKey())->get();

        if ($keywords->isEmpty()) {
            return 0;
        }

        $site = $this->siteFor($client);

        if ($site === null) {
            throw new GoogleSearchConsoleApiException('此客戶尚未設定 Search Console 網站，請先到 Search Console 整合頁選擇網站。');
        }

        $window = self::window($now);

        try {
            $accessToken = $this->dataApi->refreshAccessToken(
                $site['connection']->refresh_token,
                $client->workspace_id === null ? null : (int) $client->workspace_id,
            );
            $metrics = $this->dataApi->keywordMetrics(
                $accessToken,
                $site['site_url'],
                $window['starts_on'],
                $window['ends_on'],
                $keywords->pluck('keyword')->all(),
            );
        } catch (GoogleSearchConsoleApiException|ConnectionException $exception) {
            $message = $exception instanceof GoogleSearchConsoleApiException ? $exception->getMessage() : '目前無法連線至 Google Search Console。';
            ClientTrackedKeyword::query()->whereKey($keywords->modelKeys())->update(['last_sync_error' => mb_substr($message, 0, 255)]);

            throw $exception instanceof GoogleSearchConsoleApiException ? $exception : new GoogleSearchConsoleApiException($message);
        }

        foreach ($keywords as $keyword) {
            $row = $metrics[mb_strtolower($keyword->keyword)] ?? null;
            $hasImpressions = $row !== null && $row['impressions'] > 0;
            $keyword->rankings()->updateOrCreate(
                ['measured_on' => $window['ends_on']],
                [
                    'position' => $hasImpressions ? round($row['position'], 2) : null,
                    'clicks' => $row === null ? 0 : (int) round($row['clicks']),
                    'impressions' => $row === null ? 0 : (int) round($row['impressions']),
                    'ctr_percent' => $hasImpressions ? round($row['ctr'] * 100, 2) : null,
                ],
            );
            $keyword->forceFill(['last_synced_at' => now(), 'last_sync_error' => null])->save();
        }

        return $keywords->count();
    }
}
