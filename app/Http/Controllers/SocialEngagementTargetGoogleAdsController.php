<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateSocialEngagementTargetGoogleAdsCampaignRequest;
use App\Models\SocialEngagementTarget;
use App\Services\GoogleAdsApiException;
use App\Services\GoogleAdsCampaignReportSync;
use App\Services\GoogleAdsClientAccountResolver;
use App\Services\GoogleAdsDataApi;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SocialEngagementTargetGoogleAdsController extends Controller
{
    public function campaignOptions(
        Request $request,
        SocialEngagementTarget $socialEngagementTarget,
        GoogleAdsDataApi $dataApi,
        GoogleAdsClientAccountResolver $clientAccountResolver,
    ): RedirectResponse {
        $connection = $request->user()->googleAdsConnection;

        if ($connection === null || ! is_string($connection->refresh_token) || $connection->refresh_token === '') {
            return $this->reportRedirect($socialEngagementTarget)
                ->withErrors(['google_ads' => '請先連結 Google Ads 帳戶。']);
        }

        $clientAccount = $clientAccountResolver->forTarget($connection, $socialEngagementTarget);

        if ($clientAccount === null) {
            return $this->reportRedirect($socialEngagementTarget)
                ->withErrors(['google_ads' => '請先在 Google Ads 整合設定此客戶的帳戶。']);
        }

        try {
            $accessToken = $dataApi->refreshAccessToken($connection->refresh_token);
            $campaigns = $dataApi->campaigns(
                $accessToken,
                $clientAccount->customer_id,
                $clientAccount->login_customer_id,
            );
        } catch (GoogleAdsApiException $exception) {
            return $this->reportRedirect($socialEngagementTarget)
                ->withErrors(['google_ads' => $exception->getMessage()]);
        } catch (ConnectionException) {
            return $this->reportRedirect($socialEngagementTarget)
                ->withErrors(['google_ads' => '目前無法連線至 Google Ads，請稍後再試。']);
        }

        return $this->reportRedirect($socialEngagementTarget)
            ->with('google_ads_campaign_options', [
                'target_id' => $socialEngagementTarget->getKey(),
                'campaigns' => $campaigns,
            ]);
    }

    public function updateCampaign(
        UpdateSocialEngagementTargetGoogleAdsCampaignRequest $request,
        SocialEngagementTarget $socialEngagementTarget,
    ): RedirectResponse {
        $socialEngagementTarget->update($request->validated());

        return $this->reportRedirect($socialEngagementTarget)
            ->with('status', 'Google Ads 活動已套用至此活動目標。');
    }

    public function sync(
        Request $request,
        SocialEngagementTarget $socialEngagementTarget,
        GoogleAdsDataApi $dataApi,
        GoogleAdsCampaignReportSync $campaignReportSync,
        GoogleAdsClientAccountResolver $clientAccountResolver,
    ): RedirectResponse {
        $connection = $request->user()->googleAdsConnection;

        if ($connection === null || ! is_string($connection->refresh_token) || $connection->refresh_token === '') {
            return $this->reportRedirect($socialEngagementTarget)
                ->withErrors(['google_ads' => '請先連結 Google Ads 帳戶。']);
        }

        if ($clientAccountResolver->forTarget($connection, $socialEngagementTarget) === null) {
            return $this->reportRedirect($socialEngagementTarget)
                ->withErrors(['google_ads' => '請先在 Google Ads 整合設定此客戶的帳戶。']);
        }

        if ($socialEngagementTarget->google_ads_campaign_id === null) {
            return $this->reportRedirect($socialEngagementTarget)
                ->withErrors(['google_ads' => '請先選擇 Google Ads 活動，或在活動互動目標設定活動 ID。']);
        }

        if ($socialEngagementTarget->starts_on === null || $socialEngagementTarget->ends_on === null) {
            return $this->reportRedirect($socialEngagementTarget)
                ->withErrors(['google_ads' => '請先設定活動開始日與結束日，再同步 Google Ads 數據。']);
        }

        try {
            $accessToken = $dataApi->refreshAccessToken($connection->refresh_token);
            $campaignReportSync->sync($connection, $socialEngagementTarget, $accessToken);
        } catch (GoogleAdsApiException $exception) {
            return $this->reportRedirect($socialEngagementTarget)
                ->withErrors(['google_ads' => $exception->getMessage()]);
        } catch (ConnectionException) {
            return $this->reportRedirect($socialEngagementTarget)
                ->withErrors(['google_ads' => '目前無法連線至 Google Ads，請稍後再試。']);
        }

        return $this->reportRedirect($socialEngagementTarget)
            ->with('status', 'Google Ads 活動成效已同步。');
    }

    private function reportRedirect(SocialEngagementTarget $target): RedirectResponse
    {
        return redirect()->route('social-engagement-targets.report', $target);
    }
}
