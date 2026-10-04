<?php

declare(strict_types=1);

namespace Tests\Traits;

use Baka\Search\SearchEngineResolver;
use Kanvas\Apps\Models\Apps;

/**
 * Agent memory is on by default but only where the app has Typesense credentials, and CI has none.
 * A test that exercises the on state stubs a key when the app has none and removes it after, so a
 * developer's real settings are never touched. The stub never reaches a cluster: such tests fake the
 * event or bind an in-memory store.
 */
trait StubsTypesenseCredentials
{
    private bool $typesenseCredentialsStubbed = false;

    protected function stubTypesenseCredentials(Apps $app): void
    {
        if (SearchEngineResolver::hasTypesenseCredentials(SearchEngineResolver::typesenseSettings($app))) {
            return;
        }

        $app->set('typesense_search_settings', ['typesense_api_key' => 'test-only']);
        $this->typesenseCredentialsStubbed = true;
    }

    protected function removeStubbedTypesenseCredentials(Apps $app): void
    {
        if (! $this->typesenseCredentialsStubbed) {
            return;
        }

        $app->del('typesense_search_settings');
        $this->typesenseCredentialsStubbed = false;
    }
}
