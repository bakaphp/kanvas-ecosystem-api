<?php

declare(strict_types=1);

namespace Tests\GraphQL\Guild;

use Tests\TestCase;

class PeopleRelationshipTest extends TestCase
{
    public function testCreatePeopleRelationship(): void
    {
        $input = [
            'name' => 'Test ' . fake()->word(),
            'description' => fake()->sentence(),
        ];

        $this->graphQL('
            mutation($input: PeopleRelationshipInput!) {
                createPeopleRelationship(input: $input) {
                    id
                    name
                    description
                }
            }
        ', ['input' => $input])
        ->assertSuccessful()
        ->assertJson([
            'data' => [
                'createPeopleRelationship' => [
                    'name' => $input['name'],
                    'description' => $input['description'],
                ],
            ],
        ]);
    }

    public function testUpdatePeopleRelationship(): void
    {
        $input = ['name' => 'Test ' . fake()->word()];

        $createResponse = $this->graphQL('
            mutation($input: PeopleRelationshipInput!) {
                createPeopleRelationship(input: $input) {
                    id
                    name
                }
            }
        ', ['input' => $input])->assertSuccessful();

        $id = $createResponse->json('data.createPeopleRelationship.id');

        $updateInput = [
            'name' => 'Updated ' . fake()->word(),
            'description' => fake()->sentence(),
        ];

        $this->graphQL('
            mutation($id: ID!, $input: UpdatePeopleRelationshipInput!) {
                updatePeopleRelationship(id: $id, input: $input) {
                    id
                    name
                    description
                }
            }
        ', ['id' => $id, 'input' => $updateInput])
        ->assertSuccessful()
        ->assertJson([
            'data' => [
                'updatePeopleRelationship' => [
                    'name' => $updateInput['name'],
                    'description' => $updateInput['description'],
                ],
            ],
        ]);
    }

    public function testDeletePeopleRelationship(): void
    {
        $input = ['name' => 'Test ' . fake()->word()];

        $createResponse = $this->graphQL('
            mutation($input: PeopleRelationshipInput!) {
                createPeopleRelationship(input: $input) {
                    id
                }
            }
        ', ['input' => $input])->assertSuccessful();

        $id = $createResponse->json('data.createPeopleRelationship.id');

        $this->graphQL('
            mutation($id: ID!) {
                deletePeopleRelationship(id: $id)
            }
        ', ['id' => $id])
        ->assertSuccessful()
        ->assertJson(['data' => ['deletePeopleRelationship' => true]]);
    }

    public function testListPeopleRelationships(): void
    {
        $this->graphQL('
            mutation($input: PeopleRelationshipInput!) {
                createPeopleRelationship(input: $input) {
                    id
                }
            }
        ', ['input' => ['name' => 'Test ' . fake()->word()]])->assertSuccessful();

        $this->graphQL('
            query {
                peopleRelationships {
                    data {
                        id
                        name
                        description
                    }
                }
            }
        ')
        ->assertSuccessful()
        ->assertJsonStructure([
            'data' => [
                'peopleRelationships' => [
                    'data' => [
                        '*' => ['id', 'name'],
                    ],
                ],
            ],
        ]);
    }
}
