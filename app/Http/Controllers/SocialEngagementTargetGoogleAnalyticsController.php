<?php

namespace App\Http\Controllers;

use App\Models\SocialEngagementTarget;
use App\Services\GoogleAnalyticsApiException;
use App\Services\GoogleAnalyticsCampaignReportSync;
use App\Services\GoogleAnalyticsClientPropertyResolver;
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
        GoogleAnalyticsCampaignReportSync $campaignReportSync,
        GoogleAnalyticsClientPropertyResolver $propertyResolver,
    ): RedirectResponse {
        $connection = $request->user()->googleAnalyticsConnection;

        if ($connection === null || ! is_string($connection->refresh_token) || $connection->refresh_token === '') {
            return $this->reportRedirect($socialEngagementTarget)
                ->withErrors(['google_analytics' => '請先連結 Google Analytics 帳戶。']);
        }

        $clientProperty = $propertyResolver->forTarget($connection, $socialEngagementTarget);

        if ($clientProperty === null) {
            return $this->reportRedirect($socialEngagementTarget)
                ->withErrors(['google_analytics' => '請先在 GA4 整合設定中為此客戶填入資源 ID。']);
        }

        if ($socialEngagementTarget->starts_on === null || $socialEngagementTarget->ends_on === null) {
            return $this->reportRedirect($socialEngagementTarget)
                ->withErrors(['google_analytics' => '請先為活動設定開始日與結束日，再同步 GA4 成效。']);
        }

        try {
            $accessToken = $dataApi->refreshAccessToken($connection->refresh_token, (int) $connection->workspace_id);
            $campaignReportSync->sync($connection, $socialEngagementTarget, $accessToken, $clientProperty->property_id);
        } catch (GoogleAnalyticsApiException $exception) {
            return $this->reportRedirect($socialEngagementTarget)
                ->withErrors(['google_analytics' => $exception->getMessage()]);
        } catch (ConnectionException) {
            return $this->reportRedirect($socialEngagementTarget)
                ->withErrors(['google_analytics' => '目前無法連線至 Google Analytics，請稍後再試。']);
        }

        return $this->reportRedirect($socialEngagementTarget)
            ->with('status', 'GA4 活動成效已同步。');
    }

    private function reportRedirect(SocialEngagementTarget $target): RedirectResponse
    {
        return redirect()->route('social-engagement-targets.report', $target);
    }
}
