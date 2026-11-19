<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\IncidentController;
use App\Http\Controllers\IncidentHistoryController;
use App\Http\Controllers\MaintenanceWindowController;
use App\Http\Controllers\MonitorController;
use App\Http\Controllers\MonitorImportController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\RobotsReviewController;
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
    Route::get('/monitors/{monitor}/checks/export.csv', [MonitorController::class, 'exportCheckRuns'])->name('monitors.checks.export');
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
