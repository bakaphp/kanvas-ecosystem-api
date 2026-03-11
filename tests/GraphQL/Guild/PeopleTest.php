<?php

declare(strict_types=1);

namespace Tests\GraphQL\Guild;

use Kanvas\Guild\Customers\Models\People;
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

    public function testCreatePeopleNormalizesPhoneBeforeDedup(): void
    {
        $rawPhone = '+1 (809) 555-1234';
        $normalizedPhone = '18095551234';

        $input = [
            'firstname' => fake()->firstName(),
            'lastname' => fake()->lastName(),
            'contacts' => [
                [
                    'value' => $rawPhone,
                    'contacts_types_id' => 2,
                    'weight' => 0,
                ],
                [
                    'value' => $normalizedPhone,
                    'contacts_types_id' => 2,
                    'weight' => 0,
                ],
            ],
            'address' => [],
            'custom_fields' => [],
        ];

        $response = $this->createPeopleAndResponse($input);
        $peopleId = $response['data']['createPeople']['id'];

        $people = People::find($peopleId);
        $phoneContacts = $people->contacts()
            ->where('contacts_types_id', 2)
            ->get();

        $this->assertCount(1, $phoneContacts, 'Duplicate phone numbers with different formatting should be deduplicated');
        $this->assertEquals($normalizedPhone, $phoneContacts->first()->value);
    }

    public function testUpdatePeopleNormalizesPhoneBeforeDedup(): void
    {
        $input = [
            'firstname' => fake()->firstName(),
            'lastname' => fake()->lastName(),
            'contacts' => [
                [
                    'value' => '8095551234',
                    'contacts_types_id' => 2,
                    'weight' => 0,
                ],
            ],
            'address' => [],
            'custom_fields' => [],
        ];

        $response = $this->createPeopleAndResponse($input);
        $peopleId = $response['data']['createPeople']['id'];

        $updateInput = [
            'firstname' => $input['firstname'],
            'lastname' => $input['lastname'],
            'contacts' => [
                [
                    'value' => '+1 (809) 555-1234',
                    'contacts_types_id' => 2,
                    'weight' => 0,
                ],
            ],
            'address' => [],
            'custom_fields' => [],
        ];

        $this->graphQL('
            mutation($id: ID!, $input: PeopleInput!) {
                updatePeople(id: $id, input: $input) {
                    id
                }
            }
        ', [
            'id' => $peopleId,
            'input' => $updateInput,
        ])->assertSuccessful();

        $people = People::find($peopleId);
        $phoneContacts = $people->contacts()
            ->where('contacts_types_id', 2)
            ->get();

        $this->assertCount(1, $phoneContacts, 'Phone with different formatting should match existing normalized phone');
    }

    public function testCreatePeopleDoesNotDuplicateExistingContacts(): void
    {
        $email = fake()->unique()->safeEmail();
        $phone = '8095559999';

        $input = [
            'firstname' => fake()->firstName(),
            'lastname' => fake()->lastName(),
            'contacts' => [
                [
                    'value' => $email,
                    'contacts_types_id' => 1,
                    'weight' => 0,
                ],
                [
                    'value' => $phone,
                    'contacts_types_id' => 2,
                    'weight' => 0,
                ],
            ],
            'address' => [],
            'custom_fields' => [],
        ];

        $response = $this->createPeopleAndResponse($input);
        $peopleId = $response['data']['createPeople']['id'];

        $people = People::find($peopleId);
        $this->assertCount(2, $people->contacts);

        // Try to create/update the same person with the same contacts again
        $this->graphQL('
            mutation($id: ID!, $input: PeopleInput!) {
                updatePeople(id: $id, input: $input) {
                    id
                }
            }
        ', [
            'id' => $peopleId,
            'input' => $input,
        ])->assertSuccessful();

        $people->refresh();
        $allContacts = $people->contacts()->get();

        $emailContacts = $allContacts->where('contacts_types_id', 1)->where('value', $email);
        $phoneContacts = $allContacts->where('contacts_types_id', 2)->where('value', $phone);

        $this->assertCount(1, $emailContacts, 'Should not create duplicate email contact');
        $this->assertCount(1, $phoneContacts, 'Should not create duplicate phone contact');
    }

    public function testCreatePeopleDoesNotDuplicateSameContactInInput(): void
    {
        $email = fake()->unique()->safeEmail();
        $phone = '8095557777';

        $input = [
            'firstname' => fake()->firstName(),
            'lastname' => fake()->lastName(),
            'contacts' => [
                [
                    'value' => $email,
                    'contacts_types_id' => 1,
                    'weight' => 0,
                ],
                [
                    'value' => $email,
                    'contacts_types_id' => 1,
                    'weight' => 1,
                ],
                [
                    'value' => strtoupper($email),
                    'contacts_types_id' => 1,
                    'weight' => 2,
                ],
                [
                    'value' => $phone,
                    'contacts_types_id' => 2,
                    'weight' => 0,
                ],
                [
                    'value' => '(809) 555-7777',
                    'contacts_types_id' => 2,
                    'weight' => 1,
                ],
            ],
            'address' => [],
            'custom_fields' => [],
        ];

        $response = $this->createPeopleAndResponse($input);
        $peopleId = $response['data']['createPeople']['id'];

        $people = People::find($peopleId);
        $emailContacts = $people->contacts()
            ->where('contacts_types_id', 1)
            ->get();
        $phoneContacts = $people->contacts()
            ->where('contacts_types_id', 2)
            ->get();

        $this->assertCount(1, $emailContacts, 'Duplicate emails in same input (including case variants) should be deduplicated');
        $this->assertCount(1, $phoneContacts, 'Duplicate phones in same input (including formatted variants) should be deduplicated');
    }

    public function testSameValueDifferentTypeIsNotDuplicate(): void
    {
        $phone = '8095551111';

        $input = [
            'firstname' => fake()->firstName(),
            'lastname' => fake()->lastName(),
            'contacts' => [
                [
                    'value' => $phone,
                    'contacts_types_id' => 2,
                    'weight' => 0,
                ],
                [
                    'value' => $phone,
                    'contacts_types_id' => 3,
                    'weight' => 0,
                ],
            ],
            'address' => [],
            'custom_fields' => [],
        ];

        $response = $this->createPeopleAndResponse($input);
        $peopleId = $response['data']['createPeople']['id'];

        $people = People::find($peopleId);
        $phoneContacts = $people->contacts()->get();

        $this->assertCount(2, $phoneContacts, 'Same value with different contact types should NOT be deduplicated');
    }

    public function testUpdateContactOptOutByIdDoesNotDeleteOthers(): void
    {
        $email = fake()->unique()->safeEmail();
        $phone = '8095552222';

        $input = [
            'firstname' => fake()->firstName(),
            'lastname' => fake()->lastName(),
            'contacts' => [
                [
                    'value' => $email,
                    'contacts_types_id' => 1,
                    'weight' => 0,
                ],
                [
                    'value' => $phone,
                    'contacts_types_id' => 2,
                    'weight' => 0,
                ],
            ],
            'address' => [],
            'custom_fields' => [],
        ];

        $response = $this->createPeopleAndResponse($input);
        $peopleId = $response['data']['createPeople']['id'];

        $people = People::find($peopleId);
        $allContacts = $people->contacts()->get();
        $this->assertCount(2, $allContacts);

        $phoneContact = $allContacts->where('contacts_types_id', 2)->first();

        // Opt-out a single contact by ID — should NOT delete the email
        $updateInput = [
            'firstname' => $input['firstname'],
            'lastname' => $input['lastname'],
            'contacts' => [
                [
                    'id' => $phoneContact->id,
                    'value' => $phone,
                    'contacts_types_id' => 2,
                    'weight' => 0,
                    'is_opt_out' => true,
                ],
            ],
            'address' => [],
            'custom_fields' => [],
        ];

        $this->graphQL('
            mutation($id: ID!, $input: PeopleInput!) {
                updatePeople(id: $id, input: $input) { id }
            }
        ', ['id' => $peopleId, 'input' => $updateInput])->assertSuccessful();

        $people->refresh();
        $updatedContacts = $people->contacts()->get();

        $this->assertCount(2, $updatedContacts, 'Opt-out update by ID should not delete other contacts');
        $optedOutPhone = $updatedContacts->where('contacts_types_id', 2)->first();
        $this->assertEquals(1, $optedOutPhone->is_opt_out, 'Phone should be opted out');
        $this->assertTrue($updatedContacts->contains('value', strtolower($email)), 'Email should still exist');
    }

    public function testUpdatePeopleOptOutWithFormattedPhone(): void
    {
        $email = fake()->unique()->safeEmail();
        $phone = '4047907131';

        $input = [
            'firstname' => 'JOWSMILK',
            'lastname' => 'PEREZ',
            'contacts' => [
                [
                    'value' => $email,
                    'contacts_types_id' => 1,
                    'weight' => 0,
                ],
                [
                    'value' => $phone,
                    'contacts_types_id' => 3,
                    'weight' => 0,
                ],
            ],
            'address' => [],
            'custom_fields' => [],
        ];

        $response = $this->createPeopleAndResponse($input);
        $peopleId = $response['data']['createPeople']['id'];

        $people = People::find($peopleId);
        $allContacts = $people->contacts()->get();
        $this->assertCount(2, $allContacts);

        $phoneContact = $allContacts->where('contacts_types_id', 3)->first();

        $updateInput = [
            'firstname' => 'JOWSMILK',
            'lastname' => 'PEREZ',
            'contacts' => [
                [
                    'id' => (string) $phoneContact->id,
                    'value' => '(404) 790-7131',
                    'contacts_types_id' => 3,
                    'is_opt_out' => true,
                ],
            ],
            'address' => [],
            'custom_fields' => [],
        ];

        $this->graphQL('
            mutation($id: ID!, $input: PeopleInput!) {
                updatePeople(id: $id, input: $input) { id }
            }
        ', ['id' => $peopleId, 'input' => $updateInput])->assertSuccessful();

        $people->refresh();
        $updatedContacts = $people->contacts()->get();

        $this->assertCount(2, $updatedContacts, 'Should still have both contacts');
        $optedOutPhone = $updatedContacts->where('contacts_types_id', 3)->first();
        $this->assertEquals(1, $optedOutPhone->is_opt_out, 'Cellphone should be opted out');
        $this->assertTrue($updatedContacts->contains('value', strtolower($email)), 'Email should still exist');
    }

    public function testUpdateContactsWithoutIdDeletesOthers(): void
    {
        $email = fake()->unique()->safeEmail();
        $phone = '8095554444';

        $input = [
            'firstname' => fake()->firstName(),
            'lastname' => fake()->lastName(),
            'contacts' => [
                [
                    'value' => $email,
                    'contacts_types_id' => 1,
                    'weight' => 0,
                ],
                [
                    'value' => $phone,
                    'contacts_types_id' => 2,
                    'weight' => 0,
                ],
            ],
            'address' => [],
            'custom_fields' => [],
        ];

        $response = $this->createPeopleAndResponse($input);
        $peopleId = $response['data']['createPeople']['id'];

        // Send only the phone without an ID — full sync, should delete the email
        $updateInput = [
            'firstname' => $input['firstname'],
            'lastname' => $input['lastname'],
            'contacts' => [
                [
                    'value' => $phone,
                    'contacts_types_id' => 2,
                    'weight' => 0,
                ],
            ],
            'address' => [],
            'custom_fields' => [],
        ];

        $this->graphQL('
            mutation($id: ID!, $input: PeopleInput!) {
                updatePeople(id: $id, input: $input) { id }
            }
        ', ['id' => $peopleId, 'input' => $updateInput])->assertSuccessful();

        $people = People::find($peopleId);
        $updatedContacts = $people->contacts()->get();

        $this->assertCount(1, $updatedContacts, 'Full sync without IDs should delete contacts not in input');
        $this->assertEquals($phone, $updatedContacts->first()->value);
    }

    public function testUpdateContact(): void
    {
        $input = [
            'firstname' => fake()->firstName(),
            'lastname' => fake()->lastName(),
            'contacts' => [
                [
                    'value' => fake()->unique()->safeEmail(),
                    'contacts_types_id' => 1,
                    'weight' => 0,
                ],
                [
                    'value' => '8095556666',
                    'contacts_types_id' => 2,
                    'weight' => 0,
                ],
            ],
            'address' => [],
            'custom_fields' => [],
        ];

        $response = $this->createPeopleAndResponse($input);
        $peopleId = $response['data']['createPeople']['id'];

        $people = People::find($peopleId);
        $phoneContact = $people->contacts()->where('contacts_types_id', 2)->first();

        $this->graphQL('
            mutation($id: ID!, $input: UpdateContactInput!) {
                updateContact(id: $id, input: $input) {
                    id
                    value
                    is_opt_out
                }
            }
        ', [
            'id' => $phoneContact->id,
            'input' => ['is_opt_out' => true],
        ])
        ->assertSuccessful()
        ->assertJson([
            'data' => [
                'updateContact' => [
                    'id' => (string) $phoneContact->id,
                    'is_opt_out' => true,
                ],
            ],
        ]);

        $people->refresh();
        $this->assertCount(2, $people->contacts()->get(), 'Other contacts should not be affected');
    }

    public function testDeleteContact(): void
    {
        $input = [
            'firstname' => fake()->firstName(),
            'lastname' => fake()->lastName(),
            'contacts' => [
                [
                    'value' => fake()->unique()->safeEmail(),
                    'contacts_types_id' => 1,
                    'weight' => 0,
                ],
                [
                    'value' => '8095557777',
                    'contacts_types_id' => 2,
                    'weight' => 0,
                ],
            ],
            'address' => [],
            'custom_fields' => [],
        ];

        $response = $this->createPeopleAndResponse($input);
        $peopleId = $response['data']['createPeople']['id'];

        $people = People::find($peopleId);
        $phoneContact = $people->contacts()->where('contacts_types_id', 2)->first();

        $this->graphQL('
            mutation($id: ID!) {
                deleteContact(id: $id)
            }
        ', ['id' => $phoneContact->id])
        ->assertSuccessful()
        ->assertJson(['data' => ['deleteContact' => true]]);

        $people->refresh();
        $remainingContacts = $people->contacts()->get();
        $this->assertCount(1, $remainingContacts, 'Only the deleted contact should be removed');
        $this->assertEquals(1, $remainingContacts->first()->contacts_types_id, 'Email should remain');
    }

    public function testSyncContactsPreservesIdsWhenValuesMatch(): void
    {
        $email = fake()->unique()->safeEmail();
        $phone = '8095551234';
        $cellphone = '8095554321';
        $workPhone = '8095559876';

        $input = [
            'firstname' => fake()->firstName(),
            'lastname' => fake()->lastName(),
            'contacts' => [
                [
                    'value' => $email,
                    'contacts_types_id' => 1,
                    'weight' => 0,
                ],
                [
                    'value' => $phone,
                    'contacts_types_id' => 2,
                    'weight' => 0,
                ],
                [
                    'value' => $cellphone,
                    'contacts_types_id' => 3,
                    'weight' => 0,
                ],
                [
                    'value' => $workPhone,
                    'contacts_types_id' => 8,
                    'weight' => 0,
                ],
            ],
            'address' => [],
            'custom_fields' => [],
        ];

        $response = $this->createPeopleAndResponse($input);
        $peopleId = $response['data']['createPeople']['id'];

        $people = People::find($peopleId);
        $originalContacts = $people->contacts()->orderBy('contacts_types_id')->get();
        $originalEmailId = $originalContacts->where('contacts_types_id', 1)->first()->id;
        $originalPhoneId = $originalContacts->where('contacts_types_id', 2)->first()->id;
        $originalCellId = $originalContacts->where('contacts_types_id', 3)->first()->id;
        $originalWorkId = $originalContacts->where('contacts_types_id', 8)->first()->id;

        $this->assertCount(4, $originalContacts);

        // Simulate external CRM sync — same contacts sent back, like Elead/VinSolution would
        $updateInput = [
            'firstname' => $input['firstname'],
            'lastname' => $input['lastname'],
            'contacts' => [
                [
                    'value' => $email,
                    'contacts_types_id' => 1,
                    'weight' => 0,
                ],
                [
                    'value' => $phone,
                    'contacts_types_id' => 2,
                    'weight' => 0,
                ],
                [
                    'value' => $cellphone,
                    'contacts_types_id' => 3,
                    'weight' => 0,
                ],
                [
                    'value' => $workPhone,
                    'contacts_types_id' => 8,
                    'weight' => 0,
                ],
            ],
            'address' => [],
            'custom_fields' => [],
        ];

        $this->graphQL('
            mutation($id: ID!, $input: PeopleInput!) {
                updatePeople(id: $id, input: $input) { id }
            }
        ', ['id' => $peopleId, 'input' => $updateInput])->assertSuccessful();

        $people->refresh();
        $updatedContacts = $people->contacts()->orderBy('contacts_types_id')->get();

        $this->assertCount(4, $updatedContacts, 'Contact count should stay the same');
        $this->assertEquals($originalEmailId, $updatedContacts->where('contacts_types_id', 1)->first()->id, 'Email contact ID should be preserved');
        $this->assertEquals($originalPhoneId, $updatedContacts->where('contacts_types_id', 2)->first()->id, 'Phone contact ID should be preserved');
        $this->assertEquals($originalCellId, $updatedContacts->where('contacts_types_id', 3)->first()->id, 'Cellphone contact ID should be preserved');
        $this->assertEquals($originalWorkId, $updatedContacts->where('contacts_types_id', 8)->first()->id, 'Work phone contact ID should be preserved');
    }

    public function testSyncContactsPreservesIdsWithFormattedPhone(): void
    {
        $phone = '8095551234';

        $input = [
            'firstname' => fake()->firstName(),
            'lastname' => fake()->lastName(),
            'contacts' => [
                [
                    'value' => $phone,
                    'contacts_types_id' => 2,
                    'weight' => 0,
                ],
            ],
            'address' => [],
            'custom_fields' => [],
        ];

        $response = $this->createPeopleAndResponse($input);
        $peopleId = $response['data']['createPeople']['id'];

        $people = People::find($peopleId);
        $originalPhoneId = $people->contacts()->where('contacts_types_id', 2)->first()->id;

        // External CRM sends back the same number with different formatting
        $updateInput = [
            'firstname' => $input['firstname'],
            'lastname' => $input['lastname'],
            'contacts' => [
                [
                    'value' => '(809) 555-1234',
                    'contacts_types_id' => 2,
                    'weight' => 0,
                ],
            ],
            'address' => [],
            'custom_fields' => [],
        ];

        $this->graphQL('
            mutation($id: ID!, $input: PeopleInput!) {
                updatePeople(id: $id, input: $input) { id }
            }
        ', ['id' => $peopleId, 'input' => $updateInput])->assertSuccessful();

        $people->refresh();
        $updatedContacts = $people->contacts()->get();

        $this->assertCount(1, $updatedContacts, 'Should still have one contact');
        $this->assertEquals($originalPhoneId, $updatedContacts->first()->id, 'Phone contact ID should be preserved even with different formatting');
    }

    public function testSyncContactsAddsNewAndRemovesMissing(): void
    {
        $email = fake()->unique()->safeEmail();
        $phone = '8095559999';

        $input = [
            'firstname' => fake()->firstName(),
            'lastname' => fake()->lastName(),
            'contacts' => [
                [
                    'value' => $email,
                    'contacts_types_id' => 1,
                    'weight' => 0,
                ],
                [
                    'value' => $phone,
                    'contacts_types_id' => 2,
                    'weight' => 0,
                ],
            ],
            'address' => [],
            'custom_fields' => [],
        ];

        $response = $this->createPeopleAndResponse($input);
        $peopleId = $response['data']['createPeople']['id'];

        $people = People::find($peopleId);
        $originalEmailId = $people->contacts()->where('contacts_types_id', 1)->first()->id;

        // External CRM sync: email stays, phone removed, new cellphone added
        $newCell = '8095558888';
        $updateInput = [
            'firstname' => $input['firstname'],
            'lastname' => $input['lastname'],
            'contacts' => [
                [
                    'value' => $email,
                    'contacts_types_id' => 1,
                    'weight' => 0,
                ],
                [
                    'value' => $newCell,
                    'contacts_types_id' => 3,
                    'weight' => 0,
                ],
            ],
            'address' => [],
            'custom_fields' => [],
        ];

        $this->graphQL('
            mutation($id: ID!, $input: PeopleInput!) {
                updatePeople(id: $id, input: $input) { id }
            }
        ', ['id' => $peopleId, 'input' => $updateInput])->assertSuccessful();

        $people->refresh();
        $updatedContacts = $people->contacts()->orderBy('contacts_types_id')->get();

        $this->assertCount(2, $updatedContacts, 'Should have email + cellphone');
        $this->assertEquals($originalEmailId, $updatedContacts->where('contacts_types_id', 1)->first()->id, 'Email ID preserved');
        $this->assertNull($updatedContacts->where('contacts_types_id', 2)->first(), 'Old phone should be removed');
        $this->assertEquals($newCell, $updatedContacts->where('contacts_types_id', 3)->first()->value, 'New cellphone added');
    }

    public function testSyncContactsPreservesOptOutOnResync(): void
    {
        $email = fake()->unique()->safeEmail();
        $phone = '8095557777';

        $input = [
            'firstname' => fake()->firstName(),
            'lastname' => fake()->lastName(),
            'contacts' => [
                [
                    'value' => $email,
                    'contacts_types_id' => 1,
                    'weight' => 0,
                ],
                [
                    'value' => $phone,
                    'contacts_types_id' => 2,
                    'weight' => 0,
                ],
            ],
            'address' => [],
            'custom_fields' => [],
        ];

        $response = $this->createPeopleAndResponse($input);
        $peopleId = $response['data']['createPeople']['id'];

        // Locally opt out the phone via single-contact update
        $people = People::find($peopleId);
        $phoneContact = $people->contacts()->where('contacts_types_id', 2)->first();
        $this->graphQL('
            mutation($id: ID!, $input: PeopleInput!) {
                updatePeople(id: $id, input: $input) { id }
            }
        ', [
            'id' => $peopleId,
            'input' => [
                'firstname' => $input['firstname'],
                'lastname' => $input['lastname'],
                'contacts' => [
                    [
                        'value' => $phone,
                        'contacts_types_id' => 2,
                        'weight' => 0,
                        'id' => (string) $phoneContact->id,
                        'is_opt_out' => true,
                    ],
                ],
                'address' => [],
                'custom_fields' => [],
            ],
        ])->assertSuccessful();

        $phoneContact->refresh();
        $this->assertEquals(1, $phoneContact->is_opt_out, 'Phone should be opted out');

        // Now external CRM re-syncs both contacts WITHOUT is_opt_out
        $updateInput = [
            'firstname' => $input['firstname'],
            'lastname' => $input['lastname'],
            'contacts' => [
                [
                    'value' => $email,
                    'contacts_types_id' => 1,
                    'weight' => 0,
                ],
                [
                    'value' => $phone,
                    'contacts_types_id' => 2,
                    'weight' => 0,
                ],
            ],
            'address' => [],
            'custom_fields' => [],
        ];

        $this->graphQL('
            mutation($id: ID!, $input: PeopleInput!) {
                updatePeople(id: $id, input: $input) { id }
            }
        ', ['id' => $peopleId, 'input' => $updateInput])->assertSuccessful();

        $people->refresh();
        $updatedContacts = $people->contacts()->orderBy('contacts_types_id')->get();

        $this->assertCount(2, $updatedContacts, 'Both contacts should remain');
        $updatedPhone = $updatedContacts->where('contacts_types_id', 2)->first();
        $this->assertEquals($phoneContact->id, $updatedPhone->id, 'Phone ID should be preserved');
        $this->assertEquals(1, $updatedPhone->is_opt_out, 'Opt-out should be preserved when source does not send is_opt_out');
    }
}
