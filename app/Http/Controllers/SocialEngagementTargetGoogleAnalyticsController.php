<?php

namespace App\Http\Controllers;

use App\Models\SocialEngagementTarget;
use App\Models\SocialEngagementTargetGoogleAnalyticsReport;
use App\Services\GoogleAnalyticsApiException;
use App\Services\GoogleAnalyticsDataApi;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SocialEngagementTargetGoogleAnalyticsController extends Controller
{
    public function sync(
        Request $request,
        SocialEngagementTarget $socialEngagementTarget,
        GoogleAnalyticsDataApi $dataApi,
    ): RedirectResponse {
        $connection = $request->user()->googleAnalyticsConnection;

        if ($connection === null || ! is_string($connection->refresh_token) || $connection->refresh_token === '') {
            return $this->reportRedirect($socialEngagementTarget)
                ->withErrors(['google_analytics' => '請先連結 Google Analytics 帳戶。']);
        }

        if (! is_string($connection->property_id) || $connection->property_id === '') {
            return $this->reportRedirect($socialEngagementTarget)
                ->withErrors(['google_analytics' => '請先在 GA4 整合設定中填入資源 ID。']);
        }

        if ($socialEngagementTarget->starts_on === null || $socialEngagementTarget->ends_on === null) {
            return $this->reportRedirect($socialEngagementTarget)
                ->withErrors(['google_analytics' => '請先為活動設定開始日與結束日，再同步 GA4 成效。']);
        }

        $campaignName = is_string($socialEngagementTarget->ga4_campaign_name)
            && trim($socialEngagementTarget->ga4_campaign_name) !== ''
                ? trim($socialEngagementTarget->ga4_campaign_name)
                : $socialEngagementTarget->tag;
        $sourceName = is_string($socialEngagementTarget->ga4_source_name)
            && trim($socialEngagementTarget->ga4_source_name) !== ''
                ? trim($socialEngagementTarget->ga4_source_name)
                : null;

        try {
            $accessToken = $dataApi->refreshAccessToken($connection->refresh_token);
            $metrics = $dataApi->campaignReport(
                $accessToken,
                $connection->property_id,
                $campaignName,
                $socialEngagementTarget->starts_on->toDateString(),
                $socialEngagementTarget->ends_on->toDateString(),
                $sourceName,
            );
        } catch (GoogleAnalyticsApiException $exception) {
            return $this->reportRedirect($socialEngagementTarget)
                ->withErrors(['google_analytics' => $exception->getMessage()]);
        } catch (ConnectionException) {
            return $this->reportRedirect($socialEngagementTarget)
                ->withErrors(['google_analytics' => '目前無法連線至 Google Analytics，請稍後再試。']);
        }

        SocialEngagementTargetGoogleAnalyticsReport::query()->updateOrCreate(
            [
                'google_analytics_connection_id' => $connection->getKey(),
                'social_engagement_target_id' => $socialEngagementTarget->getKey(),
            ],
            [
                'campaign_name' => $campaignName,
                'source_name' => $sourceName,
                'starts_on' => $socialEngagementTarget->starts_on->toDateString(),
                'ends_on' => $socialEngagementTarget->ends_on->toDateString(),
                ...$metrics,
                'synced_at' => now(),
            ],
        );

        return $this->reportRedirect($socialEngagementTarget)
            ->with('status', 'GA4 活動成效已同步。');
    }

    private function reportRedirect(SocialEngagementTarget $target): RedirectResponse
    {
        return redirect()->route('social-engagement-targets.report', $target);
    }
}
