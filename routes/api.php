<?php

use App\Http\Controllers\API\CalculatorController;
use App\Http\Controllers\API\CarGroupController;
use App\Http\Controllers\API\ItemController;
use App\Http\Controllers\API\HomeController;
use App\Http\Controllers\API\NotificationController;
use App\Http\Controllers\API\MetalsController;
use App\Http\Controllers\API\MarketChartController;
use App\Http\Controllers\API\MarketNotificationController;
use App\Http\Controllers\ImportController;
use Illuminate\Support\Facades\Route;

Route::get('/home/stats', [HomeController::class, 'stats'])->name('home.stats');
Route::get('/home/top_items', [HomeController::class, 'topItems'])->name('home.top-items');

Route::get('/car_groups', [CarGroupController::class, 'index'])->name('car-groups.index');

Route::get('/items', [ItemController::class, 'index'])->name('items.index');
Route::get('/items/{item}', [ItemController::class, 'show'])->name('items.show');
Route::get('/items/{item}/similar', [ItemController::class, 'similar'])->name('items.similar');

Route::post('/calculator/estimate', [CalculatorController::class, 'estimate'])->name('calculator.estimate');

Route::get('/charts/metals', [MarketChartController::class, 'index'])->name('markets.charts.index');
Route::get('/notifications/changes', [MarketNotificationController::class, 'index'])->name('markets.notifications.index');

Route::prefix('v1/metals')->group(function () {
    Route::get('spot', [MetalsController::class, 'index']);
    Route::get('spot/{key}', [MetalsController::class, 'show']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('refresh', [MetalsController::class, 'refresh']);
    });
});

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/imports', [ImportController::class, 'store']);
    Route::get('/imports/{batch}', [ImportController::class, 'show']);
    Route::get('/imports/{batch}/duplicates', [ImportController::class, 'duplicates']);
    Route::patch('/duplicates/{review}', [ImportController::class, 'resolveDuplicate']);

    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::patch('/notifications/read-all', [NotificationController::class, 'markAllAsRead'])->name('notifications.read-all');
    Route::patch('/notifications/{notification}/read', [NotificationController::class, 'markAsRead'])->name('notifications.read');
});
