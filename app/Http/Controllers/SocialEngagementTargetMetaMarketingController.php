<?php

namespace App\Http\Controllers;

use App\Models\SocialEngagementTarget;
use App\Services\MetaMarketingApiException;
use App\Services\MetaMarketingCampaignReportSync;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SocialEngagementTargetMetaMarketingController extends Controller
{
    public function sync(
        Request $request,
        SocialEngagementTarget $socialEngagementTarget,
        MetaMarketingCampaignReportSync $campaignReportSync,
    ): RedirectResponse {
        $connection = $request->user()->metaMarketingConnection;

        if ($connection === null || ! is_string($connection->access_token) || $connection->access_token === '') {
            return $this->reportRedirect($socialEngagementTarget)
                ->withErrors(['meta_marketing' => '請先連結 Meta 帳戶。']);
        }

        if ($connection->token_expires_at !== null && $connection->token_expires_at->isPast()) {
            return $this->reportRedirect($socialEngagementTarget)
                ->withErrors(['meta_marketing' => 'Meta 授權已到期，請重新連結帳戶。']);
        }

        if ($socialEngagementTarget->meta_campaign_id === null) {
            return $this->reportRedirect($socialEngagementTarget)
                ->withErrors(['meta_marketing' => '請先在活動互動目標設定 Meta 活動 ID。']);
        }

        if ($socialEngagementTarget->starts_on === null || $socialEngagementTarget->ends_on === null) {
            return $this->reportRedirect($socialEngagementTarget)
                ->withErrors(['meta_marketing' => '請先設定活動開始日與結束日，再同步 Meta Ads 數據。']);
        }

        try {
            $campaignReportSync->sync($connection, $socialEngagementTarget);
        } catch (MetaMarketingApiException $exception) {
            return $this->reportRedirect($socialEngagementTarget)
                ->withErrors(['meta_marketing' => $exception->getMessage()]);
        } catch (ConnectionException) {
            return $this->reportRedirect($socialEngagementTarget)
                ->withErrors(['meta_marketing' => '目前無法連線至 Meta，請稍後再試。']);
        }

        return $this->reportRedirect($socialEngagementTarget)
            ->with('status', 'Meta Ads 活動成效已同步。');
    }

    private function reportRedirect(SocialEngagementTarget $target): RedirectResponse
    {
        return redirect()->route('social-engagement-targets.report', $target);
    }
}
