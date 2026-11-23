<?php

namespace App\Http\Controllers;

use App\Models\GoogleAdsConnection;
use App\Models\GoogleAnalyticsConnection;
use App\Models\MetaMarketingConnection;
use App\Models\SocialEngagementTarget;
use App\Models\SocialEngagementTargetGoogleAdsReport;
use App\Models\SocialEngagementTargetGoogleAnalyticsReport;
use App\Models\SocialEngagementTargetMetaMarketingReport;
use App\Services\CampaignDailyMarketingEfficiencyReport;
use App\Services\CampaignDailyMarketingPerformanceReport;
use App\Services\CampaignDailyMarketingReportCsv;
use App\Services\CampaignDailyMarketingTrendChart;
use App\Services\CampaignMarketingEfficiencyReport;
use App\Services\CampaignMarketingReportCsv;
use App\Services\CsvFormulaSanitizer;
use App\Services\GoogleAdsAuctionInsightPerformanceReport;
use App\Services\GoogleAdsAuctionInsightReportCsv;
use App\Services\GoogleAdsDeliveryUnitPerformanceReport;
use App\Services\GoogleAdsDeliveryUnitReportCsv;
use App\Services\GoogleAdsDevicePerformanceReport;
use App\Services\GoogleAdsDeviceReportCsv;
use App\Services\GoogleAdsKeywordPerformanceReport;
use App\Services\GoogleAdsKeywordReportCsv;
use App\Services\GoogleAdsSearchTermPerformanceReport;
use App\Services\GoogleAdsSearchTermReportCsv;
use App\Services\GoogleAnalyticsAdContentPerformanceReport;
use App\Services\GoogleAnalyticsAdContentReportCsv;
use App\Services\GoogleAnalyticsCampaignTrendReport;
use App\Services\GoogleAnalyticsEventNamePerformanceReport;
use App\Services\GoogleAnalyticsEventNameReportCsv;
use App\Services\GoogleAnalyticsItemPerformanceReport;
use App\Services\GoogleAnalyticsItemReportCsv;
use App\Services\GoogleAnalyticsLandingPagePerformanceReport;
use App\Services\GoogleAnalyticsLandingPageReportCsv;
use App\Services\MetaMarketingAdPerformanceReport;
use App\Services\MetaMarketingAdReportCsv;
use App\Services\MetaMarketingAdSetPerformanceReport;
use App\Services\MetaMarketingAdSetReportCsv;
use App\Services\MetaMarketingDemographicPerformanceReport;
use App\Services\MetaMarketingDemographicReportCsv;
use App\Services\MetaMarketingPlacementPerformanceReport;
use App\Services\MetaMarketingPlacementReportCsv;
use App\Services\PaidMediaCampaignTrendReport;
use App\Services\SocialEngagementCampaignReport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SocialEngagementCampaignReportController extends Controller
{
    public function show(
        Request $request,
        SocialEngagementTarget $socialEngagementTarget,
        SocialEngagementCampaignReport $campaignReport,
        GoogleAnalyticsCampaignTrendReport $googleAnalyticsTrendReport,
        PaidMediaCampaignTrendReport $paidMediaTrendReport,
        CampaignMarketingEfficiencyReport $marketingEfficiencyReport,
        CampaignDailyMarketingPerformanceReport $dailyMarketingPerformanceReport,
        CampaignDailyMarketingEfficiencyReport $dailyMarketingEfficiencyReport,
        CampaignDailyMarketingTrendChart $dailyMarketingTrendChart,
        GoogleAdsSearchTermPerformanceReport $googleAdsSearchTermPerformanceReport,
        GoogleAnalyticsLandingPagePerformanceReport $googleAnalyticsLandingPagePerformanceReport,
        GoogleAnalyticsEventNamePerformanceReport $googleAnalyticsEventNamePerformanceReport,
        MetaMarketingAdPerformanceReport $metaMarketingAdPerformanceReport,
        MetaMarketingPlacementPerformanceReport $metaMarketingPlacementPerformanceReport,
        MetaMarketingDemographicPerformanceReport $metaMarketingDemographicPerformanceReport,
        MetaMarketingAdSetPerformanceReport $metaMarketingAdSetPerformanceReport,
        GoogleAdsDeliveryUnitPerformanceReport $googleAdsDeliveryUnitPerformanceReport,
        GoogleAdsDevicePerformanceReport $googleAdsDevicePerformanceReport,
        GoogleAdsAuctionInsightPerformanceReport $googleAdsAuctionInsightPerformanceReport,
        GoogleAdsKeywordPerformanceReport $googleAdsKeywordPerformanceReport,
        GoogleAnalyticsItemPerformanceReport $googleAnalyticsItemPerformanceReport,
        GoogleAnalyticsAdContentPerformanceReport $googleAnalyticsAdContentPerformanceReport,
    ): View {
        $viewData = $this->reportData(
            $request,
            $socialEngagementTarget,
            $campaignReport,
            $googleAnalyticsTrendReport,
            $paidMediaTrendReport,
            $marketingEfficiencyReport,
            $dailyMarketingPerformanceReport,
            $dailyMarketingEfficiencyReport,
            $dailyMarketingTrendChart,
        );
        $viewData['googleAdsSearchTermReport'] = $googleAdsSearchTermPerformanceReport->build(
            $viewData['googleAdsReport']?->search_terms_data,
            $viewData['googleAdsReport']?->search_terms_expires_at,
            (string) $request->query('search_term_sort', 'spend'),
        );
        $viewData['googleAdsKeywordReport'] = $googleAdsKeywordPerformanceReport->build(
            $viewData['googleAdsReport']?->keyword_performance,
            $viewData['googleAdsReport']?->keyword_performance_expires_at,
            (string) $request->query('google_ads_keyword_sort', 'spend'),
        );
        $viewData['googleAnalyticsLandingPageReport'] = $googleAnalyticsLandingPagePerformanceReport->build(
            $viewData['googleAnalyticsReport']?->landing_page_report,
            $viewData['googleAnalyticsReport']?->landing_page_expires_at,
            (string) $request->query('landing_page_sort', 'sessions'),
        );
        $viewData['googleAnalyticsEventNameReport'] = $googleAnalyticsEventNamePerformanceReport->build(
            $viewData['googleAnalyticsReport']?->event_name_report,
            $viewData['googleAnalyticsReport']?->event_name_expires_at,
            (string) $request->query('event_name_sort', 'key_events'),
        );
        $viewData['googleAnalyticsItemReport'] = $googleAnalyticsItemPerformanceReport->build(
            $viewData['googleAnalyticsReport']?->item_report,
            $viewData['googleAnalyticsReport']?->item_report_expires_at,
            (string) $request->query('item_sort', 'item_revenue'),
        );
        $viewData['googleAnalyticsAdContentReport'] = $googleAnalyticsAdContentPerformanceReport->build(
            $viewData['googleAnalyticsReport']?->ad_content_report,
            $viewData['googleAnalyticsReport']?->ad_content_report_expires_at,
            (string) $request->query('ad_content_sort', 'total_revenue'),
        );
        $viewData['metaMarketingAdReport'] = $metaMarketingAdPerformanceReport->build(
            $viewData['metaMarketingReport']?->ad_insights,
            $viewData['metaMarketingReport']?->ad_insights_expires_at,
            (string) $request->query('meta_ad_sort', 'spend'),
        );
        $viewData['metaMarketingPlacementReport'] = $metaMarketingPlacementPerformanceReport->build(
            $viewData['metaMarketingReport']?->placement_insights,
            $viewData['metaMarketingReport']?->placement_insights_expires_at,
            (string) $request->query('meta_placement_sort', 'spend'),
        );
        $viewData['metaMarketingDemographicReport'] = $metaMarketingDemographicPerformanceReport->build(
            $viewData['metaMarketingReport']?->demographic_insights,
            $viewData['metaMarketingReport']?->demographic_insights_expires_at,
            (string) $request->query('meta_demographic_sort', 'spend'),
        );
        $viewData['metaMarketingAdSetReport'] = $metaMarketingAdSetPerformanceReport->build(
            $viewData['metaMarketingReport']?->ad_set_insights,
            $viewData['metaMarketingReport']?->ad_set_insights_expires_at,
            (string) $request->query('meta_ad_set_sort', 'spend'),
        );
        $viewData['googleAdsDeliveryUnitReport'] = $googleAdsDeliveryUnitPerformanceReport->build(
            $viewData['googleAdsReport']?->delivery_unit_performance,
            $viewData['googleAdsReport']?->delivery_unit_performance_expires_at,
            (string) $request->query('delivery_unit_sort', 'spend'),
        );
        $viewData['googleAdsDeviceReport'] = $googleAdsDevicePerformanceReport->build(
            $viewData['googleAdsReport']?->device_performance,
            $viewData['googleAdsReport']?->device_performance_expires_at,
            (string) $request->query('google_ads_device_sort', 'spend'),
        );
        $viewData['googleAdsAuctionInsightReport'] = $googleAdsAuctionInsightPerformanceReport->build(
            $viewData['googleAdsReport']?->auction_insights,
            $viewData['googleAdsReport']?->auction_insights_expires_at,
            (string) $request->query('google_ads_auction_sort', 'overlap_rate'),
        );

        return view('social-engagement-reports.show', $viewData);
    }

    public function exportMetaMarketingAds(
        Request $request,
        SocialEngagementTarget $socialEngagementTarget,
        MetaMarketingAdPerformanceReport $metaMarketingAdPerformanceReport,
        MetaMarketingAdReportCsv $metaMarketingAdReportCsv,
    ): StreamedResponse {
        $connection = $request->user()->metaMarketingConnection;
        abort_if(
            $connection === null
                || $connection->ad_account_id === null
                || $socialEngagementTarget->meta_campaign_id === null
                || $socialEngagementTarget->starts_on === null
                || $socialEngagementTarget->ends_on === null,
            404,
        );

        $report = SocialEngagementTargetMetaMarketingReport::query()
            ->where('meta_marketing_connection_id', $connection->getKey())
            ->where('social_engagement_target_id', $socialEngagementTarget->getKey())
            ->where('ad_account_id', $connection->ad_account_id)
            ->where('campaign_id', $socialEngagementTarget->meta_campaign_id)
            ->whereDate('starts_on', $socialEngagementTarget->starts_on)
            ->whereDate('ends_on', $socialEngagementTarget->ends_on)
            ->first();
        abort_if(
            $report === null
                || ! is_array($report->ad_insights)
                || $report->ad_insights_expires_at?->isFuture() !== true,
            404,
        );

        $adReport = $metaMarketingAdPerformanceReport->build(
            $report->ad_insights,
            $report->ad_insights_expires_at,
            'spend',
        );
        abort_if($adReport['status'] !== 'complete' || $adReport['rows'] === [], 404);
        $csv = $metaMarketingAdReportCsv->build($adReport);

        return response()->streamDownload(function () use ($csv): void {
            $stream = fopen('php://output', 'w');
            fputcsv($stream, $csv['headers'], ',', '"', '');

            foreach ($csv['rows'] as $row) {
                fputcsv($stream, array_map(CsvFormulaSanitizer::escape(...), $row), ',', '"', '');
            }

            fclose($stream);
        }, 'meta-ads-ad-performance-'.$socialEngagementTarget->getKey().'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function exportMetaMarketingAdSets(
        Request $request,
        SocialEngagementTarget $socialEngagementTarget,
        MetaMarketingAdSetPerformanceReport $metaMarketingAdSetPerformanceReport,
        MetaMarketingAdSetReportCsv $metaMarketingAdSetReportCsv,
    ): StreamedResponse {
        $connection = $request->user()->metaMarketingConnection;
        abort_if(
            $connection === null
                || $connection->ad_account_id === null
                || $socialEngagementTarget->meta_campaign_id === null
                || $socialEngagementTarget->starts_on === null
                || $socialEngagementTarget->ends_on === null,
            404,
        );

        $report = SocialEngagementTargetMetaMarketingReport::query()
            ->where('meta_marketing_connection_id', $connection->getKey())
            ->where('social_engagement_target_id', $socialEngagementTarget->getKey())
            ->where('ad_account_id', $connection->ad_account_id)
            ->where('campaign_id', $socialEngagementTarget->meta_campaign_id)
            ->whereDate('starts_on', $socialEngagementTarget->starts_on)
            ->whereDate('ends_on', $socialEngagementTarget->ends_on)
            ->first();
        abort_if(
            $report === null
                || ! is_array($report->ad_set_insights)
                || $report->ad_set_insights_expires_at?->isFuture() !== true,
            404,
        );

        $adSetReport = $metaMarketingAdSetPerformanceReport->build(
            $report->ad_set_insights,
            $report->ad_set_insights_expires_at,
            'spend',
        );
        abort_if($adSetReport['status'] !== 'complete' || $adSetReport['rows'] === [], 404);
        $csv = $metaMarketingAdSetReportCsv->build($adSetReport);

        return response()->streamDownload(function () use ($csv): void {
            $stream = fopen('php://output', 'w');
            fputcsv($stream, $csv['headers'], ',', '"', '');

            foreach ($csv['rows'] as $row) {
                fputcsv($stream, array_map(CsvFormulaSanitizer::escape(...), $row), ',', '"', '');
            }

            fclose($stream);
        }, 'meta-ads-ad-set-performance-'.$socialEngagementTarget->getKey().'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function exportMetaMarketingPlacements(
        Request $request,
        SocialEngagementTarget $socialEngagementTarget,
        MetaMarketingPlacementPerformanceReport $metaMarketingPlacementPerformanceReport,
        MetaMarketingPlacementReportCsv $metaMarketingPlacementReportCsv,
    ): StreamedResponse {
        $connection = $request->user()->metaMarketingConnection;
        abort_if(
            $connection === null
                || $connection->ad_account_id === null
                || $socialEngagementTarget->meta_campaign_id === null
                || $socialEngagementTarget->starts_on === null
                || $socialEngagementTarget->ends_on === null,
            404,
        );

        $report = SocialEngagementTargetMetaMarketingReport::query()
            ->where('meta_marketing_connection_id', $connection->getKey())
            ->where('social_engagement_target_id', $socialEngagementTarget->getKey())
            ->where('ad_account_id', $connection->ad_account_id)
            ->where('campaign_id', $socialEngagementTarget->meta_campaign_id)
            ->whereDate('starts_on', $socialEngagementTarget->starts_on)
            ->whereDate('ends_on', $socialEngagementTarget->ends_on)
            ->first();
        abort_if(
            $report === null
                || ! is_array($report->placement_insights)
                || $report->placement_insights_expires_at?->isFuture() !== true,
            404,
        );

        $placementReport = $metaMarketingPlacementPerformanceReport->build(
            $report->placement_insights,
            $report->placement_insights_expires_at,
            'spend',
        );
        abort_if($placementReport['status'] !== 'complete' || $placementReport['rows'] === [], 404);
        $csv = $metaMarketingPlacementReportCsv->build($placementReport);

        return response()->streamDownload(function () use ($csv): void {
            $stream = fopen('php://output', 'w');
            fputcsv($stream, $csv['headers'], ',', '"', '');

            foreach ($csv['rows'] as $row) {
                fputcsv($stream, array_map(CsvFormulaSanitizer::escape(...), $row), ',', '"', '');
            }

            fclose($stream);
        }, 'meta-ads-placement-performance-'.$socialEngagementTarget->getKey().'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function exportMetaMarketingDemographics(
        Request $request,
        SocialEngagementTarget $socialEngagementTarget,
        MetaMarketingDemographicPerformanceReport $metaMarketingDemographicPerformanceReport,
        MetaMarketingDemographicReportCsv $metaMarketingDemographicReportCsv,
    ): StreamedResponse {
        $connection = $request->user()->metaMarketingConnection;
        abort_if(
            $connection === null
                || $connection->ad_account_id === null
                || $socialEngagementTarget->meta_campaign_id === null
                || $socialEngagementTarget->starts_on === null
                || $socialEngagementTarget->ends_on === null,
            404,
        );

        $report = SocialEngagementTargetMetaMarketingReport::query()
            ->where('meta_marketing_connection_id', $connection->getKey())
            ->where('social_engagement_target_id', $socialEngagementTarget->getKey())
            ->where('ad_account_id', $connection->ad_account_id)
            ->where('campaign_id', $socialEngagementTarget->meta_campaign_id)
            ->whereDate('starts_on', $socialEngagementTarget->starts_on)
            ->whereDate('ends_on', $socialEngagementTarget->ends_on)
            ->first();
        abort_if(
            $report === null
                || ! is_array($report->demographic_insights)
                || $report->demographic_insights_expires_at?->isFuture() !== true,
            404,
        );

        $demographicReport = $metaMarketingDemographicPerformanceReport->build(
            $report->demographic_insights,
            $report->demographic_insights_expires_at,
            'spend',
        );
        abort_if($demographicReport['status'] !== 'complete' || $demographicReport['rows'] === [], 404);
        $csv = $metaMarketingDemographicReportCsv->build($demographicReport);

        return response()->streamDownload(function () use ($csv): void {
            $stream = fopen('php://output', 'w');
            fputcsv($stream, $csv['headers'], ',', '"', '');

            foreach ($csv['rows'] as $row) {
                fputcsv($stream, array_map(CsvFormulaSanitizer::escape(...), $row), ',', '"', '');
            }

            fclose($stream);
        }, 'meta-ads-demographic-performance-'.$socialEngagementTarget->getKey().'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function exportGoogleAnalyticsLandingPages(
        Request $request,
        SocialEngagementTarget $socialEngagementTarget,
        GoogleAnalyticsLandingPagePerformanceReport $googleAnalyticsLandingPagePerformanceReport,
        GoogleAnalyticsLandingPageReportCsv $googleAnalyticsLandingPageReportCsv,
    ): StreamedResponse {
        $analyticsReport = $this->googleAnalyticsReportForTarget(
            $socialEngagementTarget,
            $request->user()->googleAnalyticsConnection,
        );
        abort_if(
            $analyticsReport === null
                || ! is_array($analyticsReport->landing_page_report)
                || $analyticsReport->landing_page_expires_at?->isFuture() !== true,
            404,
        );

        $landingPageReport = $googleAnalyticsLandingPagePerformanceReport->build(
            $analyticsReport->landing_page_report,
            $analyticsReport->landing_page_expires_at,
            'sessions',
        );
        abort_if($landingPageReport['status'] !== 'complete' || $landingPageReport['rows'] === [], 404);
        $csv = $googleAnalyticsLandingPageReportCsv->build($landingPageReport);

        return response()->streamDownload(function () use ($csv): void {
            $stream = fopen('php://output', 'w');
            fputcsv($stream, $csv['headers'], ',', '"', '');

            foreach ($csv['rows'] as $row) {
                fputcsv($stream, array_map(CsvFormulaSanitizer::escape(...), $row), ',', '"', '');
            }

            fclose($stream);
        }, 'ga4-landing-pages-'.$socialEngagementTarget->getKey().'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function exportGoogleAnalyticsEventNames(
        Request $request,
        SocialEngagementTarget $socialEngagementTarget,
        GoogleAnalyticsEventNamePerformanceReport $googleAnalyticsEventNamePerformanceReport,
        GoogleAnalyticsEventNameReportCsv $googleAnalyticsEventNameReportCsv,
    ): StreamedResponse {
        $analyticsReport = $this->googleAnalyticsReportForTarget(
            $socialEngagementTarget,
            $request->user()->googleAnalyticsConnection,
        );
        abort_if(
            $analyticsReport === null
                || ! is_array($analyticsReport->event_name_report)
                || $analyticsReport->event_name_expires_at?->isFuture() !== true,
            404,
        );

        $eventNameReport = $googleAnalyticsEventNamePerformanceReport->build(
            $analyticsReport->event_name_report,
            $analyticsReport->event_name_expires_at,
            'key_events',
        );
        abort_if($eventNameReport['status'] !== 'complete' || $eventNameReport['rows'] === [], 404);
        $csv = $googleAnalyticsEventNameReportCsv->build($eventNameReport);

        return response()->streamDownload(function () use ($csv): void {
            $stream = fopen('php://output', 'w');
            fputcsv($stream, $csv['headers'], ',', '"', '');

            foreach ($csv['rows'] as $row) {
                fputcsv($stream, array_map(CsvFormulaSanitizer::escape(...), $row), ',', '"', '');
            }

            fclose($stream);
        }, 'ga4-event-names-'.$socialEngagementTarget->getKey().'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function exportGoogleAdsSearchTerms(
        Request $request,
        SocialEngagementTarget $socialEngagementTarget,
        GoogleAdsSearchTermPerformanceReport $googleAdsSearchTermPerformanceReport,
        GoogleAdsSearchTermReportCsv $googleAdsSearchTermReportCsv,
    ): StreamedResponse {
        $connection = $request->user()->googleAdsConnection;
        abort_if(
            $connection === null
                || $connection->customer_id === null
                || $socialEngagementTarget->google_ads_campaign_id === null
                || $socialEngagementTarget->starts_on === null
                || $socialEngagementTarget->ends_on === null,
            404,
        );

        $report = SocialEngagementTargetGoogleAdsReport::query()
            ->where('google_ads_connection_id', $connection->getKey())
            ->where('social_engagement_target_id', $socialEngagementTarget->getKey())
            ->where('customer_id', $connection->customer_id)
            ->where('campaign_id', $socialEngagementTarget->google_ads_campaign_id)
            ->whereDate('starts_on', $socialEngagementTarget->starts_on)
            ->whereDate('ends_on', $socialEngagementTarget->ends_on)
            ->first();
        abort_if(
            $report === null
                || ! is_array($report->search_terms_data)
                || $report->search_terms_expires_at?->isFuture() !== true,
            404,
        );

        $searchTermReport = $googleAdsSearchTermPerformanceReport->build(
            $report->search_terms_data,
            $report->search_terms_expires_at,
            'spend',
        );
        abort_if($searchTermReport['status'] !== 'complete', 404);
        $csv = $googleAdsSearchTermReportCsv->build($searchTermReport);

        return response()->streamDownload(function () use ($csv): void {
            $stream = fopen('php://output', 'w');
            fputcsv($stream, $csv['headers'], ',', '"', '');

            foreach ($csv['rows'] as $row) {
                fputcsv($stream, array_map(CsvFormulaSanitizer::escape(...), $row), ',', '"', '');
            }

            fclose($stream);
        }, 'google-ads-search-terms-'.$socialEngagementTarget->getKey().'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function exportGoogleAdsKeywords(
        Request $request,
        SocialEngagementTarget $socialEngagementTarget,
        GoogleAdsKeywordPerformanceReport $googleAdsKeywordPerformanceReport,
        GoogleAdsKeywordReportCsv $googleAdsKeywordReportCsv,
    ): StreamedResponse {
        $connection = $request->user()->googleAdsConnection;
        abort_if(
            $connection === null
                || $connection->customer_id === null
                || $socialEngagementTarget->google_ads_campaign_id === null
                || $socialEngagementTarget->starts_on === null
                || $socialEngagementTarget->ends_on === null,
            404,
        );

        $report = SocialEngagementTargetGoogleAdsReport::query()
            ->where('google_ads_connection_id', $connection->getKey())
            ->where('social_engagement_target_id', $socialEngagementTarget->getKey())
            ->where('customer_id', $connection->customer_id)
            ->where('campaign_id', $socialEngagementTarget->google_ads_campaign_id)
            ->whereDate('starts_on', $socialEngagementTarget->starts_on)
            ->whereDate('ends_on', $socialEngagementTarget->ends_on)
            ->first();
        abort_if(
            $report === null
                || ! is_array($report->keyword_performance)
                || $report->keyword_performance_expires_at?->isFuture() !== true,
            404,
        );

        $keywordReport = $googleAdsKeywordPerformanceReport->build(
            $report->keyword_performance,
            $report->keyword_performance_expires_at,
            'spend',
        );
        abort_if($keywordReport['status'] !== 'complete', 404);
        $csv = $googleAdsKeywordReportCsv->build($keywordReport);

        return response()->streamDownload(function () use ($csv): void {
            $stream = fopen('php://output', 'w');
            fputcsv($stream, $csv['headers'], ',', '"', '');

            foreach ($csv['rows'] as $row) {
                fputcsv($stream, array_map(CsvFormulaSanitizer::escape(...), $row), ',', '"', '');
            }

            fclose($stream);
        }, 'google-ads-keywords-'.$socialEngagementTarget->getKey().'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function exportGoogleAdsDeliveryUnits(
        Request $request,
        SocialEngagementTarget $socialEngagementTarget,
        GoogleAdsDeliveryUnitPerformanceReport $googleAdsDeliveryUnitPerformanceReport,
        GoogleAdsDeliveryUnitReportCsv $googleAdsDeliveryUnitReportCsv,
    ): StreamedResponse {
        $connection = $request->user()->googleAdsConnection;
        abort_if(
            $connection === null
                || $connection->customer_id === null
                || $socialEngagementTarget->google_ads_campaign_id === null
                || $socialEngagementTarget->starts_on === null
                || $socialEngagementTarget->ends_on === null,
            404,
        );

        $report = SocialEngagementTargetGoogleAdsReport::query()
            ->where('google_ads_connection_id', $connection->getKey())
            ->where('social_engagement_target_id', $socialEngagementTarget->getKey())
            ->where('customer_id', $connection->customer_id)
            ->where('campaign_id', $socialEngagementTarget->google_ads_campaign_id)
            ->whereDate('starts_on', $socialEngagementTarget->starts_on)
            ->whereDate('ends_on', $socialEngagementTarget->ends_on)
            ->first();
        abort_if(
            $report === null
                || ! is_array($report->delivery_unit_performance)
                || $report->delivery_unit_performance_expires_at?->isFuture() !== true,
            404,
        );

        $deliveryUnitReport = $googleAdsDeliveryUnitPerformanceReport->build(
            $report->delivery_unit_performance,
            $report->delivery_unit_performance_expires_at,
            'spend',
        );
        abort_if($deliveryUnitReport['status'] !== 'complete' || $deliveryUnitReport['rows'] === [], 404);
        $csv = $googleAdsDeliveryUnitReportCsv->build($deliveryUnitReport);

        return response()->streamDownload(function () use ($csv): void {
            $stream = fopen('php://output', 'w');
            fputcsv($stream, $csv['headers'], ',', '"', '');

            foreach ($csv['rows'] as $row) {
                fputcsv($stream, array_map(CsvFormulaSanitizer::escape(...), $row), ',', '"', '');
            }

            fclose($stream);
        }, 'google-ads-delivery-units-'.$socialEngagementTarget->getKey().'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function exportGoogleAdsDevices(
        Request $request,
        SocialEngagementTarget $socialEngagementTarget,
        GoogleAdsDevicePerformanceReport $googleAdsDevicePerformanceReport,
        GoogleAdsDeviceReportCsv $googleAdsDeviceReportCsv,
    ): StreamedResponse {
        $connection = $request->user()->googleAdsConnection;
        abort_if(
            $connection === null
                || $connection->customer_id === null
                || $socialEngagementTarget->google_ads_campaign_id === null
                || $socialEngagementTarget->starts_on === null
                || $socialEngagementTarget->ends_on === null,
            404,
        );

        $report = SocialEngagementTargetGoogleAdsReport::query()
            ->where('google_ads_connection_id', $connection->getKey())
            ->where('social_engagement_target_id', $socialEngagementTarget->getKey())
            ->where('customer_id', $connection->customer_id)
            ->where('campaign_id', $socialEngagementTarget->google_ads_campaign_id)
            ->whereDate('starts_on', $socialEngagementTarget->starts_on)
            ->whereDate('ends_on', $socialEngagementTarget->ends_on)
            ->first();
        abort_if(
            $report === null
                || ! is_array($report->device_performance)
                || $report->device_performance_expires_at?->isFuture() !== true,
            404,
        );

        $deviceReport = $googleAdsDevicePerformanceReport->build(
            $report->device_performance,
            $report->device_performance_expires_at,
            'spend',
        );
        abort_if($deviceReport['status'] !== 'complete' || $deviceReport['rows'] === [], 404);
        $csv = $googleAdsDeviceReportCsv->build($deviceReport);

        return response()->streamDownload(function () use ($csv): void {
            $stream = fopen('php://output', 'w');
            fputcsv($stream, $csv['headers'], ',', '"', '');

            foreach ($csv['rows'] as $row) {
                fputcsv($stream, array_map(CsvFormulaSanitizer::escape(...), $row), ',', '"', '');
            }

            fclose($stream);
        }, 'google-ads-device-performance-'.$socialEngagementTarget->getKey().'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function exportGoogleAdsAuctionInsights(
        Request $request,
        SocialEngagementTarget $socialEngagementTarget,
        GoogleAdsAuctionInsightPerformanceReport $googleAdsAuctionInsightPerformanceReport,
        GoogleAdsAuctionInsightReportCsv $googleAdsAuctionInsightReportCsv,
    ): StreamedResponse {
        $connection = $request->user()->googleAdsConnection;
        abort_if(
            $connection === null
                || $connection->customer_id === null
                || $socialEngagementTarget->google_ads_campaign_id === null
                || $socialEngagementTarget->starts_on === null
                || $socialEngagementTarget->ends_on === null,
            404,
        );

        $report = SocialEngagementTargetGoogleAdsReport::query()
            ->where('google_ads_connection_id', $connection->getKey())
            ->where('social_engagement_target_id', $socialEngagementTarget->getKey())
            ->where('customer_id', $connection->customer_id)
            ->where('campaign_id', $socialEngagementTarget->google_ads_campaign_id)
            ->whereDate('starts_on', $socialEngagementTarget->starts_on)
            ->whereDate('ends_on', $socialEngagementTarget->ends_on)
            ->first();
        abort_if(
            $report === null
                || ! is_array($report->auction_insights)
                || $report->auction_insights_expires_at?->isFuture() !== true,
            404,
        );

        $auctionInsightReport = $googleAdsAuctionInsightPerformanceReport->build(
            $report->auction_insights,
            $report->auction_insights_expires_at,
            'overlap_rate',
        );
        abort_if($auctionInsightReport['status'] !== 'complete' || $auctionInsightReport['rows'] === [], 404);
        $csv = $googleAdsAuctionInsightReportCsv->build($auctionInsightReport);

        return response()->streamDownload(function () use ($csv): void {
            $stream = fopen('php://output', 'w');
            fputcsv($stream, $csv['headers'], ',', '"', '');

            foreach ($csv['rows'] as $row) {
                fputcsv($stream, array_map(CsvFormulaSanitizer::escape(...), $row), ',', '"', '');
            }

            fclose($stream);
        }, 'google-ads-auction-insights-'.$socialEngagementTarget->getKey().'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function exportCampaignSummary(
        Request $request,
        SocialEngagementTarget $socialEngagementTarget,
        SocialEngagementCampaignReport $campaignReport,
        GoogleAnalyticsCampaignTrendReport $googleAnalyticsTrendReport,
        PaidMediaCampaignTrendReport $paidMediaTrendReport,
        CampaignMarketingEfficiencyReport $marketingEfficiencyReport,
        CampaignDailyMarketingPerformanceReport $dailyMarketingPerformanceReport,
        CampaignDailyMarketingEfficiencyReport $dailyMarketingEfficiencyReport,
        CampaignDailyMarketingTrendChart $dailyMarketingTrendChart,
        CampaignMarketingReportCsv $campaignMarketingReportCsv,
    ): StreamedResponse {
        $data = $this->reportData(
            $request,
            $socialEngagementTarget,
            $campaignReport,
            $googleAnalyticsTrendReport,
            $paidMediaTrendReport,
            $marketingEfficiencyReport,
            $dailyMarketingPerformanceReport,
            $dailyMarketingEfficiencyReport,
            $dailyMarketingTrendChart,
            false,
        );
        $csv = $campaignMarketingReportCsv->build($data);

        return response()->streamDownload(function () use ($csv): void {
            $stream = fopen('php://output', 'w');
            fputcsv($stream, $csv['headers'], ',', '"', '');
            fputcsv($stream, array_map(CsvFormulaSanitizer::escape(...), $csv['fields']), ',', '"', '');
            fclose($stream);
        }, 'campaign-report-'.$socialEngagementTarget->getKey().'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function exportDailyMarketingPerformance(
        Request $request,
        SocialEngagementTarget $socialEngagementTarget,
        SocialEngagementCampaignReport $campaignReport,
        GoogleAnalyticsCampaignTrendReport $googleAnalyticsTrendReport,
        PaidMediaCampaignTrendReport $paidMediaTrendReport,
        CampaignMarketingEfficiencyReport $marketingEfficiencyReport,
        CampaignDailyMarketingPerformanceReport $dailyMarketingPerformanceReport,
        CampaignDailyMarketingEfficiencyReport $dailyMarketingEfficiencyReport,
        CampaignDailyMarketingTrendChart $dailyMarketingTrendChart,
        CampaignDailyMarketingReportCsv $dailyMarketingReportCsv,
    ): StreamedResponse {
        $data = $this->reportData(
            $request,
            $socialEngagementTarget,
            $campaignReport,
            $googleAnalyticsTrendReport,
            $paidMediaTrendReport,
            $marketingEfficiencyReport,
            $dailyMarketingPerformanceReport,
            $dailyMarketingEfficiencyReport,
            $dailyMarketingTrendChart,
            false,
        );
        $csv = $dailyMarketingReportCsv->build($data['dailyMarketingPerformance']);

        return response()->streamDownload(function () use ($csv): void {
            $stream = fopen('php://output', 'w');
            fputcsv($stream, $csv['headers'], ',', '"', '');

            foreach ($csv['rows'] as $row) {
                fputcsv($stream, array_map(CsvFormulaSanitizer::escape(...), $row), ',', '"', '');
            }

            fclose($stream);
        }, 'campaign-daily-marketing-performance-'.$socialEngagementTarget->getKey().'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * @return array{
     *     target: SocialEngagementTarget,
     *     report: array<string, mixed>,
     *     googleAnalyticsConnection: GoogleAnalyticsConnection|null,
     *     googleAnalyticsReport: SocialEngagementTargetGoogleAnalyticsReport|null,
     *     googleAnalyticsReportIsStale: bool,
     *     metaMarketingConnection: MetaMarketingConnection|null,
     *     metaMarketingReport: SocialEngagementTargetMetaMarketingReport|null,
     *     metaMarketingReportIsStale: bool,
     *     metaMarketingTrend: list<array<string, mixed>>,
     *     googleAdsConnection: GoogleAdsConnection|null,
     *     googleAdsReport: SocialEngagementTargetGoogleAdsReport|null,
     *     googleAdsCampaignOptions: list<array{campaign_id: string, campaign_name: string, status: string, channel_type: string}>|null,
     *     googleAdsReportIsStale: bool,
     *     googleAdsTrend: list<array<string, mixed>>,
     *     googleAnalyticsCampaignName: string,
     *     googleAnalyticsSourceName: string|null,
     *     googleAnalyticsMediumName: string|null,
     *     googleAnalyticsTrend: list<array<string, mixed>>,
     *     adSpendAnalysis: array<string, mixed>,
     *     dailyMarketingPerformance: array<string, array<string, mixed>>
     * }
     */
    private function reportData(
        Request $request,
        SocialEngagementTarget $socialEngagementTarget,
        SocialEngagementCampaignReport $campaignReport,
        GoogleAnalyticsCampaignTrendReport $googleAnalyticsTrendReport,
        PaidMediaCampaignTrendReport $paidMediaTrendReport,
        CampaignMarketingEfficiencyReport $marketingEfficiencyReport,
        CampaignDailyMarketingPerformanceReport $dailyMarketingPerformanceReport,
        CampaignDailyMarketingEfficiencyReport $dailyMarketingEfficiencyReport,
        CampaignDailyMarketingTrendChart $dailyMarketingTrendChart,
        bool $includeHistoricalDetails = true,
    ): array {
        $campaignName = $socialEngagementTarget->googleAnalyticsCampaignName();
        $usesGoogleAdsAutoTagging = $socialEngagementTarget->googleAnalyticsAttributionMode() === 'google_ads_auto_tagging';
        $sourceName = $usesGoogleAdsAutoTagging ? null : $socialEngagementTarget->googleAnalyticsSourceName();
        $mediumName = $usesGoogleAdsAutoTagging ? null : $socialEngagementTarget->googleAnalyticsMediumName();
        $googleAnalyticsConnection = $request->user()->googleAnalyticsConnection;
        $analyticsReport = $this->googleAnalyticsReportForTarget($socialEngagementTarget, $googleAnalyticsConnection);

        $metaMarketingConnection = $request->user()->metaMarketingConnection;
        $metaMarketingReport = $metaMarketingConnection === null
            || $metaMarketingConnection->ad_account_id === null
            || $socialEngagementTarget->meta_campaign_id === null
            || $socialEngagementTarget->starts_on === null
            || $socialEngagementTarget->ends_on === null
            ? null
            : SocialEngagementTargetMetaMarketingReport::query()
                ->where('meta_marketing_connection_id', $metaMarketingConnection->getKey())
                ->where('social_engagement_target_id', $socialEngagementTarget->getKey())
                ->where('ad_account_id', $metaMarketingConnection->ad_account_id)
                ->where('campaign_id', $socialEngagementTarget->meta_campaign_id)
                ->whereDate('starts_on', $socialEngagementTarget->starts_on)
                ->whereDate('ends_on', $socialEngagementTarget->ends_on)
                ->first();

        $googleAdsConnection = $request->user()->googleAdsConnection;
        $googleAdsReport = $googleAdsConnection === null
            || $googleAdsConnection->customer_id === null
            || $socialEngagementTarget->google_ads_campaign_id === null
            || $socialEngagementTarget->starts_on === null
            || $socialEngagementTarget->ends_on === null
            ? null
            : SocialEngagementTargetGoogleAdsReport::query()
                ->where('google_ads_connection_id', $googleAdsConnection->getKey())
                ->where('social_engagement_target_id', $socialEngagementTarget->getKey())
                ->where('customer_id', $googleAdsConnection->customer_id)
                ->where('campaign_id', $socialEngagementTarget->google_ads_campaign_id)
                ->whereDate('starts_on', $socialEngagementTarget->starts_on)
                ->whereDate('ends_on', $socialEngagementTarget->ends_on)
                ->first();

        $googleAdsCampaignOptions = session('google_ads_campaign_options');
        $googleAdsCampaignOptions = is_array($googleAdsCampaignOptions)
            && (string) ($googleAdsCampaignOptions['target_id'] ?? '') === (string) $socialEngagementTarget->getKey()
            && is_array($googleAdsCampaignOptions['campaigns'] ?? null)
            ? $googleAdsCampaignOptions['campaigns']
            : null;

        $campaignData = $campaignReport->build($socialEngagementTarget, $includeHistoricalDetails);
        $reportFreshnessCutoff = now()->subDay();
        $dailyMarketingPerformance = $dailyMarketingPerformanceReport->build(
            $analyticsReport,
            $metaMarketingReport,
            $googleAdsReport,
        );
        foreach ([
            'google_analytics' => $analyticsReport,
            'meta_marketing' => $metaMarketingReport,
            'google_ads' => $googleAdsReport,
        ] as $sourceKey => $sourceReport) {
            $dailyMarketingPerformance[$sourceKey]['snapshot_available'] = $sourceReport?->daily_metrics !== null;

            $chartMetric = match ($sourceKey) {
                'google_analytics' => ['key' => 'sessions', 'label' => '工作階段', 'format' => 'integer'],
                'meta_marketing', 'google_ads' => ['key' => 'spend_amount', 'label' => '花費', 'format' => 'currency'],
            };
            $dailyMarketingPerformance[$sourceKey]['trend_chart'] = $dailyMarketingTrendChart->build(
                $dailyMarketingPerformance[$sourceKey],
                $chartMetric['key'],
                $chartMetric['label'],
                $chartMetric['format'],
            );
        }
        $dailyMarketingPerformance['combined_efficiency'] = $dailyMarketingEfficiencyReport->build(
            $socialEngagementTarget,
            $dailyMarketingPerformance,
        );

        return [
            ...$campaignData,
            'googleAnalyticsConnection' => $googleAnalyticsConnection,
            'googleAnalyticsReport' => $analyticsReport,
            'googleAnalyticsReportIsStale' => $analyticsReport !== null
                && $analyticsReport->synced_at->lessThanOrEqualTo($reportFreshnessCutoff),
            'metaMarketingConnection' => $metaMarketingConnection,
            'metaMarketingReport' => $metaMarketingReport,
            'metaMarketingReportIsStale' => $metaMarketingReport !== null
                && $metaMarketingReport->synced_at->lessThanOrEqualTo($reportFreshnessCutoff),
            'metaMarketingTrend' => $includeHistoricalDetails
                ? $paidMediaTrendReport->metaMarketingChanges($metaMarketingConnection, $socialEngagementTarget)
                : [],
            'googleAdsConnection' => $googleAdsConnection,
            'googleAdsReport' => $googleAdsReport,
            'googleAdsCampaignOptions' => $googleAdsCampaignOptions,
            'googleAdsReportIsStale' => $googleAdsReport !== null
                && $googleAdsReport->synced_at->lessThanOrEqualTo($reportFreshnessCutoff),
            'googleAdsTrend' => $includeHistoricalDetails
                ? $paidMediaTrendReport->googleAdsChanges($googleAdsConnection, $socialEngagementTarget)
                : [],
            'googleAnalyticsCampaignName' => $campaignName,
            'googleAnalyticsSourceName' => $sourceName,
            'googleAnalyticsMediumName' => $mediumName,
            'googleAnalyticsTrend' => $includeHistoricalDetails && $googleAnalyticsConnection !== null && $analyticsReport !== null
                ? $googleAnalyticsTrendReport->recentChanges(
                    $googleAnalyticsConnection,
                    $socialEngagementTarget,
                    $campaignName,
                    $sourceName,
                    $mediumName,
                    $socialEngagementTarget->googleAnalyticsAttributionMode(),
                    $usesGoogleAdsAutoTagging ? trim((string) $socialEngagementTarget->google_ads_campaign_id) : null,
                )
                : [],
            'adSpendAnalysis' => $marketingEfficiencyReport->build(
                $socialEngagementTarget,
                $analyticsReport,
                $campaignData['report']['interaction_total'],
                $metaMarketingReport,
                $googleAdsReport,
                observedCampaignInteractionChange: $campaignData['report']['observed_interaction_change'],
                comparableInteractionPostCount: $campaignData['report']['comparable_interaction_post_count'],
            ),
            'dailyMarketingPerformance' => $dailyMarketingPerformance,
        ];
    }

    public function exportGoogleAnalyticsChannelBreakdown(
        Request $request,
        SocialEngagementTarget $socialEngagementTarget,
    ): StreamedResponse {
        $analyticsReport = $this->googleAnalyticsReportForTarget(
            $socialEngagementTarget,
            $request->user()->googleAnalyticsConnection,
        );
        $rows = $analyticsReport?->source_medium_breakdown;

        abort_if(! is_array($rows) || $rows === [], 404);

        return response()->streamDownload(function () use ($analyticsReport, $rows): void {
            $stream = fopen('php://output', 'w');
            fputcsv($stream, [
                'campaign_name',
                'attribution_mode',
                'campaign_id',
                'campaign_start',
                'campaign_end',
                'channel_group',
                'source',
                'medium',
                'sessions',
                'engaged_sessions',
                'key_events',
                'total_revenue',
                'breakdown_truncated',
                'currency_code',
                'synced_at_asia_taipei',
            ], ',', '"', '');

            foreach ($rows as $row) {
                $fields = [
                    $analyticsReport->campaign_name,
                    $analyticsReport->attribution_mode,
                    $analyticsReport->campaign_id ?? '',
                    $analyticsReport->starts_on->toDateString(),
                    $analyticsReport->ends_on->toDateString(),
                    $row['channel_group'] ?? '',
                    $row['source_name'] ?? '',
                    $row['medium_name'] ?? '',
                    (string) ($row['sessions'] ?? ''),
                    (string) ($row['engaged_sessions'] ?? ''),
                    (string) ($row['key_events'] ?? ''),
                    (string) ($row['total_revenue'] ?? ''),
                    $analyticsReport->source_medium_breakdown_truncated ? 'true' : 'false',
                    $analyticsReport->currency_code ?? '',
                    $analyticsReport->synced_at?->timezone('Asia/Taipei')->format('Y-m-d H:i:s') ?? '',
                ];

                fputcsv($stream, array_map(CsvFormulaSanitizer::escape(...), $fields), ',', '"', '');
            }

            fclose($stream);
        }, 'ga4-channel-breakdown-'.$socialEngagementTarget->getKey().'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function googleAnalyticsReportForTarget(
        SocialEngagementTarget $target,
        ?GoogleAnalyticsConnection $connection,
    ): ?SocialEngagementTargetGoogleAnalyticsReport {
        if ($connection === null) {
            return null;
        }

        $sourceName = $target->googleAnalyticsSourceName();
        $mediumName = $target->googleAnalyticsMediumName();
        $attributionMode = $target->googleAnalyticsAttributionMode();
        $usesGoogleAdsAutoTagging = $attributionMode === 'google_ads_auto_tagging';

        return SocialEngagementTargetGoogleAnalyticsReport::query()
            ->where('google_analytics_connection_id', $connection->getKey())
            ->where('social_engagement_target_id', $target->getKey())
            ->where('attribution_mode', $attributionMode)
            ->when($usesGoogleAdsAutoTagging, fn (Builder $query): Builder => $query->where('campaign_id', trim((string) $target->google_ads_campaign_id)))
            ->when(! $usesGoogleAdsAutoTagging, fn (Builder $query): Builder => $query->whereNull('campaign_id'))
            ->when(! $usesGoogleAdsAutoTagging, fn (Builder $query): Builder => $query->where('campaign_name', $target->googleAnalyticsCampaignName()))
            ->when(! $usesGoogleAdsAutoTagging && $sourceName !== null, fn (Builder $query): Builder => $query->where('source_name', $sourceName))
            ->when(! $usesGoogleAdsAutoTagging && $sourceName === null, fn (Builder $query): Builder => $query->whereNull('source_name'))
            ->when(! $usesGoogleAdsAutoTagging && $mediumName !== null, fn (Builder $query): Builder => $query->where('medium_name', $mediumName))
            ->when(! $usesGoogleAdsAutoTagging && $mediumName === null, fn (Builder $query): Builder => $query->whereNull('medium_name'))
            ->when($target->starts_on !== null, fn (Builder $query): Builder => $query->whereDate('starts_on', $target->starts_on))
            ->when($target->ends_on !== null, fn (Builder $query): Builder => $query->whereDate('ends_on', $target->ends_on))
            ->first();
    }

    public function exportGoogleAnalyticsItems(
        Request $request,
        SocialEngagementTarget $socialEngagementTarget,
        GoogleAnalyticsItemPerformanceReport $googleAnalyticsItemPerformanceReport,
        GoogleAnalyticsItemReportCsv $googleAnalyticsItemReportCsv,
    ): StreamedResponse {
        $analyticsReport = $this->googleAnalyticsReportForTarget(
            $socialEngagementTarget,
            $request->user()->googleAnalyticsConnection,
        );
        abort_if(
            $analyticsReport === null
                || ! is_array($analyticsReport->item_report)
                || $analyticsReport->item_report_expires_at?->isFuture() !== true,
            404,
        );

        $itemReport = $googleAnalyticsItemPerformanceReport->build(
            $analyticsReport->item_report,
            $analyticsReport->item_report_expires_at,
            'item_revenue',
        );
        abort_if($itemReport['status'] !== 'complete' || $itemReport['rows'] === [], 404);
        $csv = $googleAnalyticsItemReportCsv->build($itemReport);

        return response()->streamDownload(function () use ($csv): void {
            $stream = fopen('php://output', 'w');
            fputcsv($stream, $csv['headers'], ',', '"', '');

            foreach ($csv['rows'] as $row) {
                fputcsv($stream, array_map(CsvFormulaSanitizer::escape(...), $row), ',', '"', '');
            }

            fclose($stream);
        }, 'ga4-items-'.$socialEngagementTarget->getKey().'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function exportGoogleAnalyticsAdContent(
        Request $request,
        SocialEngagementTarget $socialEngagementTarget,
        GoogleAnalyticsAdContentPerformanceReport $googleAnalyticsAdContentPerformanceReport,
        GoogleAnalyticsAdContentReportCsv $googleAnalyticsAdContentReportCsv,
    ): StreamedResponse {
        $analyticsReport = $this->googleAnalyticsReportForTarget(
            $socialEngagementTarget,
            $request->user()->googleAnalyticsConnection,
        );
        abort_if(
            $analyticsReport === null
                || ! is_array($analyticsReport->ad_content_report)
                || $analyticsReport->ad_content_report_expires_at?->isFuture() !== true,
            404,
        );

        $adContentReport = $googleAnalyticsAdContentPerformanceReport->build(
            $analyticsReport->ad_content_report,
            $analyticsReport->ad_content_report_expires_at,
            'total_revenue',
        );
        abort_if($adContentReport['status'] !== 'complete' || $adContentReport['rows'] === [], 404);
        $csv = $googleAnalyticsAdContentReportCsv->build($adContentReport);

        return response()->streamDownload(function () use ($csv): void {
            $stream = fopen('php://output', 'w');
            fputcsv($stream, $csv['headers'], ',', '"', '');

            foreach ($csv['rows'] as $row) {
                fputcsv($stream, array_map(CsvFormulaSanitizer::escape(...), $row), ',', '"', '');
            }

            fclose($stream);
        }, 'ga4-ad-content-'.$socialEngagementTarget->getKey().'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function exportGoogleAnalyticsHistory(
        Request $request,
        SocialEngagementTarget $socialEngagementTarget,
        GoogleAnalyticsCampaignTrendReport $googleAnalyticsTrendReport,
    ): StreamedResponse {
        $connection = $request->user()->googleAnalyticsConnection;

        abort_if(
            $connection === null
                || ! is_string($connection->property_id)
                || $connection->property_id === ''
                || $socialEngagementTarget->starts_on === null
                || $socialEngagementTarget->ends_on === null,
            404,
        );

        $usesGoogleAdsAutoTagging = $socialEngagementTarget->googleAnalyticsAttributionMode() === 'google_ads_auto_tagging';
        $rows = $googleAnalyticsTrendReport->recentChanges(
            $connection,
            $socialEngagementTarget,
            $socialEngagementTarget->googleAnalyticsCampaignName(),
            $usesGoogleAdsAutoTagging ? null : $socialEngagementTarget->googleAnalyticsSourceName(),
            $usesGoogleAdsAutoTagging ? null : $socialEngagementTarget->googleAnalyticsMediumName(),
            $socialEngagementTarget->googleAnalyticsAttributionMode(),
            $usesGoogleAdsAutoTagging ? trim((string) $socialEngagementTarget->google_ads_campaign_id) : null,
        );

        return response()->streamDownload(function () use ($rows): void {
            $stream = fopen('php://output', 'w');
            fputcsv($stream, [
                'attribution_mode',
                'campaign_id',
                'snapshot_date',
                'synced_at_asia_taipei',
                'sessions_cumulative',
                'sessions_change_since_previous',
                'engaged_sessions_cumulative',
                'engaged_sessions_change_since_previous',
                'key_events_cumulative',
                'key_events_change_since_previous',
                'total_revenue_cumulative',
                'total_revenue_change_since_previous',
                'currency_code',
            ], ',', '"', '');

            foreach ($rows as $row) {
                $fields = [
                    $row['attribution_mode'],
                    $row['campaign_id'] ?? '',
                    $row['snapshot_date']->toDateString(),
                    $row['synced_at']->timezone('Asia/Taipei')->format('Y-m-d H:i:s'),
                    (string) $row['sessions'],
                    $row['sessions_change'] === null ? '' : (string) $row['sessions_change'],
                    (string) $row['engaged_sessions'],
                    $row['engaged_sessions_change'] === null ? '' : (string) $row['engaged_sessions_change'],
                    $row['key_events'],
                    $row['key_events_change'] === null ? '' : number_format($row['key_events_change'], 4, '.', ''),
                    $row['total_revenue'],
                    $row['total_revenue_change'] === null ? '' : number_format($row['total_revenue_change'], 4, '.', ''),
                    $row['currency_code'] ?? '',
                ];

                fputcsv($stream, array_map(CsvFormulaSanitizer::escape(...), $fields), ',', '"', '');
            }

            fclose($stream);
        }, 'ga4-campaign-trend-'.$socialEngagementTarget->getKey().'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function exportPaidMediaHistory(
        Request $request,
        SocialEngagementTarget $socialEngagementTarget,
        PaidMediaCampaignTrendReport $paidMediaTrendReport,
        string $source,
    ): StreamedResponse {
        [$rows, $sourceLabel, $filePrefix] = match ($source) {
            'meta-marketing' => [
                $paidMediaTrendReport->metaMarketingChanges($request->user()->metaMarketingConnection, $socialEngagementTarget),
                'Meta Ads',
                'meta-marketing',
            ],
            'google-ads' => [
                $paidMediaTrendReport->googleAdsChanges($request->user()->googleAdsConnection, $socialEngagementTarget),
                'Google Ads',
                'google-ads',
            ],
            default => abort(404),
        };

        abort_if($rows === [], 404);

        return response()->streamDownload(function () use ($rows, $sourceLabel): void {
            $stream = fopen('php://output', 'w');
            fputcsv($stream, [
                'source',
                'campaign_name',
                'synced_at_asia_taipei',
                'spend_cumulative',
                'spend_change_since_previous',
                'currency_code',
                'impressions_cumulative',
                'impressions_change_since_previous',
                'reach_cumulative',
                'reach_change_since_previous',
                'frequency_cumulative',
                'frequency_change_since_previous',
                'clicks_cumulative',
                'clicks_change_since_previous',
                'conversions_cumulative',
                'conversions_change_since_previous',
                'conversions_value_cumulative',
                'conversions_value_change_since_previous',
                'roas_value_cumulative',
                'roas',
                'roas_change_since_previous',
                'ctr_percent',
                'ctr_change_percentage_points_since_previous',
                'cost_per_click',
                'cost_per_click_change_since_previous',
                'cost_per_thousand_impressions',
                'cost_per_thousand_impressions_change_since_previous',
                'cost_per_conversion',
                'cost_per_conversion_change_since_previous',
                'conversion_rate_percent',
                'conversion_rate_change_percentage_points_since_previous',
                'actions',
                'action_values',
                'conversion_actions',
            ], ',', '"', '');

            foreach ($rows as $row) {
                $fields = [
                    $sourceLabel,
                    $row['campaign_name'],
                    $row['synced_at']->timezone('Asia/Taipei')->format('Y-m-d H:i:s'),
                    $row['spend_amount'],
                    $row['spend_change'] === null ? '' : number_format($row['spend_change'], 4, '.', ''),
                    $row['currency_code'],
                    (string) $row['impressions'],
                    $row['impressions_change'] === null ? '' : (string) $row['impressions_change'],
                    $row['reach'] === null ? '' : (string) $row['reach'],
                    $row['reach_change'] === null ? '' : (string) $row['reach_change'],
                    $row['frequency'] === null ? '' : number_format($row['frequency'], 4, '.', ''),
                    $row['frequency_change'] === null ? '' : number_format($row['frequency_change'], 4, '.', ''),
                    (string) $row['clicks'],
                    $row['clicks_change'] === null ? '' : (string) $row['clicks_change'],
                    $row['conversions'] ?? '',
                    $row['conversions_change'] === null ? '' : number_format($row['conversions_change'], 4, '.', ''),
                    $row['conversions_value'] ?? '',
                    $row['conversions_value_change'] === null ? '' : number_format($row['conversions_value_change'], 4, '.', ''),
                    $row['roas_value'] ?? '',
                    $row['roas'] === null ? '' : number_format($row['roas'], 2, '.', ''),
                    $row['roas_change'] === null ? '' : number_format($row['roas_change'], 2, '.', ''),
                    $row['ctr_percent'] === null ? '' : number_format($row['ctr_percent'], 2, '.', ''),
                    $row['ctr_change_percentage_points'] === null ? '' : number_format($row['ctr_change_percentage_points'], 2, '.', ''),
                    $row['cost_per_click'] === null ? '' : number_format($row['cost_per_click'], 4, '.', ''),
                    $row['cost_per_click_change'] === null ? '' : number_format($row['cost_per_click_change'], 4, '.', ''),
                    $row['cost_per_thousand_impressions'] === null ? '' : number_format($row['cost_per_thousand_impressions'], 4, '.', ''),
                    $row['cost_per_thousand_impressions_change'] === null ? '' : number_format($row['cost_per_thousand_impressions_change'], 4, '.', ''),
                    $row['cost_per_conversion'] === null ? '' : number_format($row['cost_per_conversion'], 4, '.', ''),
                    $row['cost_per_conversion_change'] === null ? '' : number_format($row['cost_per_conversion_change'], 4, '.', ''),
                    $row['conversion_rate_percent'] === null ? '' : number_format($row['conversion_rate_percent'], 2, '.', ''),
                    $row['conversion_rate_change_percentage_points'] === null ? '' : number_format($row['conversion_rate_change_percentage_points'], 2, '.', ''),
                    json_encode($row['actions'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    json_encode($row['action_values'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    json_encode($row['conversion_actions'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ];

                fputcsv($stream, array_map(CsvFormulaSanitizer::escape(...), $fields), ',', '"', '');
            }

            fclose($stream);
        }, $filePrefix.'-campaign-trend-'.$socialEngagementTarget->getKey().'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
