<?php

use Illuminate\Support\Facades\Route;
use Spatie\Health\Http\Controllers\SimpleHealthCheckController;

Route::get('/', [\App\Http\Controllers\IndexController::class, 'index']);
Route::post('/receiver/{uuid}',  [\App\Http\Controllers\ReceiverController::class, 'store']);
Route::get('/status', SimpleHealthCheckController::class);
Route::middleware('auth')->get('/status/health', \Spatie\Health\Http\Controllers\HealthCheckJsonResultsController::class);
