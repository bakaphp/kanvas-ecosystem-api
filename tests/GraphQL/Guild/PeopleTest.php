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
                        'county' => fake()->city(),
                        'state' => fake()->state(),
                        'country' => fake()->country(),
                        'zip' => fake()->postcode(),
                    ],
                ],
                'custom_fields' => [],
                'organization' => fake()->company(),
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
                    'county' => fake()->city(),
                    'state' => fake()->state(),
                    'country' => fake()->country(),
                    'zip' => fake()->postcode(),
                ],
            ],
            'custom_fields' => [],
            'organization' => fake()->company(),
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

    public function testCreatePeopleWithHistory()
    {
        $user = auth()->user();
        $branch = $user->getCurrentBranch();
        $firstname = fake()->firstName();
        $middlename = fake()->firstName();
        $lastname = fake()->lastName();
        $name = $firstname . ' ' . $middlename . ' ' . $lastname;

        $organizationInput = [
            'name' => fake()->company(),
            'address' => fake()->address(),
        ];

        $response = $this->graphQL('
            mutation($input: OrganizationInput!) {
                createOrganization(input: $input) {                
                    id
                    name
                }
            }
        ', [
           'input' => $organizationInput,
        ])->json();

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
                    'county' => fake()->city(),
                    'state' => fake()->state(),
                    'country' => fake()->country(),
                    'zip' => fake()->postcode(),
                ],
            ],
            'custom_fields' => [],
            'peopleEmploymentHistory' => [
                [
                    'organizations_id' => $response['data']['createOrganization']['id'],
                    'position' => 'developer',
                    'start_date' => fake()->date(),
                    'end_date' => fake()->date(),
                    'income' => 1000,
                    'status' => 1,
                ],
            ],
            'organization' => fake()->company(),
        ];

        $response = $this->graphQL('
        mutation($input: PeopleInput!) {
            createPeople(input: $input) {                
                employment_history {
                    id
                }
            }
        }
    ', [
             'input' => $input,
    ]);
        $response->assertJsonStructure([
                     'data' => [
                         'createPeople' => [
                             'employment_history' => [
                                 [
                                     'id',
                                 ],
                             ],
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
                    'county' => fake()->city(),
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
        $response = $this->graphQL('
        mutation($id: ID!, $input: PeopleInput!) {
            updatePeople(id: $id, input: $input) {
                id
                name
            }
        }
    ', [
            'id' => $peopleId,
            'input' => $input,
    ]);
        $response->assertJson([
                'data' => [
                    'updatePeople' => [
                        'id' => $peopleId,
                        'name' => $name,
                    ],
                ],
            ]);
    }

    public function testUpdateContactPeople()
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
                    'county' => fake()->city(),
                    'state' => fake()->state(),
                    'country' => fake()->country(),
                    'zip' => fake()->postcode(),
                ],
            ],
            'custom_fields' => [],
        ];

        $response = $this->graphQL('
        mutation($input: PeopleInput!) {
            createPeople(input: $input) {   
                id,             
                firstname,
                middlename,
                lastname,
                name,
                contacts {
                    id
                }
            }
        }
    ', [
            'input' => $input,
        ]);
        $peopleId = $response['data']['createPeople']['id'];
        $contactId = $response['data']['createPeople']['contacts'][0]['id'];

        $firstname = fake()->firstName();
        $lastname = fake()->lastName();
        $name = $firstname . ' ' . $lastname;
        $input = [
            'firstname' => $firstname,
            'lastname' => $lastname,
            'contacts' => [
                [
                    'id' => $contactId,
                    'value' => fake()->email(),
                    'contacts_types_id' => 1,
                ],
            ],
            'address' => [],
            'custom_fields' => [],
        ];

        $response = $this->graphQL('
        mutation($id: ID!, $input: PeopleInput!) {
            updatePeople(id: $id, input: $input) {
                id
                name,
                contacts {
                    id,
                    value
                }
            }
        }
    ', [
            'id' => $peopleId,
            'input' => $input,
            ]);
        $response->assertJson([
                'data' => [
                    'updatePeople' => [
                        'id' => $peopleId,
                        'name' => $name,
                        'contacts' => [
                            [
                                'id' => $contactId,
                                'value' => $input['contacts'][0]['value'],
                            ],
                        ],
                    ],
                ],
            ]);
    }

    public function testCreatePeopleWithOptOutContact(): void
    {
        $email = fake()->email();
        $input = [
            'firstname' => fake()->firstName(),
            'lastname' => fake()->lastName(),
            'contacts' => [
                [
                    'value' => $email,
                    'contacts_types_id' => 1,
                    'weight' => 0,
                    'is_opt_out' => true,
                ],
            ],
            'address' => [],
            'custom_fields' => [],
        ];

        $response = $this->graphQL('
            mutation($input: PeopleInput!) {
                createPeople(input: $input) {
                    id
                    contacts {
                        id
                        value
                        is_opt_out
                    }
                }
            }
        ', ['input' => $input]);

        $response->assertSuccessful();
        $contacts = $response->json('data.createPeople.contacts');
        $this->assertNotEmpty($contacts);
        $this->assertEquals($email, $contacts[0]['value']);
        $this->assertTrue($contacts[0]['is_opt_out']);
    }

    public function testUpdatePeopleContactOptOut(): void
    {
        $email = fake()->email();
        $input = [
            'firstname' => fake()->firstName(),
            'lastname' => fake()->lastName(),
            'contacts' => [
                [
                    'value' => $email,
                    'contacts_types_id' => 1,
                    'weight' => 0,
                    'is_opt_out' => false,
                ],
            ],
            'address' => [],
            'custom_fields' => [],
        ];

        $createResponse = $this->graphQL('
            mutation($input: PeopleInput!) {
                createPeople(input: $input) {
                    id
                    contacts {
                        id
                        value
                        is_opt_out
                    }
                }
            }
        ', ['input' => $input]);

        $createResponse->assertSuccessful();
        $peopleId = $createResponse->json('data.createPeople.id');
        $contactId = $createResponse->json('data.createPeople.contacts.0.id');
        $this->assertFalse($createResponse->json('data.createPeople.contacts.0.is_opt_out'));

        $updateInput = [
            'firstname' => fake()->firstName(),
            'lastname' => fake()->lastName(),
            'contacts' => [
                [
                    'id' => $contactId,
                    'value' => $email,
                    'contacts_types_id' => 1,
                    'is_opt_out' => true,
                ],
            ],
            'address' => [],
            'custom_fields' => [],
        ];

        $updateResponse = $this->graphQL('
            mutation($id: ID!, $input: PeopleInput!) {
                updatePeople(id: $id, input: $input) {
                    id
                    contacts {
                        id
                        value
                        is_opt_out
                    }
                }
            }
        ', ['id' => $peopleId, 'input' => $updateInput]);

        $updateResponse->assertSuccessful();
        $this->assertTrue($updateResponse->json('data.updatePeople.contacts.0.is_opt_out'));
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
                    'county' => fake()->city(),
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
                    'county' => fake()->city(),
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

    public function testDeletePeopleAddress()
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
            ],
            'address' => [
                [
                    'address' => fake()->address(),
                    'city' => fake()->city(),
                    'county' => fake()->city(),
                    'state' => fake()->state(),
                    'country' => fake()->country(),
                    'zip' => fake()->postcode(),
                ],
            ],
            'custom_fields' => [],
        ];

        // First create a person with address
        $response = $this->graphQL('
            mutation($input: PeopleInput!) {
                createPeople(input: $input) {                
                    id,
                    address {
                        id
                        address
                        city
                    }
                }
            }
        ', [
            'input' => $input,
        ]);

        $response->assertOk();

        $peopleId = $response->json('data.createPeople.id');
        $addressId = $response->json('data.createPeople.address.0.id');

        $this->assertNotNull($addressId);

        // Delete the address
        $deleteResponse = $this->graphQL('
            mutation($id: ID!) {
                deletePeopleAddress(id: $id)
            }
        ', [
            'id' => $addressId,
        ]);

        $deleteResponse->assertJson([
            'data' => [
                'deletePeopleAddress' => true,
            ],
        ]);

        // Verify the address was deleted by checking the person's addresses
        $verifyResponse = $this->graphQL('
            query($id: ID!) {
                people(id: $id) {
                    id
                    address {
                        id
                    }
                }
            }
        ', [
            'id' => $peopleId,
        ]);

        $verifyResponse->assertOk();
        // The address array should be empty after deletion
        $this->assertEmpty($verifyResponse->json('data.people.address'));
    }

    public function testImportUsers()
    {
        $user = auth()->user();
        $branch = $user->getCurrentBranch();
        $firstname = fake()->firstName();
        $middlename = fake()->firstName();
        $lastname = fake()->lastName();
        $name = $firstname . ' ' . $middlename . ' ' . $lastname;

        $peoplesToImport = [
            [
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
                        'county' => fake()->city(),
                        'state' => fake()->state(),
                        'country' => fake()->country(),
                        'zip' => fake()->postcode(),
                    ],
                ],
                'custom_fields' => [
                    [
                        'name' => 'paid_subscription',
                        'data' => 1,
                    ],
                    [
                        'name' => 'position',
                        'data' => 'developer',
                    ],
                ],
            ],[
                'firstname' => fake()->firstName(),
                'middlename' => fake()->firstName(), // @todo remove this
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
                        'county' => fake()->city(),
                        'state' => fake()->state(),
                        'country' => fake()->country(),
                        'zip' => fake()->postcode(),
                    ],
                ],
                'custom_fields' => [
                    [
                        'name' => 'paid_subscription',
                        'data' => 0,
                    ],
                    [
                        'name' => 'position',
                        'data' => 'accountant',
                    ],
                ],
            ],
        ];

        $this->graphQL('
        mutation($input: [PeopleInput!]!) {
            importPeoples(input: $input) 
        }
    ', [
            'input' => $peoplesToImport,
        ])->assertSee('importPeoples');
    }

    public function testCountPeople()
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
                    'county' => fake()->city(),
                    'state' => fake()->state(),
                    'country' => fake()->country(),
                    'zip' => fake()->postcode(),
                ],
            ],
            'custom_fields' => [],
        ];

        $this->createPeopleAndResponse($input);

        $response = $this->graphQL('
            query {
                peopleCount
            }
        ');
        $response->assertJsonStructure([
                'data' => [
                    'peopleCount',
                ],
            ]);
        $this->assertTrue(is_int($response['data']['peopleCount']));
    }

    public function testPeopleCountBySubscriptionType()
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
                    'county' => fake()->city(),
                    'state' => fake()->state(),
                    'country' => fake()->country(),
                    'zip' => fake()->postcode(),
                ],
            ],
            'custom_fields' => [],
        ];

        $this->createPeopleAndResponse($input);

        $response = $this->graphQL('
            query {
                peopleCountBySubscriptionType(
                    type: "Free"
                )
            }
        ');
        $response->assertJsonStructure([
                'data' => [
                    'peopleCountBySubscriptionType',
                ],
            ]);
        $this->assertTrue(is_int($response['data']['peopleCountBySubscriptionType']));
    }
}
