<?php

use App\Http\Controllers\ApiTokenController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ClientAdPlatformImportController;
use App\Http\Controllers\ClientBudgetController;
use App\Http\Controllers\ClientCompetitorController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\ClientCrossPlatformOverviewController;
use App\Http\Controllers\ClientCurrencyController;
use App\Http\Controllers\ClientKeywordController;
use App\Http\Controllers\ClientLeadController;
use App\Http\Controllers\ClientMarketingAnnotationController;
use App\Http\Controllers\ClientMarketingInsightController;
use App\Http\Controllers\ClientPortfolioController;
use App\Http\Controllers\ClientReportScheduleController;
use App\Http\Controllers\ClientReportShareController;
use App\Http\Controllers\ClientReportSnapshotController;
use App\Http\Controllers\ClientReportTemplateController;
use App\Http\Controllers\ClientStoreRevenueController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FeatureGuideController;
use App\Http\Controllers\GoogleAdsIntegrationController;
use App\Http\Controllers\GoogleAnalyticsIntegrationController;
use App\Http\Controllers\GoogleSearchConsoleIntegrationController;
use App\Http\Controllers\IncidentController;
use App\Http\Controllers\IncidentHistoryController;
use App\Http\Controllers\IntegrationApplicationCredentialController;
use App\Http\Controllers\LineMessagingIntegrationController;
use App\Http\Controllers\LineWebhookController;
use App\Http\Controllers\MaintenanceWindowController;
use App\Http\Controllers\MarketingAlertRuleController;
use App\Http\Controllers\MetaMarketingIntegrationController;
use App\Http\Controllers\MonitorController;
use App\Http\Controllers\MonitorImportController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PlatformAuditEventController;
use App\Http\Controllers\PlatformAuthController;
use App\Http\Controllers\PlatformBillingController;
use App\Http\Controllers\PlatformEntitlementMigrationController;
use App\Http\Controllers\PlatformOrganizationController;
use App\Http\Controllers\PlatformProductController;
use App\Http\Controllers\PlatformSubscriptionController;
use App\Http\Controllers\PlatformSystemStatusController;
use App\Http\Controllers\PlatformTrialRequestController;
use App\Http\Controllers\RobotsReviewController;
use App\Http\Controllers\SharedClientReportController;
use App\Http\Controllers\SocialEngagementCampaignReportController;
use App\Http\Controllers\SocialEngagementTargetController;
use App\Http\Controllers\SocialEngagementTargetGoogleAdsController;
use App\Http\Controllers\SocialEngagementTargetGoogleAnalyticsController;
use App\Http\Controllers\SocialEngagementTargetMetaMarketingController;
use App\Http\Controllers\StatusPageController;
use App\Http\Controllers\StatusPageSubscriptionController;
use App\Http\Controllers\TrialRequestController;
use App\Http\Controllers\TwoFactorChallengeController;
use App\Http\Controllers\TwoFactorSettingsController;
use App\Http\Controllers\WorkspaceAccessController;
use App\Http\Controllers\WorkspaceActivityController;
use App\Http\Controllers\WorkspaceSwitchController;
use App\Http\Middleware\EnforceProductFeatureAvailability;
use App\Http\Middleware\EnsureCustomerAccount;
use App\Http\Middleware\EnsureOrganizationActive;
use App\Http\Middleware\EnsurePlatformAdmin;
use App\Http\Middleware\EnsureWorkspaceOwner;
use App\Http\Middleware\RejectReadOnlyWorkspaceMemberWrites;
use App\Services\PlatformConsole;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthController::class, 'create'])->name('login');
    Route::post('/login', [AuthController::class, 'store'])->middleware('throttle:login')->name('login.store');
    Route::get('/forgot-password', [AuthController::class, 'showLinkRequestForm'])->name('password.request');
    Route::post('/forgot-password', [AuthController::class, 'sendResetLink'])->middleware('throttle:5,1')->name('password.email');
    Route::get('/reset-password/{token}', [AuthController::class, 'showResetForm'])->name('password.reset');
    Route::post('/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:5,1')->name('password.update');
    Route::get('/two-factor-challenge', [TwoFactorChallengeController::class, 'create'])->name('two-factor.challenge');
    Route::post('/two-factor-challenge', [TwoFactorChallengeController::class, 'store'])->middleware('throttle:login')->name('two-factor.challenge.store');
});

Route::get('/status/{token}', [StatusPageController::class, 'show'])
    ->where('token', '[A-Za-z0-9]{64}')
    ->middleware(EnforceProductFeatureAvailability::class)
    ->name('status-pages.public');
