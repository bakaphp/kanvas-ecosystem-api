<?php

declare(strict_types=1);

namespace Tests\GraphQL\Guild;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Customers\Models\People;
use Tests\TestCase;

class PeopleMergeGraphQLTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'crm', 'ecosystem'];

    private Apps $kanvasApp;
    private Companies $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->kanvasApp = app(Apps::class);
        $this->company = static::$cachedUser->getCurrentCompany();
    }

    public function test_find_duplicates_query_returns_clusters(): void
    {
        $lastname = 'Pina' . uniqid();
        $a = $this->seedPeople('Andres', $lastname);
        $b = $this->seedPeople('andres', strtolower($lastname));

        $response = $this->graphQL('
            query {
                findGuildPeopleDuplicates(max_groups: 50) {
                    canonical_id
                    member_ids
                    reason
                    sample_name
                    peoples {
                        id
                        firstname
                        lastname
                    }
                }
            }
        ')->assertSuccessful();

        $groups = $response->json('data.findGuildPeopleDuplicates');
        $this->assertIsArray($groups);

        $matching = array_filter($groups, function (array $g) use ($a, $b): bool {
            $ids = array_map('intval', $g['member_ids']);
            sort($ids);

            return $ids === [(int) $a->id, (int) $b->id];
        });
        $this->assertCount(1, $matching, 'The seeded duplicate cluster should appear once in the GraphQL response.');

        $group = array_values($matching)[0];
        $this->assertSame((int) $a->id, $group['canonical_id'], 'Canonical = oldest id.');
        $this->assertSame('exact_name', $group['reason']);

        $memberIds = array_map(fn ($m) => (int) $m['id'], $group['peoples']);
        $this->assertEqualsCanonicalizing([(int) $a->id, (int) $b->id], $memberIds);
        $memberA = array_values(array_filter($group['peoples'], fn ($m) => (int) $m['id'] === (int) $a->id))[0];
        $this->assertSame('Andres', $memberA['firstname']);
    }

    public function test_merge_mutation_collapses_a_single_source_into_target(): void
    {
        $source = $this->seedPeople('Source', 'Person' . uniqid());
        $target = $this->seedPeople('Target', 'Person' . uniqid());

        $response = $this->graphQL('
            mutation($sources: [Int!]!, $target: Int!) {
                mergeGuildPeople(source_ids: $sources, target_id: $target) {
                    id
                    firstname
                }
            }
        ', [
            'sources' => [(int) $source->id],
            'target' => (int) $target->id,
        ])->assertSuccessful();

        $payload = $response->json('data.mergeGuildPeople');
        $this->assertSame((int) $target->id, (int) $payload['id'], 'Mutation should return the TARGET row.');

        $source->refresh();
        $this->assertTrue((bool) $source->is_deleted, 'Source must be soft-deleted post-merge.');
    }

    public function test_merge_mutation_collapses_multiple_sources_into_one_target(): void
    {
        $target = $this->seedPeople('Target', 'Person' . uniqid());
        $sourceA = $this->seedPeople('SourceA', 'Person' . uniqid());
        $sourceB = $this->seedPeople('SourceB', 'Person' . uniqid());

        $response = $this->graphQL('
            mutation($sources: [Int!]!, $target: Int!) {
                mergeGuildPeople(source_ids: $sources, target_id: $target) {
                    id
                }
            }
        ', [
            'sources' => [(int) $sourceA->id, (int) $sourceB->id],
            'target' => (int) $target->id,
        ])->assertSuccessful();

        $payload = $response->json('data.mergeGuildPeople');
        $this->assertSame((int) $target->id, (int) $payload['id']);

        $sourceA->refresh();
        $sourceB->refresh();
        $this->assertTrue((bool) $sourceA->is_deleted);
        $this->assertTrue((bool) $sourceB->is_deleted);
        $this->assertSame((int) $target->id, (int) $sourceA->merged_into_people_id);
        $this->assertSame((int) $target->id, (int) $sourceB->merged_into_people_id);
    }

    public function test_merge_mutation_returns_graphql_error_on_self_merge(): void
    {
        $people = $this->seedPeople('SelfMerge', 'Person' . uniqid());

        $response = $this->graphQL('
            mutation($sources: [Int!]!, $target: Int!) {
                mergeGuildPeople(source_ids: $sources, target_id: $target) {
                    id
                }
            }
        ', [
            'sources' => [(int) $people->id],
            'target' => (int) $people->id,
        ]);

        $errors = $response->json('errors');
        $this->assertNotEmpty($errors, 'Self-merge should produce a GraphQL error rather than silently returning data.');
    }

    private function seedPeople(string $firstname, string $lastname): People
    {
        return People::create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $this->company->getId(),
            'users_id' => static::$cachedUser->getId(),
            'firstname' => $firstname,
            'lastname' => $lastname,
            'name' => $firstname . ' ' . $lastname,
        ]);
    }
}
