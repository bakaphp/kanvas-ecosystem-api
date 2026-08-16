<?php

declare(strict_types=1);

namespace Tests\Guild\Search;

use Baka\Search\EngineNameSearch;
use Baka\Search\NameSearchResolver;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Models\People;
use Tests\TestCase;

/**
 * The opt-in is what keeps bulk matching deterministic across environments. CI runs a live
 * Meilisearch (SCOUT_DRIVER=meilisearch), so without the gate every bulk-match test would start
 * querying an index that has neither the seeded rows nor apps_id/companies_id marked filterable.
 */
final class NameSearchResolverTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'crm'];

    private Apps $currentApp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->currentApp = app(Apps::class);
    }

    protected function tearDown(): void
    {
        $this->currentApp->set(NameSearchResolver::ENABLED_SETTING, false);

        parent::tearDown();
    }

    public function testReturnsNullWhenTheAppHasNotOptedIn(): void
    {
        $this->currentApp->set(NameSearchResolver::ENABLED_SETTING, false);

        $this->assertNull(
            new NameSearchResolver()->for($this->currentApp, new People()),
            'an app that has not opted in must never reach the engine, whatever SCOUT_DRIVER says',
        );
    }

    /** Models CI exactly: a live engine driver in the environment, no opt-in on the app. */
    public function testStaysOffTheEngineEvenWhenTheEnvironmentHasALiveDriver(): void
    {
        config([
            'scout.driver' => 'meilisearch',
            'scout.meilisearch.host' => 'http://localhost:7700',
            'scout.meilisearch.key' => 'masterKey',
        ]);
        $this->currentApp->set(NameSearchResolver::ENABLED_SETTING, false);

        $this->assertNull(
            new NameSearchResolver()->for($this->currentApp, new People()),
            'SCOUT_DRIVER alone must never route bulk matching at an index nobody verified',
        );
    }

    public function testReturnsNullWhenOptedInButTheAppResolvesNoEngine(): void
    {
        $this->currentApp->set(NameSearchResolver::ENABLED_SETTING, true);
        // Anything SearchEngineResolver's match() doesn't recognise lands on NullEngine. Note a
        // setting stored as the string 'null' comes back as PHP null and would fall through to the
        // global scout.driver instead — which is not the case under test here.
        $this->currentApp->set('search_engine', 'none');

        try {
            $this->assertNull(new NameSearchResolver()->for($this->currentApp, new People()));
        } finally {
            $this->currentApp->set('search_engine', '');
        }
    }

    public function testReturnsAnEngineSearchWhenOptedInAndAnEngineResolves(): void
    {
        $this->currentApp->set(NameSearchResolver::ENABLED_SETTING, true);
        $this->currentApp->set('search_engine', 'meilisearch');

        try {
            $this->assertInstanceOf(
                EngineNameSearch::class,
                new NameSearchResolver()->for($this->currentApp, new People()),
            );
        } finally {
            $this->currentApp->set('search_engine', '');
        }
    }
}
