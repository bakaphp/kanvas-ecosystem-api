<?php

declare(strict_types=1);

namespace Tests\GraphQL\Guild;

use Tests\TestCase;

class DealCrudTest extends TestCase
{
    public function testCreateDeal(): void
    {
        $input = [
            'title' => 'Deal ' . fake()->word(),
            'description' => fake()->sentence(),
        ];

        $this->graphQL('
            mutation($input: DealInput!) {
                createDeal(input: $input) {
                    id
                    uuid
                    title
                    description
                }
            }
        ', ['input' => $input])
            ->assertSuccessful()
            ->assertJson(['data' => ['createDeal' => [
                'title' => $input['title'],
                'description' => $input['description'],
            ]]]);
    }

    public function testUpdateDeal(): void
    {
        $createInput = ['title' => 'Deal ' . fake()->word()];

        $createResponse = $this->graphQL('
            mutation($input: DealInput!) {
                createDeal(input: $input) { id title }
            }
        ', ['input' => $createInput])->assertSuccessful();

        $id = $createResponse->json('data.createDeal.id');
        $updateInput = ['title' => 'Updated ' . fake()->word()];

        $this->graphQL('
            mutation($id: ID!, $input: UpdateDealInput!) {
                updateDeal(id: $id, input: $input) { id title }
            }
        ', ['id' => $id, 'input' => $updateInput])
            ->assertSuccessful()
            ->assertJson(['data' => ['updateDeal' => [
                'id' => $id,
                'title' => $updateInput['title'],
            ]]]);
    }

    public function testCreateDealWithCustomFieldsAndFiles(): void
    {
        $input = [
            'title' => 'Deal ' . fake()->word(),
            'custom_fields' => [
                ['name' => 'business_legal_name', 'data' => 'Acme LLC'],
            ],
            'files' => [
                ['url' => 'https://example.com/deals/application.pdf', 'name' => 'application.pdf'],
            ],
        ];

        $response = $this->graphQL('
            mutation($input: DealInput!) {
                createDeal(input: $input) {
                    id
                    custom_fields { data { name value } }
                    files { data { name url } }
                }
            }
        ', ['input' => $input])->assertSuccessful();

        $deal = $response->json('data.createDeal');
        $this->assertSame('Acme LLC', collect($deal['custom_fields']['data'])->firstWhere('name', 'business_legal_name')['value']);
        $this->assertSame('application.pdf', $deal['files']['data'][0]['name']);
    }

    public function testUpdateDealPersistsCustomFieldsAndFilesBeforeTheWorkflowRuns(): void
    {
        $id = $this->graphQL('
            mutation($input: DealInput!) {
                createDeal(input: $input) { id }
            }
        ', ['input' => ['title' => 'Deal ' . fake()->word()]])->assertSuccessful()->json('data.createDeal.id');

        $response = $this->graphQL('
            mutation($id: ID!, $input: UpdateDealInput!) {
                updateDeal(id: $id, input: $input) {
                    id
                    custom_fields { data { name value } }
                    files { data { name } }
                }
            }
        ', ['id' => $id, 'input' => [
            'custom_fields' => [
                ['name' => 'business_dba', 'data' => 'Acme Funding'],
            ],
            'files' => [
                ['url' => 'https://example.com/deals/bank-statement.pdf', 'name' => 'bank-statement.pdf'],
            ],
        ]])->assertSuccessful();

        $deal = $response->json('data.updateDeal');
        $this->assertSame('Acme Funding', collect($deal['custom_fields']['data'])->firstWhere('name', 'business_dba')['value']);
        $this->assertSame('bank-statement.pdf', $deal['files']['data'][0]['name']);
    }

    public function testDealsCanBeFilteredByCustomField(): void
    {
        $marker = 'zoho-' . uniqid();

        $hit = $this->graphQL('
            mutation($input: DealInput!) {
                createDeal(input: $input) { id }
            }
        ', ['input' => [
            'title' => 'Deal ' . fake()->word(),
            'custom_fields' => [['name' => 'ZOHO_DEAL_ID', 'data' => $marker]],
        ]])->assertSuccessful()->json('data.createDeal.id');

        $this->graphQL('
            mutation($input: DealInput!) {
                createDeal(input: $input) { id }
            }
        ', ['input' => ['title' => 'Deal ' . fake()->word()]])->assertSuccessful();

        $response = $this->graphQL('
            query($marker: Mixed) {
                deals(hasCustomFields: {
                    AND: [
                        { column: NAME, value: "ZOHO_DEAL_ID" }
                        { column: VALUE, value: $marker }
                    ]
                }) {
                    data { id title }
                }
            }
        ', ['marker' => $marker])->assertSuccessful();

        $this->assertSame([$hit], array_column($response->json('data.deals.data'), 'id'));
    }

    public function testDeleteDeal(): void
    {
        $createResponse = $this->graphQL('
            mutation($input: DealInput!) {
                createDeal(input: $input) { id }
            }
        ', ['input' => ['title' => 'Deal ' . fake()->word()]])->assertSuccessful();

        $id = $createResponse->json('data.createDeal.id');

        $this->graphQL('
            mutation($id: ID!) { deleteDeal(id: $id) }
        ', ['id' => $id])
            ->assertSuccessful()
            ->assertJson(['data' => ['deleteDeal' => true]]);
    }

    public function testListDeals(): void
    {
        $this->graphQL('
            query { deals { data { id title } } }
        ')
            ->assertSuccessful()
            ->assertJsonStructure(['data' => ['deals' => ['data']]]);
    }
}
