<?php

declare(strict_types=1);

namespace Tests\GraphQL\Guild;

use Tests\TestCase;

class PeopleTest extends TestCase
{
    public function testGetCustomers(): void
    {
        $this->graphQL('
            query {
                peoples {
                    data {
                        uuid
                        name
                    }
                }
            }')->assertOk();
    }

    protected function createPeopleAndResponse(array $input = [])
    {
        $user = auth()->user();
        $branch = $user->getCurrentBranch();
        $title = fake()->title();

        if (empty($input)) {
            $input = [
                'firstname' => fake()->firstName(),
                'lastname' => fake()->lastName(),
                'contacts' => [
                    [
                        'value' => fake()->email(),
                        'contacts_types_id' => 1,
                        'weight' => 0,
                    ],
                    [
                        'value' => fake()->phoneNumber(),
                        'contacts_types_id' => 2,
                        'weight' => 0,
                    ],
                ],
                'address' => [
                    [
                        'address' => fake()->address(),
                        'city' => fake()->city(),
                        'state' => fake()->state(),
                        'country' => fake()->country(),
                        'zip' => fake()->postcode(),
                    ],
                ],
                'custom_fields' => [],
            ];
        }

        return $this->graphQL('
            mutation($input: PeopleInput!) {
                createPeople(input: $input) {                
                    id,
                    uuid,
                    name,
                    dob
                }
            }
        ', [
            'input' => $input,
        ])->json();
    }

    public function testCreatePeople()
    {
        $user = auth()->user();
        $branch = $user->getCurrentBranch();
        $firstname = fake()->firstName();
        $middlename = fake()->firstName();
        $lastname = fake()->lastName();
        $name = $firstname . ' ' . $middlename . ' ' . $lastname;

        $input = [
            'firstname' => $firstname,
            'middlename' => $middlename, // @todo remove this
            'lastname' => $lastname,
            'contacts' => [
                [
                    'value' => fake()->email(),
                    'contacts_types_id' => 1,
                    'weight' => 0,
                ],
                [
                    'value' => fake()->phoneNumber(),
                    'contacts_types_id' => 2,
                    'weight' => 0,
                ],
            ],
            'address' => [
                [
                    'address' => fake()->address(),
                    'city' => fake()->city(),
                    'state' => fake()->state(),
                    'country' => fake()->country(),
                    'zip' => fake()->postcode(),
                ],
            ],
            'custom_fields' => [],
        ];

        $this->graphQL('
        mutation($input: PeopleInput!) {
            createPeople(input: $input) {                
                firstname,
                middlename,
                lastname,
                name,
            }
        }
    ', [
            'input' => $input,
        ])->assertJson([
            'data' => [
                'createPeople' => [
                    'firstname' => $firstname,
                    'middlename' => $middlename,
                    'lastname' => $lastname,
                    'name' => $name,
                ],
            ],
        ]);
    }

    public function testUpdatePeople()
    {
        $user = auth()->user();
        $branch = $user->getCurrentBranch();
        $firstname = fake()->firstName();
        $lastname = fake()->lastName();

        $input = [
            'firstname' => $firstname,
            'lastname' => $lastname,
            'contacts' => [
                [
                    'value' => fake()->email(),
                    'contacts_types_id' => 1,
                    'weight' => 0,
                ],
                [
                    'value' => fake()->phoneNumber(),
                    'contacts_types_id' => 2,
                    'weight' => 0,
                ],
            ],
            'address' => [
                [
                    'address' => fake()->address(),
                    'city' => fake()->city(),
                    'state' => fake()->state(),
                    'country' => fake()->country(),
                    'zip' => fake()->postcode(),
                ],
            ],
            'custom_fields' => [],
        ];

        $response = $this->createPeopleAndResponse($input);

        $peopleId = $response['data']['createPeople']['id'];
        $firstname = fake()->firstName();
        $lastname = fake()->lastName();
        $name = $firstname . ' ' . $lastname;
        $input = [
            'firstname' => $firstname,
            'lastname' => $lastname,
            'contacts' => [],
            'address' => [],
            'custom_fields' => [],
        ];
        $this->graphQL('
        mutation($id: ID!, $input: PeopleInput!) {
            updatePeople(id: $id, input: $input) {
                id
                name
            }
        }
    ', [
            'id' => $peopleId,
            'input' => $input,
        ])->assertJson([
            'data' => [
                'updatePeople' => [
                    'id' => $peopleId,
                    'name' => $name,

                ],
            ],
        ]);
    }

    public function testDeletePeople()
    {
        $user = auth()->user();
        $branch = $user->getCurrentBranch();
        $title = fake()->title();

        $input = [
            'firstname' => fake()->firstName(),
            'lastname' => fake()->lastName(),
            'contacts' => [
                [
                    'value' => fake()->email(),
                    'contacts_types_id' => 1,
                    'weight' => 0,
                ],
                [
                    'value' => fake()->phoneNumber(),
                    'contacts_types_id' => 2,
                    'weight' => 0,
                ],
            ],
            'address' => [
                [
                    'address' => fake()->address(),
                    'city' => fake()->city(),
                    'state' => fake()->state(),
                    'country' => fake()->country(),
                    'zip' => fake()->postcode(),
                ],
            ],
            'custom_fields' => [],
        ];

        $response = $this->createPeopleAndResponse($input);

        $peopleId = $response['data']['createPeople']['id'];

        $this->graphQL('
        mutation($id: ID!) {
            deletePeople(id: $id)
        }
    ', [
            'id' => $peopleId,
        ])->assertJson([
            'data' => [
                'deletePeople' => true,
            ],
        ]);
    }

    public function testRestoreLead()
    {
        $user = auth()->user();
        $branch = $user->getCurrentBranch();
        $title = fake()->title();

        $input = [
            'firstname' => fake()->firstName(),
            'lastname' => fake()->lastName(),
            'contacts' => [
                [
                    'value' => fake()->email(),
                    'contacts_types_id' => 1,
                    'weight' => 0,
                ],
                [
                    'value' => fake()->phoneNumber(),
                    'contacts_types_id' => 2,
                    'weight' => 0,
                ],
            ],
            'address' => [
                [
                    'address' => fake()->address(),
                    'city' => fake()->city(),
                    'state' => fake()->state(),
                    'country' => fake()->country(),
                    'zip' => fake()->postcode(),
                ],
            ],
            'custom_fields' => [],
        ];

        $response = $this->createPeopleAndResponse($input);

        $peopleId = $response['data']['createPeople']['id'];

        $this->graphQL('
            mutation($id: ID!) {
                deletePeople(id: $id)
            }
        ', [
                'id' => $peopleId,
            ])->assertJson([
                'data' => [
                    'deletePeople' => true,
                ],
            ]);

        $this->graphQL('
            mutation($id: ID!) {
                restorePeople(id: $id)
            }
        ', [
                'id' => $peopleId,
            ])->assertJson([
                'data' => [
                    'restorePeople' => true,
                ],
            ]);
    }
}
