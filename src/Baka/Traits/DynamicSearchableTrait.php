<?php

declare(strict_types=1);

namespace Baka\Traits;

use Baka\Search\SearchEngineResolver;
use Laravel\Scout\Searchable;

trait DynamicSearchableTrait
{
    use Searchable;

    public function searchableUsing()
    {
        return app(SearchEngineResolver::class)->resolveEngine();
    }
}