Route::get('/shared/client-reports/{token}', SharedClientReportController::class)
    ->where('token', '[a-f0-9]{64}')
    ->middleware('throttle:60,1')
    ->middleware(EnforceProductFeatureAvailability::class)
    ->name('client-report-shares.public');
Route::get('/shared/client-reports/{token}/export.csv', [SharedClientReportController::class, 'export'])
    ->where('token', '[a-f0-9]{64}')
    ->middleware('throttle:10,1')
    ->middleware(EnforceProductFeatureAvailability::class)
    ->name('client-report-shares.public.export');
Route::get('/shared/client-reports/{token}/snapshots/{snapshot}/export.csv', [ClientReportSnapshotController::class, 'downloadPublic'])
    ->where('token', '[a-f0-9]{64}')
    ->whereNumber('snapshot')
    ->middleware('throttle:10,1')
    ->middleware(EnforceProductFeatureAvailability::class)
    ->name('client-report-shares.public.snapshots.export');
Route::post('/status/{token}/subscriptions', [StatusPageSubscriptionController::class, 'subscribe'])
    ->where('token', '[A-Za-z0-9]{64}')
    ->middleware('throttle:status-page-subscription')
    ->middleware(EnforceProductFeatureAvailability::class)
    ->name('status-page-subscriptions.subscribe');
Route::post('/webhooks/line/{webhookKey}', LineWebhookController::class)
    ->where('webhookKey', '[A-Za-z0-9]{64}')
    ->middleware('throttle:120,1')
    ->name('line.webhook');
Route::get('/status-subscriptions/{subscription}/confirm', [StatusPageSubscriptionController::class, 'showConfirmation'])
    ->whereNumber('subscription')
    ->middleware(EnforceProductFeatureAvailability::class)
    ->name('status-page-subscriptions.confirm');
Route::post('/status-subscriptions/{subscription}/confirm', [StatusPageSubscriptionController::class, 'confirm'])
    ->whereNumber('subscription')
    ->middleware(EnforceProductFeatureAvailability::class)
    ->name('status-page-subscriptions.confirm.store');
Route::get('/status-subscriptions/{subscription}/unsubscribe', [StatusPageSubscriptionController::class, 'showUnsubscribe'])
    ->whereNumber('subscription')
    ->middleware('signed')
    ->name('status-page-subscriptions.unsubscribe');
Route::post('/status-subscriptions/{subscription}/unsubscribe', [StatusPageSubscriptionController::class, 'unsubscribe'])
    ->whereNumber('subscription')
    ->middleware('signed')
    ->name('status-page-subscriptions.unsubscribe.store');

Route::middleware('guest')->prefix('team/invitations')->name('team.invitations.')->group(function (): void {
    Route::get('/{token}', [WorkspaceAccessController::class, 'showInvitation'])
        ->where('token', '[A-Za-z0-9]{64}')
        ->name('show');
    Route::post('/{token}', [WorkspaceAccessController::class, 'acceptInvitation'])
        ->where('token', '[A-Za-z0-9]{64}')
        ->middleware('throttle:5,1')
        ->name('accept');
});

