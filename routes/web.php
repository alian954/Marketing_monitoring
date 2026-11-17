<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\IncidentController;
use App\Http\Controllers\MaintenanceWindowController;
use App\Http\Controllers\MonitorController;
use App\Http\Controllers\MonitorImportController;
use App\Http\Controllers\RobotsReviewController;
use App\Http\Controllers\StatusPageController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthController::class, 'create'])->name('login');
    Route::post('/login', [AuthController::class, 'store'])->middleware('throttle:login')->name('login.store');
});

Route::get('/status/{token}', [StatusPageController::class, 'show'])
    ->where('token', '[A-Za-z0-9]{64}')
    ->name('status-pages.public');

Route::middleware('auth')->group(function (): void {
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    Route::post('/logout', [AuthController::class, 'destroy'])->name('logout');
    Route::get('/status-page', [StatusPageController::class, 'edit'])->name('status-pages.edit');
    Route::post('/status-page', [StatusPageController::class, 'update'])->name('status-pages.update');
    Route::post('/status-page/monitors/{monitor}/toggle', [StatusPageController::class, 'toggleMonitor'])->name('status-pages.monitors.toggle');

    Route::get('/monitors', [MonitorController::class, 'index'])->name('monitors.index');
    Route::get('/monitors/export.csv', [MonitorController::class, 'export'])->name('monitors.export');
    Route::get('/monitors/import', [MonitorImportController::class, 'create'])->name('monitors.import');
    Route::post('/monitors/import/preview', [MonitorImportController::class, 'preview'])->name('monitors.import.preview');
    Route::post('/monitors/import/commit', [MonitorImportController::class, 'commit'])->name('monitors.import.commit');
    Route::get('/monitors/create', [MonitorController::class, 'create'])->name('monitors.create');
    Route::post('/monitors', [MonitorController::class, 'store'])->name('monitors.store');
    Route::get('/monitors/{monitor}/edit', [MonitorController::class, 'edit'])->name('monitors.edit');
    Route::put('/monitors/{monitor}', [MonitorController::class, 'update'])->name('monitors.update');
    Route::post('/monitors/{monitor}/toggle', [MonitorController::class, 'toggle'])->name('monitors.toggle');
    Route::post('/monitors/{monitor}/check', [MonitorController::class, 'checkNow'])->name('monitors.check');
    Route::delete('/monitors/{monitor}', [MonitorController::class, 'destroy'])->name('monitors.destroy');
    Route::post('/monitors/{monitor}/maintenance-windows', [MaintenanceWindowController::class, 'store'])->name('monitors.maintenance-windows.store');
    Route::delete('/monitors/{monitor}/maintenance-windows/{maintenanceWindow}', [MaintenanceWindowController::class, 'destroy'])->name('monitors.maintenance-windows.destroy');
    Route::get('/monitors/{monitor}/robots', [RobotsReviewController::class, 'show'])->name('monitors.robots');
    Route::get('/monitors/{monitor}', [MonitorController::class, 'show'])->name('monitors.show');

    Route::patch('/incidents/{incident}', [IncidentController::class, 'update'])->name('incidents.update');
    Route::post('/notifications/{outbox}/retry', [IncidentController::class, 'retryOutbox'])->name('notifications.retry');
});
