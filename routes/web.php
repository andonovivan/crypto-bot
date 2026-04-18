<?php

use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

Route::get('/', [DashboardController::class, 'index']);
Route::get('/api/data', [DashboardController::class, 'data']);
Route::get('/api/settings', [DashboardController::class, 'settings']);
Route::post('/api/close', [DashboardController::class, 'closePosition']);
Route::post('/api/close-all', [DashboardController::class, 'closeAll']);
Route::post('/api/reverse', [DashboardController::class, 'reversePosition']);
Route::post('/api/add-margin', [DashboardController::class, 'addToPosition']);
Route::post('/api/settings', [DashboardController::class, 'saveSettings']);
Route::post('/api/reset', [DashboardController::class, 'resetAll']);
Route::post('/api/scan', [DashboardController::class, 'scanNow']);
Route::get('/api/scanner', [DashboardController::class, 'scannerData']);
Route::post('/api/open-position', [DashboardController::class, 'openPosition']);
