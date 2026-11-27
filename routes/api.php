<?php

use App\Http\Controllers\Api\V1\ClientDataController;
use App\Http\Middleware\EnforceProductFeatureAvailability;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->middleware(['throttle:60,1', EnforceProductFeatureAvailability::class])->group(function (): void {
    Route::get('/clients', [ClientDataController::class, 'index'])->name('clients.index');
    Route::get('/clients/{client}/summary', [ClientDataController::class, 'summary'])
        ->whereNumber('client')
        ->name('clients.summary');
    Route::get('/clients/{client}/daily-performance', [ClientDataController::class, 'dailyPerformance'])
        ->whereNumber('client')
        ->name('clients.daily-performance');
});
