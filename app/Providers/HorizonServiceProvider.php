<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;
use Kanvas\Users\Models\Users;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();

        $this->gate();

        Horizon::auth(function ($request) {
            // Check if the user is authorized to view Horizon
            return Gate::allows('viewHorizon', $request->user());
        });

        // Horizon::routeSmsNotificationsTo('15556667777');
        // Horizon::routeMailNotificationsTo('example@example.com');
        // Horizon::routeSlackNotificationsTo('slack-webhook-url', '#channel');
    }

    /**
     * Register the Horizon gate.
     *
     * This gate determines who can access Horizon in non-local environments.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', function () {
            $user = auth()->user();
            dd($user);
            return in_array($user->email, [
                'rwhite@mctekk.com'
            ]);
        });
    }
}
