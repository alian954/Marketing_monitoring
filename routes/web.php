<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FeatureGuideController;
use App\Http\Controllers\GoogleAdsIntegrationController;
use App\Http\Controllers\GoogleAnalyticsIntegrationController;
use App\Http\Controllers\IncidentController;
use App\Http\Controllers\IncidentHistoryController;
use App\Http\Controllers\MaintenanceWindowController;
use App\Http\Controllers\MetaMarketingIntegrationController;
use App\Http\Controllers\MonitorController;
use App\Http\Controllers\MonitorImportController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\RobotsReviewController;
use App\Http\Controllers\SocialEngagementCampaignReportController;
use App\Http\Controllers\SocialEngagementTargetController;
use App\Http\Controllers\SocialEngagementTargetGoogleAdsController;
use App\Http\Controllers\SocialEngagementTargetGoogleAnalyticsController;
use App\Http\Controllers\SocialEngagementTargetMetaMarketingController;
use App\Http\Controllers\StatusPageController;
use App\Http\Controllers\StatusPageSubscriptionController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthController::class, 'create'])->name('login');
    Route::post('/login', [AuthController::class, 'store'])->middleware('throttle:login')->name('login.store');
    Route::get('/forgot-password', [AuthController::class, 'showLinkRequestForm'])->name('password.request');
    Route::post('/forgot-password', [AuthController::class, 'sendResetLink'])->middleware('throttle:5,1')->name('password.email');
    Route::get('/reset-password/{token}', [AuthController::class, 'showResetForm'])->name('password.reset');
    Route::post('/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:5,1')->name('password.update');
});

Route::get('/status/{token}', [StatusPageController::class, 'show'])
    ->where('token', '[A-Za-z0-9]{64}')
    ->name('status-pages.public');
Route::post('/status/{token}/subscriptions', [StatusPageSubscriptionController::class, 'subscribe'])
    ->where('token', '[A-Za-z0-9]{64}')
    ->middleware('throttle:status-page-subscription')
    ->name('status-page-subscriptions.subscribe');
Route::get('/status-subscriptions/{subscription}/confirm', [StatusPageSubscriptionController::class, 'showConfirmation'])
    ->whereNumber('subscription')
    ->name('status-page-subscriptions.confirm');
Route::post('/status-subscriptions/{subscription}/confirm', [StatusPageSubscriptionController::class, 'confirm'])
    ->whereNumber('subscription')
    ->name('status-page-subscriptions.confirm.store');
Route::get('/status-subscriptions/{subscription}/unsubscribe', [StatusPageSubscriptionController::class, 'showUnsubscribe'])
    ->whereNumber('subscription')
    ->middleware('signed')
    ->name('status-page-subscriptions.unsubscribe');
Route::post('/status-subscriptions/{subscription}/unsubscribe', [StatusPageSubscriptionController::class, 'unsubscribe'])
    ->whereNumber('subscription')
    ->middleware('signed')
    ->name('status-page-subscriptions.unsubscribe.store');

