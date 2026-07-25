<?php

declare(strict_types=1);

namespace Tests\Guild\Organizations;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Organizations\Models\Organization;
use Tests\TestCase;

/**
 * GraphQL surface tests for the duplicate-cleanup operator UI:
 *   - `findOrganizationDuplicates` query → returns clusters as JSON
 *   - `mergeOrganizations` mutation → executes the merge + returns the target
 *
 * The underlying business logic is covered by FindOrganizationDuplicatesServiceTest +
 * MergeOrganizationsActionTest. This test verifies the GraphQL adapter layer wires inputs/outputs
 * correctly and that Bouncer authorization isn't accidentally gating them.
 */
class OrganizationMergeGraphQLTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'crm', 'accounting'];

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
        $cluster = 'GraphCluster' . uniqid();
        $a = $this->seedOrganization($cluster);
        $b = $this->seedOrganization(strtolower($cluster));

        $response = $this->graphQL('
            query {
                findOrganizationDuplicates(max_groups: 50) {
                    canonical_id
                    member_ids
                    reason
                    sample_name
                    organizations {
                        id
                        name
                    }
                }
            }
        ')->assertSuccessful();

        $groups = $response->json('data.findOrganizationDuplicates');
        $this->assertIsArray($groups);

        // Our cluster should be among the returned groups
        $matching = array_filter($groups, function (array $g) use ($a, $b): bool {
            $ids = array_map('intval', $g['member_ids']);
            sort($ids);

            return $ids === [(int) $a->id, (int) $b->id];
        });
        $this->assertCount(1, $matching, 'The seeded duplicate cluster should appear once in the GraphQL response.');

        $group = array_values($matching)[0];
        $this->assertSame((int) $a->id, $group['canonical_id'], 'Canonical = oldest id.');
        $this->assertSame('exact_name', $group['reason']);

        $memberIds = array_map(fn ($m) => (int) $m['id'], $group['organizations']);
        $this->assertEqualsCanonicalizing([(int) $a->id, (int) $b->id], $memberIds);
    }

    public function test_merge_mutation_collapses_a_single_source_into_target(): void
    {
        $source = $this->seedOrganization('Source ' . uniqid());
        $target = $this->seedOrganization('Target ' . uniqid());

        $response = $this->graphQL('
            mutation($sources: [Int!]!, $target: Int!) {
                mergeOrganizations(source_ids: $sources, target_id: $target) {
                    id
                    name
                }
            }
        ', [
            'sources' => [(int) $source->id],
            'target' => (int) $target->id,
        ])->assertSuccessful();

        $payload = $response->json('data.mergeOrganizations');
        $this->assertSame((int) $target->id, (int) $payload['id'], 'Mutation should return the TARGET row.');
        $this->assertSame($target->name, $payload['name']);

        // Source is soft-deleted
        $source->refresh();
        $this->assertTrue((bool) $source->is_deleted, 'Source must be soft-deleted post-merge.');
    }

    public function test_merge_mutation_collapses_multiple_sources_into_one_target(): void
    {
        $target = $this->seedOrganization('Target ' . uniqid());
        $sourceA = $this->seedOrganization('SourceA ' . uniqid());
        $sourceB = $this->seedOrganization('SourceB ' . uniqid());

        $response = $this->graphQL('
            mutation($sources: [Int!]!, $target: Int!) {
                mergeOrganizations(source_ids: $sources, target_id: $target) {
                    id
                }
            }
        ', [
            'sources' => [(int) $sourceA->id, (int) $sourceB->id],
            'target' => (int) $target->id,
        ])->assertSuccessful();

        $payload = $response->json('data.mergeOrganizations');
        $this->assertSame((int) $target->id, (int) $payload['id']);

        $sourceA->refresh();
        $sourceB->refresh();
        $this->assertTrue((bool) $sourceA->is_deleted);
        $this->assertTrue((bool) $sourceB->is_deleted);
        $this->assertSame((int) $target->id, (int) $sourceA->merged_into_organization_id);
        $this->assertSame((int) $target->id, (int) $sourceB->merged_into_organization_id);
    }

    public function test_merge_mutation_returns_graphql_error_on_self_merge(): void
    {
        $org = $this->seedOrganization('SelfMerge ' . uniqid());

        $response = $this->graphQL('
            mutation($sources: [Int!]!, $target: Int!) {
                mergeOrganizations(source_ids: $sources, target_id: $target) {
                    id
                }
            }
        ', [
            'sources' => [(int) $org->id],
            'target' => (int) $org->id,
        ]);

        // GraphQL surfaces RuntimeException as an errors array
        $errors = $response->json('errors');
        $this->assertNotEmpty($errors, 'Self-merge should produce a GraphQL error rather than silently returning data.');
    }

    private function seedOrganization(string $name): Organization
    {
        return Organization::create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $this->company->getId(),
            'users_id' => static::$cachedUser->getId(),
            'name' => $name,
            'address' => '',
            'total_employees' => 0,
        ]);
    }
}
