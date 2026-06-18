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
 *   - `findGuildOrganizationDuplicates` query → returns clusters as JSON
 *   - `mergeGuildOrganizations` mutation → executes the merge + returns the target
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
                findGuildOrganizationDuplicates(max_groups: 50) {
                    canonical_id
                    member_ids
                    reason
                    sample_name
                }
            }
        ')->assertSuccessful();

        $groups = $response->json('data.findGuildOrganizationDuplicates');
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
    }

    public function test_merge_mutation_collapses_source_into_target(): void
    {
        $source = $this->seedOrganization('Source ' . uniqid());
        $target = $this->seedOrganization('Target ' . uniqid());

        $response = $this->graphQL('
            mutation($source: Int!, $target: Int!) {
                mergeGuildOrganizations(source_id: $source, target_id: $target) {
                    id
                    name
                }
            }
        ', [
            'source' => (int) $source->id,
            'target' => (int) $target->id,
        ])->assertSuccessful();

        $payload = $response->json('data.mergeGuildOrganizations');
        $this->assertSame((int) $target->id, (int) $payload['id'], 'Mutation should return the TARGET row.');
        $this->assertSame($target->name, $payload['name']);

        // Source is soft-deleted
        $source->refresh();
        $this->assertTrue((bool) $source->is_deleted, 'Source must be soft-deleted post-merge.');
    }

    public function test_merge_mutation_returns_graphql_error_on_self_merge(): void
    {
        $org = $this->seedOrganization('SelfMerge ' . uniqid());

        $response = $this->graphQL('
            mutation($source: Int!, $target: Int!) {
                mergeGuildOrganizations(source_id: $source, target_id: $target) {
                    id
                }
            }
        ', [
            'source' => (int) $org->id,
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
