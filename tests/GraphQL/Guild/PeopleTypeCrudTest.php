<?php

declare(strict_types=1);

namespace Tests\GraphQL\Guild;

use Tests\TestCase;

class PeopleTypeCrudTest extends TestCase
{
    public function testCreatePeopleType(): void
    {
        $input = [
            'name' => 'Test ' . fake()->word(),
            'description' => fake()->sentence(),
            'is_active' => true,
            'is_default' => false,
        ];

        $this->graphQL('
            mutation($input: PeopleTypeInput!) {
                createPeopleType(input: $input) {
                    id
                    uuid
                    name
                    slug
                    description
                    is_active
                    is_default
                }
            }
        ', ['input' => $input])
        ->assertSuccessful()
        ->assertJson([
            'data' => [
                'createPeopleType' => [
                    'name' => $input['name'],
                    'is_active' => true,
                    'is_default' => false,
                ],
            ],
        ]);
    }

    public function testUpdatePeopleType(): void
    {
        $input = ['name' => 'Type ' . fake()->word()];
        $createResponse = $this->graphQL('
            mutation($input: PeopleTypeInput!) {
                createPeopleType(input: $input) { id name }
            }
        ', ['input' => $input])->assertSuccessful();
        $id = $createResponse->json('data.createPeopleType.id');

        $updateInput = ['name' => 'Updated ' . fake()->word()];
        $this->graphQL('
            mutation($id: ID!, $input: PeopleTypeInput!) {
                updatePeopleType(id: $id, input: $input) { id name }
            }
        ', ['id' => $id, 'input' => $updateInput])
        ->assertSuccessful()
        ->assertJson([
            'data' => [
                'updatePeopleType' => [
                    'name' => $updateInput['name'],
                ],
            ],
        ]);
    }

    public function testDeletePeopleType(): void
    {
        $input = ['name' => 'Delete ' . fake()->word()];
        $createResponse = $this->graphQL('
            mutation($input: PeopleTypeInput!) {
                createPeopleType(input: $input) { id }
            }
        ', ['input' => $input])->assertSuccessful();
        $id = $createResponse->json('data.createPeopleType.id');

        $this->graphQL('
            mutation($id: ID!) { deletePeopleType(id: $id) }
        ', ['id' => $id])
        ->assertSuccessful()
        ->assertJson(['data' => ['deletePeopleType' => true]]);
    }

    public function testListPeopleTypes(): void
    {
        $this->graphQL('
            mutation($input: PeopleTypeInput!) {
                createPeopleType(input: $input) { id }
            }
        ', ['input' => ['name' => 'List Test ' . fake()->word()]])->assertSuccessful();

        $this->graphQL('
            query {
                peopleTypes {
                    data {
                        id
                        name
                        slug
                        is_active
                        is_default
                    }
                }
            }
        ')
        ->assertSuccessful()
        ->assertJsonStructure([
            'data' => [
                'peopleTypes' => [
                    'data' => [
                        ['id', 'name', 'slug'],
                    ],
                ],
            ],
        ]);
    }

    public function testCreatePeopleWithType(): void
    {
        $typeInput = ['name' => 'Participant ' . fake()->word()];
        $typeResponse = $this->graphQL('
            mutation($input: PeopleTypeInput!) {
                createPeopleType(input: $input) { id }
            }
        ', ['input' => $typeInput])->assertSuccessful();
        $typeId = $typeResponse->json('data.createPeopleType.id');

        $peopleInput = [
            'firstname' => fake()->firstName(),
            'lastname' => fake()->lastName(),
            'people_type_id' => $typeId,
            'contacts' => [
                [
                    'value' => fake()->email(),
                    'contacts_types_id' => 1,
                    'weight' => 0,
                ],
            ],
        ];

        $this->graphQL('
            mutation($input: PeopleInput!) {
                createPeople(input: $input) {
                    id
                    name
                    people_type {
                        id
                        name
                        slug
                    }
                }
            }
        ', ['input' => $peopleInput])
        ->assertSuccessful()
        ->assertJson([
            'data' => [
                'createPeople' => [
                    'people_type' => [
                        'id' => $typeId,
                        'name' => $typeInput['name'],
                    ],
                ],
            ],
        ]);
    }

    public function testFilterPeopleByType(): void
    {
        $typeInput = ['name' => 'Filter ' . fake()->word()];
        $typeResponse = $this->graphQL('
            mutation($input: PeopleTypeInput!) {
                createPeopleType(input: $input) { id slug }
            }
        ', ['input' => $typeInput])->assertSuccessful();
        $typeId = $typeResponse->json('data.createPeopleType.id');
        $typeSlug = $typeResponse->json('data.createPeopleType.slug');

        $peopleInput = [
            'firstname' => fake()->firstName(),
            'lastname' => fake()->lastName(),
            'people_type_id' => $typeId,
            'contacts' => [
                [
                    'value' => fake()->email(),
                    'contacts_types_id' => 1,
                    'weight' => 0,
                ],
            ],
        ];
        $this->graphQL('
            mutation($input: PeopleInput!) {
                createPeople(input: $input) { id }
            }
        ', ['input' => $peopleInput])->assertSuccessful();

        $this->graphQL('
            query($slug: Mixed!) {
                peoples(
                    hasPeopleType: { column: SLUG, operator: EQ, value: $slug }
                ) {
                    data {
                        id
                        people_type {
                            slug
                        }
                    }
                }
            }
        ', ['slug' => $typeSlug])
        ->assertSuccessful();
    }
}
