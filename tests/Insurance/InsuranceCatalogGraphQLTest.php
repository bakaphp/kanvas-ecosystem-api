<?php

declare(strict_types=1);

namespace Tests\Insurance;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class InsuranceCatalogGraphQLTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'commerce', 'insurance'];

    private function createInsurerId(): string
    {
        $response = $this->graphQL('
            mutation($input: InsurerInput!) {
                createInsurer(input: $input) { id name status }
            }
        ', ['input' => ['name' => 'GraphQL Seguros ' . uniqid()]])->assertSuccessful();

        $this->assertSame('ACTIVE', $response->json('data.createInsurer.status'));

        return $response->json('data.createInsurer.id');
    }

    public function testPlanCrudWithNestedChildrenThroughGraphQL(): void
    {
        $insurerId = $this->createInsurerId();

        $created = $this->graphQL('
            mutation($input: InsurancePlanInput!) {
                createInsurancePlan(input: $input) {
                    id
                    type
                    sponsored
                    insurer { id }
                    coverages { name sort_order }
                    exclusions { description }
                }
            }
        ', ['input' => [
            'insurer_id' => $insurerId,
            'name' => 'Ley Plan',
            'type' => 'LAW',
            'sponsored' => true,
            'coverages' => [['name' => 'Liability', 'limit_amount' => 1000000]],
            'exclusions' => [['description' => 'Racing']],
        ]])->assertSuccessful();

        $planId = $created->json('data.createInsurancePlan.id');
        $this->assertSame('LAW', $created->json('data.createInsurancePlan.type'));
        $this->assertTrue($created->json('data.createInsurancePlan.sponsored'));
        $this->assertSame($insurerId, $created->json('data.createInsurancePlan.insurer.id'));
        $this->assertSame('Liability', $created->json('data.createInsurancePlan.coverages.0.name'));

        $updated = $this->graphQL('
            mutation($id: ID!, $input: UpdateInsurancePlanInput!) {
                updateInsurancePlan(id: $id, input: $input) {
                    name
                    type
                    coverages { name }
                    exclusions { description }
                }
            }
        ', ['id' => $planId, 'input' => ['name' => 'Ley Plus', 'type' => 'FULL', 'coverages' => [['name' => 'Fire']]]])->assertSuccessful();

        $this->assertSame('Ley Plus', $updated->json('data.updateInsurancePlan.name'));
        $this->assertSame('FULL', $updated->json('data.updateInsurancePlan.type'));
        $this->assertSame('Fire', $updated->json('data.updateInsurancePlan.coverages.0.name'));
        $this->assertSame('Racing', $updated->json('data.updateInsurancePlan.exclusions.0.description'));

        $listed = $this->graphQL('query { insurancePlans { data { id } } }')->assertSuccessful();
        $this->assertContains($planId, array_column($listed->json('data.insurancePlans.data'), 'id'));

        $this->graphQL('mutation($id: ID!) { deleteInsurancePlan(id: $id) }', ['id' => $planId])
            ->assertSuccessful()
            ->assertJson(['data' => ['deleteInsurancePlan' => true]]);

        $this->graphQL('query { insurancePlans { data { id } } }')
            ->assertSuccessful()
            ->assertJsonMissing(['id' => $planId]);
    }

    public function testInsurerUpdateAndDelete(): void
    {
        $insurerId = $this->createInsurerId();

        $this->graphQL('
            mutation($id: ID!, $input: UpdateInsurerInput!) {
                updateInsurer(id: $id, input: $input) { name status }
            }
        ', ['id' => $insurerId, 'input' => ['name' => 'Renamed', 'status' => 'INACTIVE']])
            ->assertSuccessful()
            ->assertJson(['data' => ['updateInsurer' => ['name' => 'Renamed', 'status' => 'INACTIVE']]]);

        $this->graphQL('mutation($id: ID!) { deleteInsurer(id: $id) }', ['id' => $insurerId])
            ->assertSuccessful()
            ->assertJson(['data' => ['deleteInsurer' => true]]);
    }
}
