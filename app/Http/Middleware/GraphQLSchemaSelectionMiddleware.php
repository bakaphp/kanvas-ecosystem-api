<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Providers\GraphqlMultiVersionTestProvider;
use Closure;
use Illuminate\Http\Request;
use Nuwave\Lighthouse\LighthouseServiceProvider;

class GraphQLSchemaSelectionMiddleware
{
    /**
     * Handle an incoming request.
     *
     */
    public function handle(Request $request, Closure $next)
    {
        // decide schema por path/host/header y setea config aquí
        // config([...]);

        app()->forgetInstance(\Nuwave\Lighthouse\GraphQL::class);
        app()->forgetInstance(\Nuwave\Lighthouse\Schema\SchemaBuilder::class);
        app()->forgetInstance(\Nuwave\Lighthouse\Schema\Source\SchemaSourceProvider::class);
        logger()->debug('GraphQLSchemaSelectionMiddleware: Forgotten Lighthouse instances to reload schema with new config.');
        if (request()->getPathInfo() == '/2025-01-graphql') {
            config(['lighthouse.schema.register' => base_path('graphql/2025-01-schema.graphql')]);
        }
        logger()->debug('schema', ['lighthouse.schema.register' => config('lighthouse.schema.register')]);
        // logger()->info();
        // logger()->debug('GraphQLSchemaSelectionMiddleware: Current schema_path config', ['provider' => app()->make(\Nuwave\Lighthouse\Schema\Source\SchemaSourceProvider::class)]);
        new LighthouseServiceProvider(app())->register();
        new GraphqlMultiVersionTestProvider(app())->boot();

        return $next($request);
    }
}
