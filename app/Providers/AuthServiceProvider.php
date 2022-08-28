<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Kanvas\Users\Users\Models\Users;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        // 'App\Models\Model' => 'App\Policies\ModelPolicy',
    ];

    /**
     * Register any authentication / authorization services.
     *
     * @return void
     */
    public function boot()
    {
        //$this->registerPolicies();

        Auth::viaRequest('custom-token', function (Request $request) {
            if (!empty($request->bearerToken())) {
                return Users::where('id', 1)->first();
            }

            return false;
            //die('33');
            //print_r($request); die();
            //return User::where('token', $request->token)->first();
        });
    }
}
