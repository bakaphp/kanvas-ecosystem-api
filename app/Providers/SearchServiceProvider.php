<?php

declare(strict_types=1);

namespace App\Providers;

use Baka\Search\Jobs\TenantAwareMakeSearchable;
use Baka\Search\Jobs\TenantAwareRemoveFromSearch;
use Baka\Search\SearchEngineResolver;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Laravel\Scout\Scout;
use Override;

class SearchServiceProvider extends ServiceProvider
{
    #[Override]
    public function register()
    {
        $this->app->singleton(SearchEngineResolver::class);
    }

    #[Override]
    public function boot()
    {
        // Queued indexing must rebind the dispatching tenant in the worker, otherwise Scout resolves
        // the engine against the worker's default app and silently routes to the wrong / Null engine.
        Scout::makeSearchableUsing(TenantAwareMakeSearchable::class);
        Scout::removeFromSearchUsing(TenantAwareRemoveFromSearch::class);
    }
}
