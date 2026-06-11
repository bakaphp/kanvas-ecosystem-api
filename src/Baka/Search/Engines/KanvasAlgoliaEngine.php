<?php

declare(strict_types=1);

namespace Baka\Search\Engines;

use Algolia\AlgoliaSearch\Api\SearchClient;
use Algolia\ScoutExtended\Engines\AlgoliaEngine;
use Algolia\ScoutExtended\Jobs\DeleteJob;
use Algolia\ScoutExtended\Jobs\UpdateJob;

class KanvasAlgoliaEngine extends AlgoliaEngine
{
    public function update($searchables)
    {
        $this->withTenantClient(fn () => dispatch_sync(new UpdateJob($searchables)));
    }

    public function delete($searchables)
    {
        $this->withTenantClient(fn () => dispatch_sync(new DeleteJob($searchables)));
    }

    /**
     * Scout-Extended's UpdateJob/DeleteJob receive SearchClient via method
     * injection from the container; without this swap they get the global
     * client built from config('scout.algolia.*'), bypassing the per-tenant
     * client this engine was constructed with.
     */
    protected function withTenantClient(callable $fn): void
    {
        $container = app();
        $previous = $container->bound(SearchClient::class)
            ? $container->make(SearchClient::class)
            : null;

        $container->instance(SearchClient::class, $this->algolia);

        try {
            $fn();
        } finally {
            if ($previous !== null) {
                $container->instance(SearchClient::class, $previous);
            } else {
                $container->forgetInstance(SearchClient::class);
            }
        }
    }
}
