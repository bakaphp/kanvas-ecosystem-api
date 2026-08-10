<?php

declare(strict_types=1);

namespace Tests\Intelligence\Traits;

use Baka\Search\SearchEngineResolver;
use Throwable;
use Typesense\Client;

/**
 * Points a test at the real Typesense cluster used by local Docker Compose
 * dev environments (reachable at the 'typesense' hostname). CI (GitHub
 * Actions) runs no Typesense service at all — those runs skip via
 * skipIfTypesenseUnreachable() rather than failing, while local runs exercise
 * a genuine store/search round trip against the live cluster.
 */
trait InteractsWithLiveTypesense
{
    /**
     * @return array{typesense_api_key: string, typesense_nodes: array<int, array<string, mixed>>}
     */
    protected function liveTypesenseSettings(): array
    {
        return [
            'typesense_api_key' => 'xyz',
            'typesense_nodes' => [[
                'host' => 'typesense',
                'port' => 8108,
                'path' => '/',
                'protocol' => 'http',
            ]],
        ];
    }

    protected function liveTypesenseClient(): Client
    {
        return SearchEngineResolver::getTypesenseClient($this->liveTypesenseSettings());
    }

    protected function skipIfTypesenseUnreachable(): void
    {
        try {
            $this->liveTypesenseClient()->health->retrieve();
        } catch (Throwable) {
            $this->markTestSkipped('No reachable Typesense cluster in this environment (expected in CI).');
        }
    }

    protected function deleteTypesenseTestCollection(string $collection): void
    {
        try {
            $this->liveTypesenseClient()->collections[$collection]->delete();
        } catch (Throwable) {
            // Never created, or already cleaned up — fine either way.
        }
    }
}
