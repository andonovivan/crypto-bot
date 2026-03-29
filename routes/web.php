<?php

use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

Route::get('/', [DashboardController::class, 'index']);
Route::get('/api/data', [DashboardController::class, 'data']);
Route::get('/api/settings', [DashboardController::class, 'settings']);
Route::post('/api/close', [DashboardController::class, 'closePosition']);
Route::post('/api/settings', [DashboardController::class, 'saveSettings']);