if (PlatformConsole::isEnabled()) {
    $consoleRoutes = Route::prefix(PlatformConsole::PATH_PREFIX)->name('platform.');

    if (PlatformConsole::domain() !== null) {
        $consoleRoutes->domain(PlatformConsole::domain());
    }

    $consoleRoutes->group(function (): void {
        Route::middleware('guest:platform')->group(function (): void {
            Route::get('/login', [PlatformAuthController::class, 'create'])->name('login');
            Route::post('/login', [PlatformAuthController::class, 'store'])->middleware('throttle:login')->name('login.store');
            Route::get('/two-factor-challenge', [TwoFactorChallengeController::class, 'create'])->name('two-factor.challenge');
            Route::post('/two-factor-challenge', [TwoFactorChallengeController::class, 'store'])->middleware('throttle:login')->name('two-factor.challenge.store');
        });

        Route::middleware(['auth:platform', EnsurePlatformAdmin::class])->group(function (): void {
            Route::post('/logout', [PlatformAuthController::class, 'destroy'])->name('logout');
            Route::get('/account/security', [TwoFactorSettingsController::class, 'show'])->name('account.security');
            Route::post('/account/two-factor', [TwoFactorSettingsController::class, 'enable'])->middleware('throttle:10,1')->name('account.two-factor.enable');
            Route::post('/account/two-factor/confirm', [TwoFactorSettingsController::class, 'confirm'])->middleware('throttle:10,1')->name('account.two-factor.confirm');
            Route::post('/account/two-factor/recovery-codes', [TwoFactorSettingsController::class, 'regenerateRecoveryCodes'])->middleware('throttle:10,1')->name('account.two-factor.recovery-codes');
            Route::delete('/account/two-factor', [TwoFactorSettingsController::class, 'disable'])->middleware('throttle:10,1')->name('account.two-factor.disable');
            Route::get('/', [PlatformOrganizationController::class, 'index'])->name('index');
            Route::get('/products', [PlatformProductController::class, 'index'])->name('products.index');
            Route::get('/audit-events', [PlatformAuditEventController::class, 'index'])->name('audit-events.index');
            Route::get('/trial-requests', [PlatformTrialRequestController::class, 'index'])->name('trial-requests.index');
            Route::get('/entitlement-migration', [PlatformEntitlementMigrationController::class, 'index'])->name('entitlement-migration.index');
            Route::post('/entitlement-migration/subscriptions', [PlatformEntitlementMigrationController::class, 'mapSubscriptions'])->name('entitlement-migration.subscriptions');
            Route::post('/entitlement-migration/enforce', [PlatformEntitlementMigrationController::class, 'enforce'])->name('entitlement-migration.enforce');
            Route::post('/trial-requests/{trialRequest}/approve', [PlatformTrialRequestController::class, 'approve'])
                ->whereNumber('trialRequest')
                ->name('trial-requests.approve');
            Route::post('/trial-requests/{trialRequest}/reject', [PlatformTrialRequestController::class, 'reject'])
                ->whereNumber('trialRequest')
                ->name('trial-requests.reject');
            Route::patch('/products/{product}/lifecycle', [PlatformProductController::class, 'updateLifecycle'])
                ->whereNumber('product')
                ->name('products.lifecycle.update');
            Route::post('/products/{product}/plans', [PlatformProductController::class, 'storePlan'])
                ->whereNumber('product')
                ->name('products.plans.store');
            Route::patch('/products/plans/{productPlan}', [PlatformProductController::class, 'updatePlan'])
                ->whereNumber('productPlan')
                ->name('products.plans.update');
            Route::post('/organizations', [PlatformOrganizationController::class, 'store'])->name('organizations.store');
            Route::get('/organizations/{organization}/billing', [PlatformBillingController::class, 'index'])
                ->whereNumber('organization')
                ->name('organizations.billing');
            Route::post('/organizations/{organization}/billing/orders', [PlatformBillingController::class, 'storeOrder'])
                ->whereNumber('organization')
                ->name('organizations.billing.orders.store');
            Route::post('/organizations/{organization}/billing/orders/{billingOrder}/payments', [PlatformBillingController::class, 'storePayment'])
                ->whereNumber('organization')
                ->whereNumber('billingOrder')
                ->name('organizations.billing.payments.store');
            Route::post('/organizations/{organization}/billing/orders/{billingOrder}/refunds', [PlatformBillingController::class, 'storeRefund'])
                ->whereNumber('organization')
                ->whereNumber('billingOrder')
                ->name('organizations.billing.refunds.store');
            Route::post('/organizations/{organization}/billing/orders/{billingOrder}/cancel', [PlatformBillingController::class, 'cancelOrder'])
                ->whereNumber('organization')
                ->whereNumber('billingOrder')
                ->name('organizations.billing.orders.cancel');
            Route::get('/organizations/{organization}/products', [PlatformSubscriptionController::class, 'index'])
                ->whereNumber('organization')
                ->name('organizations.products');
            Route::post('/organizations/{organization}/subscriptions', [PlatformSubscriptionController::class, 'store'])
                ->whereNumber('organization')
                ->name('organizations.subscriptions.store');
            Route::patch('/organizations/{organization}/subscriptions/{subscription}', [PlatformSubscriptionController::class, 'update'])
                ->whereNumber('organization')
                ->whereNumber('subscription')
                ->name('organizations.subscriptions.update');
            Route::post('/organizations/{organization}/subscriptions/{subscription}/items', [PlatformSubscriptionController::class, 'storeItem'])
                ->whereNumber('organization')
                ->whereNumber('subscription')
                ->name('organizations.subscriptions.items.store');
            Route::patch('/organizations/{organization}/subscriptions/{subscription}/items/{subscriptionItem}', [PlatformSubscriptionController::class, 'updateItem'])
                ->whereNumber('organization')
                ->whereNumber('subscription')
                ->whereNumber('subscriptionItem')
                ->name('organizations.subscriptions.items.update');
            Route::patch('/organizations/{organization}', [PlatformOrganizationController::class, 'update'])
                ->whereNumber('organization')
                ->name('organizations.update');
            Route::patch('/organizations/{organization}/entitlement-enforcement', [PlatformOrganizationController::class, 'updateEntitlementEnforcement'])
                ->whereNumber('organization')
                ->name('organizations.entitlement-enforcement.update');
            Route::get('/organizations/{organization}', [PlatformOrganizationController::class, 'show'])
                ->whereNumber('organization')
                ->name('organizations.show');
            Route::delete('/organizations/{organization}/data', [PlatformOrganizationController::class, 'purgeData'])
                ->whereNumber('organization')
                ->middleware('throttle:10,1')
                ->name('organizations.data.purge');
            Route::get('/system', [PlatformSystemStatusController::class, 'index'])->name('system.index');
        });
    });

    if (PlatformConsole::domain() === null) {
        Route::redirect('/platform', '/'.PlatformConsole::PATH_PREFIX);
    }
}

