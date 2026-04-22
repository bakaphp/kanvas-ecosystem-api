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