Route::middleware('auth')->group(function (): void {
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/social-post-maturity/export.csv', [DashboardController::class, 'exportSocialPostMaturity'])->name('social-post-maturity.export');
    Route::get('/social-engagement-targets/export.csv', [DashboardController::class, 'exportSocialEngagementTargets'])->name('social-engagement-targets.export');
    Route::get('/social-engagement-targets/{socialEngagementTarget}/report', [SocialEngagementCampaignReportController::class, 'show'])->whereNumber('socialEngagementTarget')->name('social-engagement-targets.report');
    Route::get('/social-engagement-targets/{socialEngagementTarget}/report.csv', [SocialEngagementCampaignReportController::class, 'exportCampaignSummary'])
        ->whereNumber('socialEngagementTarget')
        ->name('social-engagement-targets.report.export');
    Route::get('/social-engagement-targets/{socialEngagementTarget}/posts.csv', [SocialEngagementCampaignReportController::class, 'exportCampaignPosts'])
        ->whereNumber('socialEngagementTarget')
        ->middleware('throttle:10,1')
        ->name('social-engagement-targets.report.posts.export');
    Route::get('/social-engagement-targets/{socialEngagementTarget}/daily-marketing-performance.csv', [SocialEngagementCampaignReportController::class, 'exportDailyMarketingPerformance'])
        ->whereNumber('socialEngagementTarget')
        ->middleware('throttle:10,1')
        ->name('social-engagement-targets.daily-marketing-performance');
    Route::get('/social-engagement-targets/{socialEngagementTarget}/google-ads-search-terms.csv', [SocialEngagementCampaignReportController::class, 'exportGoogleAdsSearchTerms'])
        ->whereNumber('socialEngagementTarget')
        ->middleware('throttle:10,1')
        ->name('social-engagement-targets.google-ads.search-terms');
    Route::get('/social-engagement-targets/{socialEngagementTarget}/google-ads-keywords.csv', [SocialEngagementCampaignReportController::class, 'exportGoogleAdsKeywords'])
        ->whereNumber('socialEngagementTarget')
        ->middleware('throttle:10,1')
        ->name('social-engagement-targets.google-ads.keywords');
    Route::get('/social-engagement-targets/{socialEngagementTarget}/google-ads-delivery-units.csv', [SocialEngagementCampaignReportController::class, 'exportGoogleAdsDeliveryUnits'])
        ->whereNumber('socialEngagementTarget')
        ->middleware('throttle:10,1')
        ->name('social-engagement-targets.google-ads.delivery-units');
    Route::get('/social-engagement-targets/{socialEngagementTarget}/google-ads-devices.csv', [SocialEngagementCampaignReportController::class, 'exportGoogleAdsDevices'])
        ->whereNumber('socialEngagementTarget')
        ->middleware('throttle:10,1')
        ->name('social-engagement-targets.google-ads.devices');
    Route::get('/social-engagement-targets/{socialEngagementTarget}/google-ads-auction-insights.csv', [SocialEngagementCampaignReportController::class, 'exportGoogleAdsAuctionInsights'])
        ->whereNumber('socialEngagementTarget')
        ->middleware('throttle:10,1')
        ->name('social-engagement-targets.google-ads.auction-insights');
    Route::get('/social-engagement-targets/{socialEngagementTarget}/google-ads-responsive-search-ad-assets.csv', [SocialEngagementCampaignReportController::class, 'exportGoogleAdsResponsiveSearchAdAssets'])
        ->whereNumber('socialEngagementTarget')
        ->middleware('throttle:10,1')
        ->name('social-engagement-targets.google-ads.responsive-search-ad-assets');
    Route::get('/social-engagement-targets/{socialEngagementTarget}/google-ads-performance-max-assets.csv', [SocialEngagementCampaignReportController::class, 'exportGoogleAdsPerformanceMaxAssets'])
        ->whereNumber('socialEngagementTarget')
        ->middleware('throttle:10,1')
        ->name('social-engagement-targets.google-ads.performance-max-assets');
    Route::get('/social-engagement-targets/{socialEngagementTarget}/meta-marketing-ads.csv', [SocialEngagementCampaignReportController::class, 'exportMetaMarketingAds'])
        ->whereNumber('socialEngagementTarget')
        ->middleware('throttle:10,1')
        ->name('social-engagement-targets.meta-marketing.ads');
    Route::get('/social-engagement-targets/{socialEngagementTarget}/meta-marketing-adsets.csv', [SocialEngagementCampaignReportController::class, 'exportMetaMarketingAdSets'])
        ->whereNumber('socialEngagementTarget')
        ->middleware('throttle:10,1')
        ->name('social-engagement-targets.meta-marketing.adsets');
    Route::get('/social-engagement-targets/{socialEngagementTarget}/meta-marketing-placements.csv', [SocialEngagementCampaignReportController::class, 'exportMetaMarketingPlacements'])
        ->whereNumber('socialEngagementTarget')
        ->middleware('throttle:10,1')
        ->name('social-engagement-targets.meta-marketing.placements');
    Route::get('/social-engagement-targets/{socialEngagementTarget}/meta-marketing-demographics.csv', [SocialEngagementCampaignReportController::class, 'exportMetaMarketingDemographics'])
        ->whereNumber('socialEngagementTarget')
        ->middleware('throttle:10,1')
        ->name('social-engagement-targets.meta-marketing.demographics');
    Route::get('/social-engagement-targets/{socialEngagementTarget}/google-analytics-channels.csv', [SocialEngagementCampaignReportController::class, 'exportGoogleAnalyticsChannelBreakdown'])
        ->whereNumber('socialEngagementTarget')
        ->middleware('throttle:10,1')
        ->name('social-engagement-targets.google-analytics.channels');
    Route::get('/social-engagement-targets/{socialEngagementTarget}/google-analytics-landing-pages.csv', [SocialEngagementCampaignReportController::class, 'exportGoogleAnalyticsLandingPages'])
        ->whereNumber('socialEngagementTarget')
        ->middleware('throttle:10,1')
        ->name('social-engagement-targets.google-analytics.landing-pages');
    Route::get('/social-engagement-targets/{socialEngagementTarget}/google-analytics-events.csv', [SocialEngagementCampaignReportController::class, 'exportGoogleAnalyticsEventNames'])
        ->whereNumber('socialEngagementTarget')
        ->middleware('throttle:10,1')
        ->name('social-engagement-targets.google-analytics.events');
    Route::get('/social-engagement-targets/{socialEngagementTarget}/google-analytics-items.csv', [SocialEngagementCampaignReportController::class, 'exportGoogleAnalyticsItems'])
        ->whereNumber('socialEngagementTarget')
        ->middleware('throttle:10,1')
        ->name('social-engagement-targets.google-analytics.items');
    Route::get('/social-engagement-targets/{socialEngagementTarget}/google-analytics-ad-content.csv', [SocialEngagementCampaignReportController::class, 'exportGoogleAnalyticsAdContent'])
        ->whereNumber('socialEngagementTarget')
        ->middleware('throttle:10,1')
        ->name('social-engagement-targets.google-analytics.ad-content');
    Route::get('/social-engagement-targets/{socialEngagementTarget}/google-analytics-new-vs-returning.csv', [SocialEngagementCampaignReportController::class, 'exportGoogleAnalyticsNewVsReturning'])
        ->whereNumber('socialEngagementTarget')
        ->middleware('throttle:10,1')
        ->name('social-engagement-targets.google-analytics.new-vs-returning');
    Route::get('/social-engagement-targets/{socialEngagementTarget}/google-analytics-history.csv', [SocialEngagementCampaignReportController::class, 'exportGoogleAnalyticsHistory'])
        ->whereNumber('socialEngagementTarget')
        ->name('social-engagement-targets.google-analytics.history');
    Route::get('/social-engagement-targets/{socialEngagementTarget}/paid-media-history/{source}.csv', [SocialEngagementCampaignReportController::class, 'exportPaidMediaHistory'])
        ->whereNumber('socialEngagementTarget')
        ->where('source', 'meta-marketing|google-ads')
        ->name('social-engagement-targets.paid-media.history');
    Route::post('/social-engagement-targets/{socialEngagementTarget}/google-analytics', [SocialEngagementTargetGoogleAnalyticsController::class, 'sync'])
        ->whereNumber('socialEngagementTarget')
        ->middleware('throttle:10,1')
        ->name('social-engagement-targets.google-analytics.sync');
    Route::post('/social-engagement-targets/{socialEngagementTarget}/meta-marketing', [SocialEngagementTargetMetaMarketingController::class, 'sync'])
        ->whereNumber('socialEngagementTarget')
        ->middleware('throttle:10,1')
        ->name('social-engagement-targets.meta-marketing.sync');
    Route::post('/social-engagement-targets/{socialEngagementTarget}/google-ads', [SocialEngagementTargetGoogleAdsController::class, 'sync'])
        ->whereNumber('socialEngagementTarget')
        ->middleware('throttle:10,1')
        ->name('social-engagement-targets.google-ads.sync');
    Route::post('/social-engagement-targets/{socialEngagementTarget}/google-ads/campaign-options', [SocialEngagementTargetGoogleAdsController::class, 'campaignOptions'])
        ->whereNumber('socialEngagementTarget')
        ->middleware('throttle:10,1')
        ->name('social-engagement-targets.google-ads.campaign-options');
    Route::put('/social-engagement-targets/{socialEngagementTarget}/google-ads/campaign', [SocialEngagementTargetGoogleAdsController::class, 'updateCampaign'])
        ->whereNumber('socialEngagementTarget')
        ->name('social-engagement-targets.google-ads.campaign.update');
    Route::put('/social-engagement-targets/{socialEngagementTarget}/ad-spend', [SocialEngagementTargetController::class, 'updateAdSpend'])
        ->whereNumber('socialEngagementTarget')
        ->name('social-engagement-targets.ad-spend.update');
    Route::put('/social-engagement-targets/{socialEngagementTarget}/budget', [SocialEngagementTargetController::class, 'updateBudget'])
        ->whereNumber('socialEngagementTarget')
        ->name('social-engagement-targets.budget.update');
    Route::post('/social-engagement-targets', [SocialEngagementTargetController::class, 'store'])->name('social-engagement-targets.store');
    Route::delete('/social-engagement-targets/{socialEngagementTarget}', [SocialEngagementTargetController::class, 'destroy'])->name('social-engagement-targets.destroy');
    Route::get('/integrations/google-analytics', [GoogleAnalyticsIntegrationController::class, 'show'])->name('integrations.google-analytics.edit');
    Route::post('/integrations/google-analytics/connect', [GoogleAnalyticsIntegrationController::class, 'connect'])->name('integrations.google-analytics.connect');
    Route::get('/integrations/google-analytics/callback', [GoogleAnalyticsIntegrationController::class, 'callback'])->name('integrations.google-analytics.callback');
    Route::put('/integrations/google-analytics/property', [GoogleAnalyticsIntegrationController::class, 'updateProperty'])->name('integrations.google-analytics.property.update');
    Route::delete('/integrations/google-analytics/connection', [GoogleAnalyticsIntegrationController::class, 'disconnect'])->name('integrations.google-analytics.disconnect');
    Route::get('/integrations/meta-marketing', [MetaMarketingIntegrationController::class, 'show'])->name('integrations.meta-marketing.edit');
    Route::post('/integrations/meta-marketing/connect', [MetaMarketingIntegrationController::class, 'connect'])->name('integrations.meta-marketing.connect');
    Route::get('/integrations/meta-marketing/callback', [MetaMarketingIntegrationController::class, 'callback'])->name('integrations.meta-marketing.callback');
    Route::put('/integrations/meta-marketing/ad-account', [MetaMarketingIntegrationController::class, 'updateAdAccount'])->name('integrations.meta-marketing.ad-account.update');
    Route::delete('/integrations/meta-marketing/connection', [MetaMarketingIntegrationController::class, 'disconnect'])->name('integrations.meta-marketing.disconnect');
    Route::get('/integrations/google-ads', [GoogleAdsIntegrationController::class, 'show'])->name('integrations.google-ads.edit');
    Route::post('/integrations/google-ads/connect', [GoogleAdsIntegrationController::class, 'connect'])->name('integrations.google-ads.connect');
    Route::get('/integrations/google-ads/callback', [GoogleAdsIntegrationController::class, 'callback'])->name('integrations.google-ads.callback');
    Route::put('/integrations/google-ads/customer', [GoogleAdsIntegrationController::class, 'updateCustomer'])->name('integrations.google-ads.customer.update');
    Route::delete('/integrations/google-ads/connection', [GoogleAdsIntegrationController::class, 'disconnect'])->name('integrations.google-ads.disconnect');
    Route::get('/features', [FeatureGuideController::class, 'index'])->name('features.index');
    Route::get('/account/password', [AuthController::class, 'editPassword'])->name('account.password.edit');
    Route::put('/account/password', [AuthController::class, 'updatePassword'])->name('account.password.update');
    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::get('/notifications/export.csv', [NotificationController::class, 'export'])->name('notifications.export');
    Route::post('/notifications/test', [NotificationController::class, 'sendTestNotification'])
        ->middleware('throttle:notification-test')
        ->name('notifications.test');
    Route::get('/incidents', [IncidentHistoryController::class, 'index'])->name('incidents.index');
    Route::get('/incidents/export.csv', [IncidentHistoryController::class, 'export'])->name('incidents.export');
    Route::post('/incidents/bulk-acknowledge', [IncidentController::class, 'bulkAcknowledge'])->name('incidents.bulk-acknowledge');
    Route::post('/logout', [AuthController::class, 'destroy'])->name('logout');
    Route::get('/status-page', [StatusPageController::class, 'edit'])->name('status-pages.edit');
    Route::post('/status-page', [StatusPageController::class, 'update'])->name('status-pages.update');
    Route::post('/status-page/monitors/{monitor}/toggle', [StatusPageController::class, 'toggleMonitor'])->name('status-pages.monitors.toggle');

    Route::get('/monitors', [MonitorController::class, 'index'])->name('monitors.index');
    Route::get('/monitors/export.csv', [MonitorController::class, 'export'])->name('monitors.export');
    Route::get('/monitors/availability/export.csv', [MonitorController::class, 'exportFleetAvailability'])->name('monitors.availability.export.fleet');
    Route::get('/monitors/social-engagement/export.csv', [MonitorController::class, 'exportSocialEngagement'])->name('monitors.social-engagement.export');
    Route::get('/monitors/social-engagement/history.csv', [MonitorController::class, 'exportSocialEngagementHistory'])->name('monitors.social-engagement.export.history');
    Route::get('/monitors/social-engagement/growth.csv', [DashboardController::class, 'exportSocialEngagementGrowth'])->name('monitors.social-engagement.export.growth');
    Route::get('/monitors/{monitor}/availability.csv', [MonitorController::class, 'exportAvailability'])->name('monitors.availability.export');
    Route::get('/monitors/{monitor}/checks/export.csv', [MonitorController::class, 'exportCheckRuns'])->name('monitors.checks.export');
    Route::get('/monitors/{monitor}/social-engagement/history.csv', [MonitorController::class, 'exportMonitorSocialEngagementHistory'])->name('monitors.social-engagement.export.monitor-history');
    Route::get('/monitors/{monitor}/dns-changes/export.csv', [MonitorController::class, 'exportDnsChanges'])->name('monitors.dns-changes.export');
    Route::get('/monitors/import', [MonitorImportController::class, 'create'])->name('monitors.import');
    Route::get('/monitors/import/template.csv', [MonitorImportController::class, 'template'])->name('monitors.import.template');
    Route::post('/monitors/import/preview', [MonitorImportController::class, 'preview'])->name('monitors.import.preview');
    Route::post('/monitors/import/commit', [MonitorImportController::class, 'commit'])->name('monitors.import.commit');
    Route::post('/monitors/probe-preview', [MonitorController::class, 'previewProbe'])
        ->middleware('throttle:monitor-probe-preview')
        ->name('monitors.probe-preview');
    Route::match(['POST', 'PUT'], '/monitors/{monitor}/probe-preview', [MonitorController::class, 'previewExistingProbe'])
        ->middleware('throttle:monitor-probe-preview')
        ->name('monitors.probe-preview.update');
    Route::get('/monitors/create', [MonitorController::class, 'create'])->name('monitors.create');
    Route::post('/monitors', [MonitorController::class, 'store'])->name('monitors.store');
    Route::post('/monitors/bulk-status', [MonitorController::class, 'bulkStatus'])->name('monitors.bulk-status');
    Route::get('/monitors/{monitor}/edit', [MonitorController::class, 'edit'])->name('monitors.edit');
    Route::put('/monitors/{monitor}', [MonitorController::class, 'update'])->name('monitors.update');
    Route::post('/monitors/{monitor}/toggle', [MonitorController::class, 'toggle'])->name('monitors.toggle');
    Route::post('/monitors/{monitor}/check', [MonitorController::class, 'checkNow'])->name('monitors.check');
    Route::delete('/monitors/{monitor}', [MonitorController::class, 'destroy'])->name('monitors.destroy');
    Route::post('/monitors/{monitor}/maintenance-windows', [MaintenanceWindowController::class, 'store'])->name('monitors.maintenance-windows.store');
    Route::delete('/monitors/{monitor}/maintenance-windows/{maintenanceWindow}', [MaintenanceWindowController::class, 'destroy'])->name('monitors.maintenance-windows.destroy');
    Route::post('/monitors/{monitor}/notifications/test', [NotificationController::class, 'sendMonitorTestNotification'])
        ->middleware('throttle:notification-test')
        ->name('monitors.notifications.test');
    Route::get('/monitors/{monitor}/robots', [RobotsReviewController::class, 'show'])->name('monitors.robots');
    Route::get('/monitors/{monitor}', [MonitorController::class, 'show'])->name('monitors.show');

    Route::post('/incidents/{incident}/acknowledgement', [IncidentController::class, 'acknowledge'])->name('incidents.acknowledgement.store');
    Route::delete('/incidents/{incident}/acknowledgement', [IncidentController::class, 'unacknowledge'])->name('incidents.acknowledgement.destroy');
    Route::post('/incidents/{incident}/notification-snooze', [IncidentController::class, 'snoozeNotifications'])->name('incidents.notifications.snooze');
    Route::delete('/incidents/{incident}/notification-snooze', [IncidentController::class, 'resumeNotifications'])->name('incidents.notifications.snooze.destroy');
    Route::patch('/incidents/{incident}', [IncidentController::class, 'update'])->name('incidents.update');
    Route::post('/notifications/{outbox}/retry', [IncidentController::class, 'retryOutbox'])->name('notifications.retry');
});
