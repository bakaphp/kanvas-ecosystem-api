<?php

use Illuminate\Support\Facades\Route;

Route::get('/', [\App\Http\Controllers\IndexController::class, 'index']);
Route::get('/status', [\App\Http\Controllers\IndexController::class, 'status']);
