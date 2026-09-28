<?php

declare(strict_types=1);

namespace Tests\Inventory\Recommendations;

use Kanvas\Apps\Models\Apps;

/**
 * Discovery resolves its engine from an app setting, so a machine that has run
 * the Typesense setup steps scores a live cluster instead of the SQL path and
 * these assertions fail for the environment. An unknown engine name resolves to
 * Scout's null engine, which is also what stops product factories shipping
 * documents to a real collection.
 */
trait PinsSearchEngine
{
    private array $pinnedEngines = [];

    protected function pinSearchEngine(string ...$keys): void
    {
        $app = app(Apps::class);

        foreach ($keys === [] ? ['products_search_engine'] : $keys as $key) {
            $this->pinnedEngines[$key] = $app->get($key);
            $app->set($key, 'database');
        }
    }

    /**
     * `set(key, null)` is a no-op on the settings store, so an unset key has to be
     * deleted rather than written back as null.
     */
    protected function restoreSearchEngine(): void
    {
        $app = app(Apps::class);

        foreach ($this->pinnedEngines as $key => $value) {
            is_string($value) ? $app->set($key, $value) : $app->del($key);
        }

        $this->pinnedEngines = [];
    }
}
