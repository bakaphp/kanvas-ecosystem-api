<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Kanvas\AccessControlList\Templates\ModulesRepositories;
use Kanvas\Auth\TokenGuard;
use Kanvas\Users\Models\Users;

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
     */
    public function boot(): void
    {
        //$this->registerPolicies();

        //set kanvas JWT Token Guard
        Auth::extend('kanvasToken', function ($app, $name, array $config) {
            return new TokenGuard(
                Auth::createUserProvider($config['provider']),
                $app->make('request')
            );
        });

        $this->registerModuleAccessGate();
    }

    /**
     * Register the module access gate.
     * This automatically checks module-level permissions before entity permissions.
     */
    protected function registerModuleAccessGate(): void
    {
        Gate::before(function ($user, string $_ability, array $arguments) {
            if (empty($arguments) || ! is_string($arguments[0])) {
                return null;
            }

            $modelClass = $arguments[0];

            $moduleId = ModulesRepositories::getModuleIdByModelName($modelClass);

            // If model doesn't belong to any module, allow normal flow
            if ($moduleId === null) {
                return null;
            }

            // Check if user has access to the module
            if ($user instanceof Users && ! $user->canAccessModule($moduleId)) {
                return false;
            }

            return null;
        });
    }
}
