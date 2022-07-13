<?php

use Illuminate\Support\Facades\Route;

Route::get('/', [\Kanvas\Http\Controllers\IndexController::class, 'index']);
Route::get('/status', [\Kanvas\Http\Controllers\IndexController::class, 'status']);
Route::post('/register', [\Kanvas\Http\Controllers\Auth\AuthController::class, 'register']);
Route::post('/login', [\Kanvas\Http\Controllers\Auth\AuthController::class, 'login']);
Route::get('/countries', [\Kanvas\Http\Controllers\Locations\CountriesController::class, 'index']);
Route::get('/countries/{id}', [\Kanvas\Http\Controllers\Locations\CountriesController::class, 'show']);
Route::get('/countries/{countriesId}/states', [\Kanvas\Http\Controllers\Locations\StatesController::class, 'index']);
// Route::get('/countries/{countriesId}/states/{statesId}/regions', [\Kanvas\Http\Controllers\Locations\CitiesController::class, 'index']);
Route::get('/timezones', [\Kanvas\Http\Controllers\Locations\TimezonesController::class, 'index']);
Route::get('/currencies', [\Kanvas\Http\Controllers\Currencies\CurrenciesController::class, 'index']);
Route::get('/currencies/{id}', [\Kanvas\Http\Controllers\Currencies\CurrenciesController::class, 'show']);



/**
 * Private Routes.
 */
Route::middleware(['auth'])->group(function () {

    /**
     * Apps Routes.
     */
    Route::group(['controller' => \Kanvas\Http\Controllers\Apps\AppsController::class], function () {
        Route::get('/apps', 'index');
        Route::get('/apps/{id}', 'show');
        Route::post('/apps', 'create');
        Route::put('/apps/{id}', 'update');
        Route::delete('/apps/{id}', 'destroy');
    });

    /**
     * Companies Routes.
     */
    Route::group(['controller' => \Kanvas\Http\Controllers\Companies\CompaniesController::class], function () {
        Route::get('/companies', 'index');
        Route::get('/companies/{id}', 'show');
        Route::post('/companies', 'create');
        Route::put('/companies/{id}', 'update');
        Route::delete('/companies/{id}', 'destroy');
    });
});
