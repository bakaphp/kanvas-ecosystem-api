<?php

declare(strict_types=1);

namespace Tests\GraphQL\Guild;

use Tests\TestCase;

class PeopleEmploymentHistoryTest extends TestCase
{
    private function createOrganization(): int
    {
        $response = $this->graphQL('
            mutation($input: OrganizationInput!) {
                createOrganization(input: $input) {
                    id
                }
            }
        ', [
            'input' => [
                'name' => fake()->company(),
                'address' => fake()->address(),
            ],
        ])->json();

        return (int) $response['data']['createOrganization']['id'];
    }

    private function createPeople(): int
    {
        $response = $this->graphQL('
            mutation($input: PeopleInput!) {
                createPeople(input: $input) {
                    id
                }
            }
        ', [
            'input' => [
                'firstname' => fake()->firstName(),
                'lastname' => fake()->lastName(),
                'contacts' => [
                    [
                        'value' => fake()->email(),
                        'contacts_types_id' => 1,
                        'weight' => 0,
                    ],
                ],
                'address' => [],
                'custom_fields' => [],
            ],
        ])->json();

        return (int) $response['data']['createPeople']['id'];
    }

    public function testCreateEmploymentHistory(): void
    {
        $peopleId = $this->createPeople();
        $organizationId = $this->createOrganization();

        $this->graphQL('
            mutation($input: EmploymentPeopleHistoryInput!) {
                createPeopleEmploymentHistory(input: $input) {
                    id
                    position
                    status
                    income
                    organization { id }
                }
            }
        ', [
            'input' => [
                'peoples_id' => $peopleId,
                'organizations_id' => $organizationId,
                'position' => 'Software Engineer',
                'start_date' => '2022-01-01',
                'end_date' => '2023-01-01',
                'income' => 120000,
                'status' => 1,
                'income_type' => 'yearly',
            ],
        ])->assertJson([
            'data' => [
                'createPeopleEmploymentHistory' => [
                    'position' => 'Software Engineer',
                    'status' => 1,
                    'income' => 120000,
                    'organization' => ['id' => $organizationId],
                ],
            ],
        ]);
    }

    public function testUpdateEmploymentHistory(): void
    {
        $peopleId = $this->createPeople();
        $organizationId = $this->createOrganization();

        $created = $this->graphQL('
            mutation($input: EmploymentPeopleHistoryInput!) {
                createPeopleEmploymentHistory(input: $input) {
                    id
                }
            }
        ', [
            'input' => [
                'peoples_id' => $peopleId,
                'organizations_id' => $organizationId,
                'position' => 'Junior Developer',
                'start_date' => '2020-01-01',
                'status' => 1,
            ],
        ])->json();

        $employmentHistoryId = (int) $created['data']['createPeopleEmploymentHistory']['id'];

        $this->graphQL('
            mutation($id: ID!, $input: EmploymentPeopleHistoryInput!) {
                updatePeopleEmploymentHistory(id: $id, input: $input) {
                    id
                    position
                    status
                }
            }
        ', [
            'id' => $employmentHistoryId,
            'input' => [
                'organizations_id' => $organizationId,
                'position' => 'Senior Developer',
                'start_date' => '2020-01-01',
                'end_date' => '2024-01-01',
                'status' => 0,
            ],
        ])->assertJson([
            'data' => [
                'updatePeopleEmploymentHistory' => [
                    'id' => $employmentHistoryId,
                    'position' => 'Senior Developer',
                    'status' => 0,
                ],
            ],
        ]);
    }

    public function testDeleteEmploymentHistory(): void
    {
        $peopleId = $this->createPeople();
        $organizationId = $this->createOrganization();

        $created = $this->graphQL('
            mutation($input: EmploymentPeopleHistoryInput!) {
                createPeopleEmploymentHistory(input: $input) {
                    id
                }
            }
        ', [
            'input' => [
                'peoples_id' => $peopleId,
                'organizations_id' => $organizationId,
                'position' => 'Contractor',
                'start_date' => '2021-01-01',
                'status' => 1,
            ],
        ])->json();

        $employmentHistoryId = (int) $created['data']['createPeopleEmploymentHistory']['id'];

        $this->graphQL('
            mutation($id: ID!) {
                deletePeopleEmploymentHistory(id: $id)
            }
        ', [
            'id' => $employmentHistoryId,
        ])->assertJson([
            'data' => [
                'deletePeopleEmploymentHistory' => true,
            ],
        ]);
    }
}
