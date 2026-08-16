<?php

declare(strict_types=1);

namespace Tests\Guild\Customers\Search;

use Baka\Search\Contracts\NameSearchInterface;
use Baka\Search\EngineNameSearch;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Collection;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Customers\Contracts\PeopleCandidateSourceInterface;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Customers\Search\PeopleEngineCandidateSource;
use Kanvas\Guild\Customers\Search\PeopleSqlCandidateSource;
use Kanvas\Users\Models\Users;
use Laravel\Scout\Builder as ScoutBuilder;
use Laravel\Scout\Engines\Engine;
use Tests\TestCase;

final class PeopleCandidateSourceTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'crm'];

    private Apps $currentApp;
    private Companies $currentCompany;
    private Users $actingUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->currentApp = app(Apps::class);
        $this->actingUser = static::$cachedUser;
        $this->currentCompany = $this->actingUser->getCurrentCompany();
    }

    public function testEveryQueryByFieldExistsInThePeopleTypesenseSchemaAsAString(): void
    {
        $fields = [];
        foreach (new People()->typesenseCollectionSchema()['fields'] as $field) {
            $fields[$field['name']] = $field['type'];
        }

        foreach (explode(',', PeopleCandidateSourceInterface::QUERY_BY) as $name) {
            $this->assertArrayHasKey($name, $fields, "query_by names '{$name}' but the collection has no such field");
            $this->assertContains($fields[$name], ['string', 'string[]'], "query_by field '{$name}' must be string");
        }
    }

    public function testEngineSourceIssuesOnePerNameQueryCarryingTheTenantFilters(): void
    {
        $engine = $this->fakeEngine([]);

        new PeopleEngineCandidateSource(new EngineNameSearch($engine))->candidatesFor(
            $this->currentApp,
            $this->currentCompany,
            [
                ['query' => 'Jorgelina Duran', 'tokens' => ['jorgelina', 'duran']],
                ['query' => 'Sandra Pichardo', 'tokens' => ['sandra', 'pichardo']],
            ],
        );

        $this->assertCount(2, $engine->builders, 'each name must get its own query — no shared candidate budget');

        foreach ($engine->builders as $builder) {
            $wheres = array_column($builder->wheres, 'value', 'field');

            $this->assertSame($this->currentApp->getId(), $wheres['apps_id'] ?? null);
            $this->assertSame($this->currentCompany->getId(), $wheres['companies_id'] ?? null);
            $this->assertSame(NameSearchInterface::DEFAULT_CANDIDATES_PER_TERM, $builder->limit);
        }

        $this->assertSame(['Jorgelina Duran', 'Sandra Pichardo'], array_map(
            fn (ScoutBuilder $b): string => $b->query,
            $engine->builders,
        ));
    }

    public function testEngineSourceHydratesTheIdsTheEngineReturned(): void
    {
        $person = $this->makePerson('Enginehituniq', 'Contactcc');

        $candidates = new PeopleEngineCandidateSource(new EngineNameSearch($this->fakeEngine([(string) $person->getId()])))
            ->candidatesFor(
                $this->currentApp,
                $this->currentCompany,
                [['query' => 'Enginehituniq Contactcc', 'tokens' => ['enginehituniq', 'contactcc']]],
            );

        $this->assertCount(1, $candidates);
        $this->assertSame((int) $person->getId(), (int) $candidates->first()->getId());
    }

    /** The engine filter lives in a remote index; hydration re-scopes so a bad hit can never leak. */
    public function testEngineSourceDropsIdsThatBelongToAnotherCompany(): void
    {
        $foreign = People::factory()
            ->withAppId($this->currentApp->getId())
            ->withCompanyId($this->currentCompany->getId() + 99999)
            ->create(['firstname' => 'Foreignuniq', 'lastname' => 'Tenantuniq']);

        $candidates = new PeopleEngineCandidateSource(new EngineNameSearch($this->fakeEngine([(string) $foreign->getId()])))
            ->candidatesFor(
                $this->currentApp,
                $this->currentCompany,
                [['query' => 'Foreignuniq Tenantuniq', 'tokens' => ['foreignuniq', 'tenantuniq']]],
            );

        $this->assertCount(0, $candidates, 'a hit from another company must not survive hydration');
    }

    public function testSqlAndEngineSourcesAgreeOnAKnownPerson(): void
    {
        $person = $this->makePerson('Parityuniq', 'Matcheruniq');
        $terms = [['query' => 'Parityuniq Matcheruniq', 'tokens' => ['parityuniq', 'matcheruniq']]];

        $sql = new PeopleSqlCandidateSource()->candidatesFor($this->currentApp, $this->currentCompany, $terms);
        $engine = new PeopleEngineCandidateSource(new EngineNameSearch($this->fakeEngine([(string) $person->getId()])))
            ->candidatesFor($this->currentApp, $this->currentCompany, $terms);

        $this->assertSame(
            $sql->pluck('id')->map('intval')->sort()->values()->all(),
            $engine->pluck('id')->map('intval')->sort()->values()->all(),
        );
    }

    /**
     * @param list<string> $returnIds
     */
    private function fakeEngine(array $returnIds): Engine
    {
        return new class ($returnIds) extends Engine {
            /** @var list<ScoutBuilder> */
            public array $builders = [];

            /** @param list<string> $returnIds */
            public function __construct(private readonly array $returnIds)
            {
            }

            public function search(ScoutBuilder $builder)
            {
                $this->builders[] = $builder;

                return $this->returnIds;
            }

            public function mapIds($results)
            {
                return new Collection($results);
            }

            public function update($models)
            {
            }

            public function delete($models)
            {
            }

            public function paginate(ScoutBuilder $builder, $perPage, $page)
            {
                return [];
            }

            public function map(ScoutBuilder $builder, $results, $model)
            {
                return new Collection();
            }

            public function lazyMap(ScoutBuilder $builder, $results, $model)
            {
                return new Collection();
            }

            public function getTotalCount($results)
            {
                return count($results);
            }

            public function flush($model)
            {
            }

            public function createIndex($name, array $options = [])
            {
            }

            public function deleteIndex($name)
            {
            }
        };
    }

    private function makePerson(string $first, string $last): People
    {
        return People::factory()
            ->withAppId($this->currentApp->getId())
            ->withCompanyId($this->currentCompany->getId())
            ->withUserId($this->actingUser->getId())
            ->create(['firstname' => $first, 'lastname' => $last]);
    }
}
