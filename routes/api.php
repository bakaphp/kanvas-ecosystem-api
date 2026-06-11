<?php

use Illuminate\Support\Facades\Route;
use Spatie\Health\Http\Controllers\SimpleHealthCheckController;

Route::get('/', [\App\Http\Controllers\IndexController::class, 'index']);

// OTel Collector → GraphQL bridge (Docker-internal only, validated by X-Internal-Token header)
Route::post('/telemetry/otlp', [\App\Http\Controllers\Intelligence\OtlpAdapterController::class, 'handle'])
    ->middleware('throttle:600,1');
Route::post('/receiver/{uuid}', [\App\Http\Controllers\ReceiverController::class, 'store']);
Route::get('/oauth/{uuid}', [\App\Http\Controllers\OAuthIntegrationController::class, 'auth']);
Route::get('/oauth/{uuid}/callback', [\App\Http\Controllers\OAuthIntegrationController::class, 'callback']);
Route::get('/status', SimpleHealthCheckController::class);
Route::middleware('auth')->get('/status/health', \Spatie\Health\Http\Controllers\HealthCheckJsonResultsController::class);
