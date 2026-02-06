<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Nuwave\Lighthouse\Schema\AST\ASTCache;
use Nuwave\Lighthouse\Schema\Source\SchemaSourceProvider;
use Nuwave\Lighthouse\Schema\Source\SchemaStitcher;
use Yakovenko\LighthouseGraphqlMultiSchema\Commands\LighthouseClearCacheCommand;
use Yakovenko\LighthouseGraphqlMultiSchema\Commands\PublishConfigCommand;
use Yakovenko\LighthouseGraphqlMultiSchema\Services\GraphQLRouteRegister;
use Yakovenko\LighthouseGraphqlMultiSchema\Services\GraphQLSchemaConfig;
use Yakovenko\LighthouseGraphqlMultiSchema\Services\SchemaASTCache;

class GraphqlMultiVersionTestProvider extends ServiceProvider
{
    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        // Empty - all registration logic moved to boot for Octane testing
    }

    /**
     * Bootstrap services.
     *
     * This method contains all the registration logic from LighthouseMultiSchemaServiceProvider::register()
     * to test if these services are reinitialized on each Octane request.
     *
     * @return void
     */
    public function boot()
    {
        // logger()->info('GraphqlMultiVersionTestProvider::boot() - Testing Octane service reinitialization');

        // Register GraphQLSchemaConfig bind
        app()->scoped(GraphQLSchemaConfig::class, function () {
            logger()->info('Creating new GraphQLSchemaConfig instance in boot');

            return new GraphQLSchemaConfig();
        });

        // Register GraphQLRouteRegister without dependency injection
        app()->singleton(GraphQLRouteRegister::class, function () {
            logger()->info('Creating new GraphQLRouteRegister instance in boot');

            return new GraphQLRouteRegister();
        });

        // Register SchemaASTCache as bind (not singleton) to make it Octane-compatible
        // This ensures a fresh instance per request with current request context
        app()->scoped(ASTCache::class, function ($app) {
            logger()->info('Creating new SchemaASTCache instance in boot');

            return new SchemaASTCache(
                $app['config'],
                $app->make(GraphQLSchemaConfig::class)
            );
        });

        // Register SchemaSourceProvider bind using SchemaStitcher
        app()->scoped(SchemaSourceProvider::class, function ($app) {
            logger()->debug('Resolving SchemaSourceProvider bind via GraphqlMultiVersionTestProvider');

            return new SchemaStitcher(
                $app->make(GraphQLSchemaConfig::class)->getPath($app['request'])
            );
        });

        // Merge package configuration with application configuration
        $this->mergeConfigFrom(
            base_path('vendor/yakovenko/laravel-lighthouse-graphql-multi-schema/src/lighthouse-multi-schema.php'),
            'lighthouse-multi-schema'
        );

        // Register custom commands
        $this->commands([PublishConfigCommand::class]);

        // Force override of lighthouse:clear-cache command
        app()->singleton('command.lighthouse.clear-cache', function ($app) {
            logger()->info('Creating new LighthouseClearCacheCommand instance in boot');

            return new LighthouseClearCacheCommand(
                $app['files'],
                $app->make(GraphQLSchemaConfig::class)
            );
        });

        // Original boot logic from LighthouseMultiSchemaServiceProvider
        // Publish the configuration file to the application's config directory
        $this->publishes([
            base_path('vendor/yakovenko/laravel-lighthouse-graphql-multi-schema/src/lighthouse-multi-schema.php') => config_path('lighthouse-multi-schema.php'),
        ], 'config');

        // Register GraphQL routes by resolving GraphQLRouteRegister from the container
        app()->make(GraphQLRouteRegister::class)->registerGraphQLRoutes();

        // Register overridden command
        if (app()->runningInConsole()) {
            $this->commands(['command.lighthouse.clear-cache']);
        }

        // logger()->info('GraphqlMultiVersionTestProvider::boot() - Completed');
    }
}
