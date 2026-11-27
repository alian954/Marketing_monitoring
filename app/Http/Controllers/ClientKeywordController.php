<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientKeywordRanking;
use App\Models\ClientTrackedKeyword;
use App\Services\ClientKeywordRankingSync;
use App\Services\GoogleSearchConsoleApiException;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;

class ClientKeywordController extends Controller
{
    private const MAX_KEYWORDS_PER_CLIENT = 100;

    private const HISTORY_WEEKS = 8;

    public function index(Client $client, ClientKeywordRankingSync $rankingSync): Response
    {
        $keywords = $client->trackedKeywords()
            ->with(['rankings' => static fn ($query) => $query->orderByDesc('measured_on')->limit(self::HISTORY_WEEKS * 200)])
            ->orderBy('keyword')
            ->get();
        $rows = $keywords->map(static function (ClientTrackedKeyword $keyword): array {
            $history = $keyword->rankings->sortByDesc('measured_on')->take(self::HISTORY_WEEKS)->values();
            $latest = $history->first();
            $previous = $history->get(1);
            $latestPosition = $latest?->position === null ? null : (float) $latest->position;
            $previousPosition = $previous?->position === null ? null : (float) $previous->position;

            return [
                'keyword' => $keyword,
                'latest' => $latest,
                'change' => $latestPosition !== null && $previousPosition !== null ? round($previousPosition - $latestPosition, 1) : null,
                'meets_target' => $keyword->target_position !== null && $latestPosition !== null ? $latestPosition <= $keyword->target_position : null,
                'history' => $history->reverse()->values()->map(static fn (ClientKeywordRanking $ranking): array => [
                    'measured_on' => $ranking->measured_on->toDateString(),
                    'position' => $ranking->position === null ? null : (float) $ranking->position,
                ])->all(),
            ];
        });

        return response()
            ->view('clients.keywords', [
                'client' => $client,
                'rows' => $rows,
                'hasSearchConsoleSite' => $rankingSync->siteFor($client) !== null,
                'window' => ClientKeywordRankingSync::window(CarbonImmutable::now()),
                'maxKeywords' => self::MAX_KEYWORDS_PER_CLIENT,
            ])
            ->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, Client $client): RedirectResponse
    {
        $keywords = collect(preg_split('/[\r\n,，]+/u', (string) $request->input('keywords', '')) ?: [])
            ->map(static fn (string $keyword): string => mb_strtolower(trim(preg_replace('/\s+/u', ' ', $keyword) ?? '')))
            ->filter(static fn (string $keyword): bool => $keyword !== '')
            ->unique()
            ->values();
        $existing = $client->trackedKeywords()->pluck('keyword')->all();
        $newKeywords = $keywords->reject(static fn (string $keyword): bool => in_array($keyword, $existing, true))->values();
        $validator = Validator::make([
            'keywords' => $keywords->all(),
            'target_position' => $request->input('target_position') === '' ? null : $request->input('target_position'),
        ], [
            'keywords' => ['required', 'array', 'min:1'],
            'keywords.*' => ['string', 'max:150'],
            'target_position' => ['nullable', 'integer', 'min:1', 'max:100'],
        ], [
            'keywords.required' => '請輸入至少一個關鍵字。',
            'keywords.min' => '請輸入至少一個關鍵字。',
            'keywords.*.max' => '單一關鍵字不得超過 150 個字。',
            'target_position.*' => '目標排名必須是 1 到 100 的整數。',
        ]);
        $validator->after(function ($validator) use ($existing, $newKeywords): void {
            if (count($existing) + $newKeywords->count() > self::MAX_KEYWORDS_PER_CLIENT) {
                $validator->errors()->add('keywords', '每個客戶最多追蹤 '.self::MAX_KEYWORDS_PER_CLIENT.' 個關鍵字。');
            }
        });

        if ($validator->fails()) {
            return redirect()->route('clients.keywords.index', $client)->withErrors($validator)->withInput();
        }

        $targetPosition = $validator->validated()['target_position'] ?? null;

        foreach ($newKeywords as $keyword) {
            $client->trackedKeywords()->create(['keyword' => $keyword, 'target_position' => $targetPosition]);
        }

        return redirect()
            ->route('clients.keywords.index', $client)
            ->with('status', '已新增 '.$newKeywords->count().' 個追蹤關鍵字'.($keywords->count() > $newKeywords->count() ? '（已略過重複的關鍵字）' : '').'。');
    }

    public function refresh(Client $client, ClientKeywordRankingSync $rankingSync): RedirectResponse
    {
        try {
            $count = $rankingSync->sync($client, CarbonImmutable::now());
        } catch (GoogleSearchConsoleApiException $exception) {
            return redirect()->route('clients.keywords.index', $client)->withErrors(['refresh' => $exception->getMessage()]);
        }

        return redirect()->route('clients.keywords.index', $client)->with('status', '已更新 '.$count.' 個關鍵字的排名。');
    }

    public function destroy(Client $client, ClientTrackedKeyword $trackedKeyword): RedirectResponse
    {
        $trackedKeyword->delete();

        return redirect()->route('clients.keywords.index', $client)->with('status', '已停止追蹤「'.$trackedKeyword->keyword.'」。');
    }
}
