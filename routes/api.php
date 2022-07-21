<?php

use Illuminate\Support\Facades\Route;

Route::get('/', [\App\Http\Controllers\IndexController::class, 'index']);
Route::get('/status', [\App\Http\Controllers\IndexController::class, 'status']);
Route::post('/register', [\App\Http\Controllers\Auth\AuthController::class, 'register']);
Route::post('/login', [\App\Http\Controllers\Auth\AuthController::class, 'login']);
Route::get('/countries', [\App\Http\Controllers\Locations\CountriesController::class, 'index']);
Route::get('/countries/{id}', [\App\Http\Controllers\Locations\CountriesController::class, 'show']);
Route::get('/countries/{countriesId}/states', [\App\Http\Controllers\Locations\StatesController::class, 'index']);
// Route::get('/countries/{countriesId}/states/{statesId}/regions', [\Kanvas\Http\Controllers\Locations\CitiesController::class, 'index']);
Route::get('/timezones', [\App\Http\Controllers\Locations\TimezonesController::class, 'index']);
Route::get('/currencies', [\App\Http\Controllers\Currencies\CurrenciesController::class, 'index']);
Route::get('/currencies/{id}', [\App\Http\Controllers\Currencies\CurrenciesController::class, 'show']);



/**
 * Private Routes.
 */
Route::middleware(['auth'])->group(function () {

    /**
     * Apps Routes.
     */
    Route::group(['controller' => \App\Http\Controllers\Apps\AppsController::class], function () {
        Route::get('/apps', 'index');
        Route::get('/apps/{id}', 'show');
        Route::post('/apps', 'create');
        Route::put('/apps/{id}', 'update');
        Route::delete('/apps/{id}', 'destroy');
    });

    /**
     * Companies Routes.
     */
    Route::group(['controller' => \App\Http\Controllers\Companies\CompaniesController::class], function () {
        Route::get('/companies', 'index');
        Route::get('/companies/{id}', 'show');
        Route::post('/companies', 'create');
        Route::put('/companies/{id}', 'update');
        Route::delete('/companies/{id}', 'destroy');
    });

    /**
     * Filesystem Routes.
     */
    Route::group(['controller' => \App\Http\Controllers\Filesystem\FilesystemEntitiesController::class], function () {
        Route::get('/filesystem-entities', 'index');
        Route::get('/filesystem-entities/{id}', 'show');
        Route::post('/filesystem-entities', 'create');
        Route::put('/filesystem-entities/{id}', 'update');
        Route::delete('/filesystem-entities/{id}', 'destroy');
    });
});
