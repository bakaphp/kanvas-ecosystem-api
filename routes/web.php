<?php

use Illuminate\Support\Facades\Route;
use App\GraphQL\Ecosystem\Mutations\Auth\AuthManagementMutation;
use Laravel\Horizon\Horizon;

Route::get('horizon/login', [AuthManagementMutation::class, 'showLoginForm'])->name('horizon.login');
Route::middleware(['web', 'auth'])->group(function () {
    Route::get('/horizon/dashboard', function () {
        abort_unless(Gate::allows('viewHorizon'), 403);
        return Horizon::auth();
    });
});