<?php

namespace App\Http\Controllers;

use App\Models\SocialEngagementTarget;
use App\Services\SocialEngagementCampaignReport;
use Illuminate\View\View;

class SocialEngagementCampaignReportController extends Controller
{
    public function show(
        SocialEngagementTarget $socialEngagementTarget,
        SocialEngagementCampaignReport $campaignReport,
    ): View {
        return view('social-engagement-reports.show', $campaignReport->build($socialEngagementTarget));
    }
}
