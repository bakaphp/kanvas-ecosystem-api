<?php

use Illuminate\Support\Facades\Route;
use Spatie\Health\Http\Controllers\SimpleHealthCheckController;

Route::get('/', [\App\Http\Controllers\IndexController::class, 'index']);
Route::post('/receiver/{uuid}', [\App\Http\Controllers\ReceiverController::class, 'store']);
Route::get('/oauth/{uuid}', [\App\Http\Controllers\OAuthIntegrationController::class, 'auth']);
Route::get('/oauth/{uuid}/callback', [\App\Http\Controllers\OAuthIntegrationController::class, 'callback']);
Route::get('/status', SimpleHealthCheckController::class);
Route::middleware('auth')->get('/status/health', \Spatie\Health\Http\Controllers\HealthCheckJsonResultsController::class);