Route::middleware(['auth', EnsureCustomerAccount::class])->group(function (): void {
    Route::get('/team/invitations/{token}/join', [WorkspaceAccessController::class, 'showJoinInvitation'])
        ->where('token', '[A-Za-z0-9]{64}')
        ->name('team.invitations.join.show');
    Route::post('/team/invitations/{token}/join', [WorkspaceAccessController::class, 'joinInvitation'])
        ->where('token', '[A-Za-z0-9]{64}')
        ->middleware('throttle:5,1')
        ->name('team.invitations.join');
    Route::post('/workspaces/switch', WorkspaceSwitchController::class)
        ->middleware('throttle:30,1')
        ->name('workspaces.switch');
});

Route::middleware(['auth', EnsureCustomerAccount::class, EnsureOrganizationActive::class, RejectReadOnlyWorkspaceMemberWrites::class, EnforceProductFeatureAvailability::class])->group(function (): void {
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/clients/performance/export.csv', [ClientPortfolioController::class, 'export'])->name('clients.performance.export');
    Route::get('/clients/performance', [ClientPortfolioController::class, 'index'])->name('clients.performance');
    Route::middleware(EnsureWorkspaceOwner::class)->prefix('team')->name('team.')->group(function (): void {
        Route::get('/', [WorkspaceAccessController::class, 'index'])->name('index');
        Route::get('/activity', WorkspaceActivityController::class)->name('activity.index');
        Route::post('/invitations', [WorkspaceAccessController::class, 'storeInvitation'])
            ->middleware('throttle:10,1')
            ->name('invitations.store');
        Route::delete('/invitations/{invitation}', [WorkspaceAccessController::class, 'destroyInvitation'])
            ->whereNumber('invitation')
            ->name('invitations.destroy');
        Route::delete('/members/{membership}', [WorkspaceAccessController::class, 'destroyMember'])
            ->whereNumber('membership')
            ->name('members.destroy');
        Route::patch('/members/{membership}/role', [WorkspaceAccessController::class, 'updateMemberRole'])
            ->whereNumber('membership')
            ->name('members.role.update');
        Route::get('/members/{membership}/clients', [WorkspaceAccessController::class, 'editMemberClients'])
            ->whereNumber('membership')
            ->name('members.clients.edit');
        Route::patch('/members/{membership}/clients', [WorkspaceAccessController::class, 'updateMemberClients'])
            ->whereNumber('membership')
            ->name('members.clients.update');
    });
    Route::middleware(EnsureWorkspaceOwner::class)->prefix('api-tokens')->name('api-tokens.')->group(function (): void {
        Route::get('/', [ApiTokenController::class, 'index'])->name('index');
        Route::post('/', [ApiTokenController::class, 'store'])->middleware('throttle:10,1')->name('store');
        Route::delete('/{apiToken}', [ApiTokenController::class, 'destroy'])->whereNumber('apiToken')->name('destroy');
    });
    Route::get('/clients', [ClientController::class, 'index'])->name('clients.index');
    Route::post('/clients', [ClientController::class, 'store'])->name('clients.store');
    Route::put('/clients/{client}', [ClientController::class, 'update'])->whereNumber('client')->name('clients.update');
    Route::delete('/clients/{client}', [ClientController::class, 'destroy'])->whereNumber('client')->name('clients.destroy');
    Route::get('/clients/{client}/currencies', [ClientCurrencyController::class, 'index'])->whereNumber('client')->name('clients.currencies.index');
    Route::patch('/clients/{client}/currencies/reporting', [ClientCurrencyController::class, 'updateReportingCurrency'])->whereNumber('client')->name('clients.currencies.reporting.update');
    Route::post('/clients/{client}/exchange-rates', [ClientCurrencyController::class, 'storeExchangeRate'])->whereNumber('client')->name('clients.exchange-rates.store');
    Route::put('/clients/{client}/exchange-rates/{exchangeRate}', [ClientCurrencyController::class, 'updateExchangeRate'])
        ->whereNumber('client')
        ->whereNumber('exchangeRate')
        ->name('clients.exchange-rates.update');
    Route::delete('/clients/{client}/exchange-rates/{exchangeRate}', [ClientCurrencyController::class, 'destroyExchangeRate'])
        ->whereNumber('client')
        ->whereNumber('exchangeRate')
        ->name('clients.exchange-rates.destroy');
    Route::get('/clients/{client}/report-shares', [ClientReportShareController::class, 'index'])->whereNumber('client')->name('clients.report-shares.index');
    Route::post('/clients/{client}/report-shares', [ClientReportShareController::class, 'store'])->whereNumber('client')->name('clients.report-shares.store');
    Route::post('/clients/{client}/report-shares/templates', [ClientReportTemplateController::class, 'store'])
        ->whereNumber('client')
        ->name('clients.report-shares.templates.store');
    Route::delete('/clients/{client}/report-shares/templates/{reportTemplate}', [ClientReportTemplateController::class, 'destroy'])
        ->whereNumber('client')
        ->whereNumber('reportTemplate')
        ->scopeBindings()
        ->name('clients.report-shares.templates.destroy');
    Route::delete('/clients/{client}/report-shares/{reportShare}', [ClientReportShareController::class, 'destroy'])
        ->whereNumber('client')
        ->whereNumber('reportShare')
        ->name('clients.report-shares.destroy');
    Route::post('/clients/{client}/report-shares/{reportShare}/snapshots', [ClientReportSnapshotController::class, 'store'])
        ->whereNumber('client')
        ->whereNumber('reportShare')
        ->scopeBindings()
        ->name('clients.report-shares.snapshots.store');
    Route::get('/clients/{client}/report-shares/{reportShare}/snapshots/{snapshot}/export.csv', [ClientReportSnapshotController::class, 'download'])
        ->whereNumber('client')
        ->whereNumber('reportShare')
        ->whereNumber('snapshot')
        ->scopeBindings()
        ->name('clients.report-shares.snapshots.export');
    Route::post('/clients/{client}/report-schedules', [ClientReportScheduleController::class, 'store'])
        ->whereNumber('client')
        ->name('clients.report-schedules.store');
    Route::patch('/clients/{client}/report-schedules/{reportSchedule}', [ClientReportScheduleController::class, 'update'])
        ->whereNumber('client')
        ->whereNumber('reportSchedule')
        ->scopeBindings()
        ->name('clients.report-schedules.update');
    Route::delete('/clients/{client}/report-schedules/{reportSchedule}', [ClientReportScheduleController::class, 'destroy'])
        ->whereNumber('client')
        ->whereNumber('reportSchedule')
        ->scopeBindings()
        ->name('clients.report-schedules.destroy');
    Route::get('/clients/{client}/cross-platform', [ClientCrossPlatformOverviewController::class, 'show'])
        ->whereNumber('client')
        ->name('clients.cross-platform');
    Route::post('/clients/{client}/insights', [ClientMarketingInsightController::class, 'store'])
        ->whereNumber('client')
        ->middleware('throttle:10,1')
        ->name('clients.insights.store');
    Route::get('/clients/{client}/store-revenue', [ClientStoreRevenueController::class, 'index'])
        ->whereNumber('client')
        ->name('clients.store-revenue.index');
    Route::post('/clients/{client}/store-revenue', [ClientStoreRevenueController::class, 'store'])
        ->whereNumber('client')
        ->middleware('throttle:20,1')
        ->name('clients.store-revenue.store');
    Route::delete('/clients/{client}/store-revenue', [ClientStoreRevenueController::class, 'destroy'])
        ->whereNumber('client')
        ->name('clients.store-revenue.destroy');
    Route::get('/clients/{client}/ad-imports', [ClientAdPlatformImportController::class, 'index'])
        ->whereNumber('client')
        ->name('clients.ad-imports.index');
    Route::post('/clients/{client}/ad-imports', [ClientAdPlatformImportController::class, 'store'])
        ->whereNumber('client')
        ->middleware('throttle:20,1')
        ->name('clients.ad-imports.store');
    Route::delete('/clients/{client}/ad-imports', [ClientAdPlatformImportController::class, 'destroy'])
        ->whereNumber('client')
        ->name('clients.ad-imports.destroy');
    Route::get('/clients/{client}/leads', [ClientLeadController::class, 'index'])
        ->whereNumber('client')
        ->name('clients.leads.index');
    Route::post('/clients/{client}/leads', [ClientLeadController::class, 'store'])
        ->whereNumber('client')
        ->middleware('throttle:20,1')
        ->name('clients.leads.store');
    Route::get('/clients/{client}/leads/offline-conversions.csv', [ClientLeadController::class, 'exportOfflineConversions'])
        ->whereNumber('client')
        ->name('clients.leads.offline-conversions');
    Route::delete('/clients/{client}/leads', [ClientLeadController::class, 'destroy'])
        ->whereNumber('client')
        ->name('clients.leads.destroy');
    Route::get('/clients/{client}/keywords', [ClientKeywordController::class, 'index'])
        ->whereNumber('client')
        ->name('clients.keywords.index');
    Route::post('/clients/{client}/keywords', [ClientKeywordController::class, 'store'])
        ->whereNumber('client')
        ->name('clients.keywords.store');
    Route::post('/clients/{client}/keywords/refresh', [ClientKeywordController::class, 'refresh'])
        ->whereNumber('client')
        ->middleware('throttle:5,1')
        ->name('clients.keywords.refresh');
    Route::delete('/clients/{client}/keywords/{trackedKeyword}', [ClientKeywordController::class, 'destroy'])
        ->whereNumber('client')
        ->whereNumber('trackedKeyword')
        ->scopeBindings()
        ->name('clients.keywords.destroy');
    Route::get('/clients/{client}/competitors', [ClientCompetitorController::class, 'index'])
        ->whereNumber('client')
        ->name('clients.competitors.index');
    Route::get('/clients/{client}/budgets', [ClientBudgetController::class, 'index'])
        ->whereNumber('client')
        ->name('clients.budgets.index');
    Route::post('/clients/{client}/budgets', [ClientBudgetController::class, 'store'])
        ->whereNumber('client')
        ->name('clients.budgets.store');
    Route::delete('/clients/{client}/budgets/{monthlyBudget}', [ClientBudgetController::class, 'destroy'])
        ->whereNumber('client')
        ->whereNumber('monthlyBudget')
        ->scopeBindings()
        ->name('clients.budgets.destroy');
    Route::get('/clients/{client}/annotations', [ClientMarketingAnnotationController::class, 'index'])
        ->whereNumber('client')
        ->name('clients.annotations.index');
    Route::post('/clients/{client}/annotations', [ClientMarketingAnnotationController::class, 'store'])
        ->whereNumber('client')
        ->name('clients.annotations.store');
    Route::delete('/clients/{client}/annotations/{marketingAnnotation}', [ClientMarketingAnnotationController::class, 'destroy'])
        ->whereNumber('client')
        ->whereNumber('marketingAnnotation')
        ->scopeBindings()
        ->name('clients.annotations.destroy');
    Route::get('/clients/{client}/marketing-alerts', [MarketingAlertRuleController::class, 'index'])
        ->whereNumber('client')
        ->name('clients.marketing-alerts.index');
    Route::post('/clients/{client}/marketing-alerts', [MarketingAlertRuleController::class, 'store'])
        ->whereNumber('client')
        ->name('clients.marketing-alerts.store');
    Route::patch('/clients/{client}/marketing-alerts/{marketingAlertRule}', [MarketingAlertRuleController::class, 'update'])
        ->whereNumber('client')
        ->whereNumber('marketingAlertRule')
        ->scopeBindings()
        ->name('clients.marketing-alerts.update');
    Route::delete('/clients/{client}/marketing-alerts/{marketingAlertRule}', [MarketingAlertRuleController::class, 'destroy'])
        ->whereNumber('client')
        ->whereNumber('marketingAlertRule')
        ->scopeBindings()
        ->name('clients.marketing-alerts.destroy');
    Route::post('/clients/{client}/marketing-alerts/events/acknowledge-all', [MarketingAlertRuleController::class, 'acknowledgeAllEvents'])
        ->whereNumber('client')
        ->name('clients.marketing-alerts.events.acknowledge-all');
    Route::patch('/clients/{client}/marketing-alerts/events/{marketingAlertEvent}', [MarketingAlertRuleController::class, 'updateEvent'])
        ->whereNumber('client')
        ->whereNumber('marketingAlertEvent')
        ->scopeBindings()
        ->name('clients.marketing-alerts.events.update');
    Route::get('/social-post-maturity/export.csv', [DashboardController::class, 'exportSocialPostMaturity'])->name('social-post-maturity.export');
    Route::get('/social-engagement-targets/export.csv', [DashboardController::class, 'exportSocialEngagementTargets'])->name('social-engagement-targets.export');
    Route::get('/social-engagement-targets/{socialEngagementTarget}/report', [SocialEngagementCampaignReportController::class, 'show'])->whereNumber('socialEngagementTarget')->name('social-engagement-targets.report');
    Route::post('/social-engagement-targets/{socialEngagementTarget}/report/utm-link-preview', [SocialEngagementCampaignReportController::class, 'previewUtmLink'])
        ->whereNumber('socialEngagementTarget')
        ->name('social-engagement-targets.report.utm-link-preview');
    Route::get('/social-engagement-targets/{socialEngagementTarget}/posts', [SocialEngagementCampaignReportController::class, 'postDetails'])
        ->whereNumber('socialEngagementTarget')
        ->name('social-engagement-targets.posts');
    Route::get('/social-engagement-targets/{socialEngagementTarget}/report.csv', [SocialEngagementCampaignReportController::class, 'exportCampaignSummary'])
        ->whereNumber('socialEngagementTarget')
        ->name('social-engagement-targets.report.export');
    Route::get('/social-engagement-targets/{socialEngagementTarget}/posts.csv', [SocialEngagementCampaignReportController::class, 'exportCampaignPosts'])
        ->whereNumber('socialEngagementTarget')
        ->middleware('throttle:10,1')
        ->name('social-engagement-targets.report.posts.export');
    Route::get('/social-engagement-targets/{socialEngagementTarget}/creatives.csv', [SocialEngagementCampaignReportController::class, 'exportCampaignCreativePerformance'])
        ->whereNumber('socialEngagementTarget')
        ->middleware('throttle:10,1')
        ->name('social-engagement-targets.creative-performance.export');
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
    Route::middleware(EnsureWorkspaceOwner::class)->group(function (): void {
        Route::put('/integrations/{integrationProvider}/oauth-application', [IntegrationApplicationCredentialController::class, 'update'])
            ->whereIn('integrationProvider', ['google_analytics', 'google_search_console', 'meta_marketing', 'google_ads'])
            ->middleware('throttle:10,1')
            ->name('integrations.oauth-application.update');
        Route::delete('/integrations/{integrationProvider}/oauth-application', [IntegrationApplicationCredentialController::class, 'destroy'])
            ->whereIn('integrationProvider', ['google_analytics', 'google_search_console', 'meta_marketing', 'google_ads'])
            ->middleware('throttle:10,1')
            ->name('integrations.oauth-application.destroy');
        Route::get('/integrations/google-analytics', [GoogleAnalyticsIntegrationController::class, 'show'])->name('integrations.google-analytics.edit');
        Route::post('/integrations/google-analytics/connect', [GoogleAnalyticsIntegrationController::class, 'connect'])->name('integrations.google-analytics.connect');
        Route::get('/integrations/google-analytics/callback', [GoogleAnalyticsIntegrationController::class, 'callback'])->name('integrations.google-analytics.callback');
        Route::put('/integrations/google-analytics/clients/{client}/property', [GoogleAnalyticsIntegrationController::class, 'updateProperty'])
            ->whereNumber('client')
            ->name('integrations.google-analytics.property.update');
        Route::delete('/integrations/google-analytics/connection', [GoogleAnalyticsIntegrationController::class, 'disconnect'])->name('integrations.google-analytics.disconnect');
        Route::get('/integrations/google-search-console', [GoogleSearchConsoleIntegrationController::class, 'show'])->name('integrations.google-search-console.edit');
        Route::post('/integrations/google-search-console/connect', [GoogleSearchConsoleIntegrationController::class, 'connect'])->name('integrations.google-search-console.connect');
        Route::get('/integrations/google-search-console/callback', [GoogleSearchConsoleIntegrationController::class, 'callback'])->name('integrations.google-search-console.callback');
        Route::put('/integrations/google-search-console/site', [GoogleSearchConsoleIntegrationController::class, 'updateSite'])->name('integrations.google-search-console.site.update');
        Route::get('/integrations/google-search-console/report', [GoogleSearchConsoleIntegrationController::class, 'report'])
            ->middleware('throttle:10,1')
            ->name('integrations.google-search-console.report');
        Route::get('/integrations/google-search-console/report/export.csv', [GoogleSearchConsoleIntegrationController::class, 'export'])
            ->middleware('throttle:10,1')
            ->name('integrations.google-search-console.report.export');
        Route::delete('/integrations/google-search-console/connection', [GoogleSearchConsoleIntegrationController::class, 'disconnect'])->name('integrations.google-search-console.disconnect');
        Route::get('/integrations/meta-marketing', [MetaMarketingIntegrationController::class, 'show'])->name('integrations.meta-marketing.edit');
        Route::post('/integrations/meta-marketing/connect', [MetaMarketingIntegrationController::class, 'connect'])->name('integrations.meta-marketing.connect');
        Route::get('/integrations/meta-marketing/callback', [MetaMarketingIntegrationController::class, 'callback'])->name('integrations.meta-marketing.callback');
        Route::put('/integrations/meta-marketing/clients/{client}/ad-account', [MetaMarketingIntegrationController::class, 'updateClientAdAccount'])
            ->whereNumber('client')
            ->name('integrations.meta-marketing.clients.ad-account.update');
        Route::delete('/integrations/meta-marketing/connection', [MetaMarketingIntegrationController::class, 'disconnect'])->name('integrations.meta-marketing.disconnect');
        Route::get('/integrations/google-ads', [GoogleAdsIntegrationController::class, 'show'])->name('integrations.google-ads.edit');
        Route::post('/integrations/google-ads/connect', [GoogleAdsIntegrationController::class, 'connect'])->name('integrations.google-ads.connect');
        Route::get('/integrations/google-ads/callback', [GoogleAdsIntegrationController::class, 'callback'])->name('integrations.google-ads.callback');
        Route::put('/integrations/google-ads/clients/{client}/customer', [GoogleAdsIntegrationController::class, 'updateClientCustomer'])
            ->whereNumber('client')
            ->name('integrations.google-ads.clients.customer.update');
        Route::delete('/integrations/google-ads/connection', [GoogleAdsIntegrationController::class, 'disconnect'])->name('integrations.google-ads.disconnect');
        Route::get('/integrations/line', [LineMessagingIntegrationController::class, 'show'])->name('integrations.line.edit');
        Route::put('/integrations/line', [LineMessagingIntegrationController::class, 'update'])
            ->middleware('throttle:10,1')
            ->name('integrations.line.update');
        Route::delete('/integrations/line', [LineMessagingIntegrationController::class, 'destroy'])->name('integrations.line.destroy');
        Route::post('/integrations/line/targets/{lineNotificationTarget}/test', [LineMessagingIntegrationController::class, 'sendTest'])
            ->whereNumber('lineNotificationTarget')
            ->middleware('throttle:notification-test')
            ->name('integrations.line.targets.test');
        Route::delete('/integrations/line/targets/{lineNotificationTarget}', [LineMessagingIntegrationController::class, 'destroyTarget'])
            ->whereNumber('lineNotificationTarget')
            ->name('integrations.line.targets.destroy');
    });
    Route::get('/features', [FeatureGuideController::class, 'index'])->name('features.index');
    Route::middleware(EnsureWorkspaceOwner::class)->prefix('trials')->name('trials.')->group(function (): void {
        Route::get('/', [TrialRequestController::class, 'index'])->name('index');
        Route::post('/', [TrialRequestController::class, 'store'])
            ->middleware('throttle:10,1')
            ->name('store');
    });
    Route::get('/account/password', [AuthController::class, 'editPassword'])->name('account.password.edit');
    Route::put('/account/password', [AuthController::class, 'updatePassword'])->name('account.password.update');
    Route::post('/account/two-factor', [TwoFactorSettingsController::class, 'enable'])->middleware('throttle:10,1')->name('account.two-factor.enable');
    Route::post('/account/two-factor/confirm', [TwoFactorSettingsController::class, 'confirm'])->middleware('throttle:10,1')->name('account.two-factor.confirm');
    Route::post('/account/two-factor/recovery-codes', [TwoFactorSettingsController::class, 'regenerateRecoveryCodes'])->middleware('throttle:10,1')->name('account.two-factor.recovery-codes');
    Route::delete('/account/two-factor', [TwoFactorSettingsController::class, 'disable'])->middleware('throttle:10,1')->name('account.two-factor.disable');
    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::get('/notifications/export.csv', [NotificationController::class, 'export'])->name('notifications.export');
    Route::post('/notifications/test', [NotificationController::class, 'sendTestNotification'])
        ->middleware('throttle:notification-test')
        ->name('notifications.test');
    Route::get('/incidents', [IncidentHistoryController::class, 'index'])->name('incidents.index');
    Route::get('/incidents/export.csv', [IncidentHistoryController::class, 'export'])->name('incidents.export');
    Route::post('/incidents/bulk-acknowledge', [IncidentController::class, 'bulkAcknowledge'])->name('incidents.bulk-acknowledge');
    Route::post('/logout', [AuthController::class, 'destroy'])->name('logout');
    Route::middleware(EnsureWorkspaceOwner::class)->group(function (): void {
        Route::get('/status-page', [StatusPageController::class, 'edit'])->name('status-pages.edit');
        Route::post('/status-page', [StatusPageController::class, 'update'])->name('status-pages.update');
        Route::post('/status-page/monitors/{monitor}/toggle', [StatusPageController::class, 'toggleMonitor'])->name('status-pages.monitors.toggle');
    });

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
