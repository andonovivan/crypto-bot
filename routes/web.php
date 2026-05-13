<?php

use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

// Dashboard pages — all served by the same view. Client-side JS reads the
// `data-page` attribute on <body> to decide which sections to mount and
// which pollers to start.
Route::get('/', [DashboardController::class, 'index'])->defaults('page', 'overview')->name('dashboard.overview');
Route::get('/positions', [DashboardController::class, 'index'])->defaults('page', 'positions')->name('dashboard.positions');
Route::get('/scanner', [DashboardController::class, 'index'])->defaults('page', 'scanner')->name('dashboard.scanner');
Route::get('/history', [DashboardController::class, 'index'])->defaults('page', 'history')->name('dashboard.history');
Route::get('/failed', [DashboardController::class, 'index'])->defaults('page', 'failed')->name('dashboard.failed');
Route::get('/risk', [DashboardController::class, 'index'])->defaults('page', 'risk')->name('dashboard.risk');
Route::get('/settings', [DashboardController::class, 'index'])->defaults('page', 'settings')->name('dashboard.settings');

Route::get('/api/health', [DashboardController::class, 'health']);
Route::get('/api/data', [DashboardController::class, 'data']);
Route::get('/api/stats', [DashboardController::class, 'stats']);
Route::get('/api/trades', [DashboardController::class, 'trades']);
Route::get('/api/trades/aggregates', [DashboardController::class, 'tradeAggregates']);
Route::get('/api/failed-entries', [DashboardController::class, 'failedEntries']);
Route::get('/api/settings', [DashboardController::class, 'settings']);
Route::post('/api/close', [DashboardController::class, 'closePosition']);
Route::post('/api/close-all', [DashboardController::class, 'closeAll']);
Route::post('/api/reverse', [DashboardController::class, 'reversePosition']);
Route::post('/api/add-margin', [DashboardController::class, 'addToPosition']);
Route::post('/api/settings', [DashboardController::class, 'saveSettings']);
Route::post('/api/reset', [DashboardController::class, 'resetAll']);
Route::post('/api/scan', [DashboardController::class, 'scanNow']);
Route::get('/api/scanner', [DashboardController::class, 'scannerData']);
Route::get('/api/balance-history', [DashboardController::class, 'balanceHistory']);
Route::post('/api/open-position', [DashboardController::class, 'openPosition']);
