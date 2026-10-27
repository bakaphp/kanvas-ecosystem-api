<?php

use App\Http\Controllers\IndexController;
use App\Http\Controllers\MetaReceiverController;
use App\Http\Controllers\OAuthIntegrationController;
use App\Http\Controllers\ReceiverController;
use Illuminate\Support\Facades\Route;
use Spatie\Health\Http\Controllers\HealthCheckJsonResultsController;
use Spatie\Health\Http\Controllers\SimpleHealthCheckController;

Route::get('/', [IndexController::class, 'index']);
Route::post('/receiver/meta', [MetaReceiverController::class, 'storeMeta']);
Route::get('/receiver/meta', [MetaReceiverController::class, 'verifyMeta']);
Route::post('/receiver/{uuid}', [ReceiverController::class, 'store']);
Route::get('/receiver/{uuid}', [ReceiverController::class, 'verify']);
Route::get('/oauth/callback', [OAuthIntegrationController::class, 'callbackByState']);
Route::get('/oauth/{uuid}', [OAuthIntegrationController::class, 'auth']);
Route::get('/oauth/{uuid}/callback', [OAuthIntegrationController::class, 'callback']);
Route::get('/status', SimpleHealthCheckController::class);
Route::middleware('auth')->get('/status/health', HealthCheckJsonResultsController::class);
