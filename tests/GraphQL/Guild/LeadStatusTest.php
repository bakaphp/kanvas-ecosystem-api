<?php

namespace Tests\GraphQL\Guild;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class LeadStatusTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'crm'];

    public function testCreateLeadStatus(): void
    {
        $input = [
            'name' => fake()->word,
            'is_default' => (int) fake()->boolean,
            'is_open' => true,
        ];

        $this->graphQL(
            '
            mutation createLeadStatus($input: LeadStatusInput!) {
                createLeadStatus(input: $input){
                    name,
                    is_default,
                    is_open,
                }
            }
            ',
            [
                'input' => $input,
            ]
        )->assertJson([
            'data' => [
                'createLeadStatus' => [
                    'name' => $input['name'],
                    'is_default' => $input['is_default'],
                    'is_open' => true,
                ],
            ],
        ]);
    }

    public function testCreateLeadStatusWithoutIsOpenIsClosed(): void
    {
        $this->graphQL(
            '
            mutation createLeadStatus($input: LeadStatusInput!) {
                createLeadStatus(input: $input){
                    is_open,
                }
            }
            ',
            [
                'input' => [
                    'name' => 'Closed-' . fake()->unique()->uuid(),
                    'is_default' => 0,
                ],
            ]
        )->assertJson([
            'data' => [
                'createLeadStatus' => [
                    'is_open' => false,
                ],
            ],
        ]);
    }

    public function testUpdateLeadStatus(): void
    {
        $input = [
            'name' => fake()->word,
            'is_default' => (int) fake()->boolean,
            'is_open' => false,
        ];
        $response = $this->graphQL(
            '
            mutation createLeadStatus($input: LeadStatusInput!) {
                createLeadStatus(input: $input){
                    id,
                    name,
                    is_default,
                    is_open,
                }
            }
            ',
            [
                'input' => $input,
            ]
        );

        $id = $response->json('data.createLeadStatus.id');
        $input = [
            'name' => fake()->word,
            'is_default' => (int) fake()->boolean,
            'is_open' => true,
        ];
        $this->graphQL(
            '
            mutation updateLeadStatus($id: ID!, $input: LeadStatusInput!) {
                updateLeadStatus(id: $id, input: $input){
                    name,
                    is_default,
                    is_open,
                }
            }
            ',
            [
                'id' => $id,
                'input' => $input,
            ]
        )->assertJson([
            'data' => [
                'updateLeadStatus' => [
                    'name' => $input['name'],
                    'is_default' => $input['is_default'],
                    'is_open' => true,
                ],
            ],
        ]);
    }

    public function testUpdateLeadStatusWithoutIsOpenKeepsIt(): void
    {
        $id = $this->graphQL(
            '
            mutation createLeadStatus($input: LeadStatusInput!) {
                createLeadStatus(input: $input){
                    id,
                }
            }
            ',
            [
                'input' => [
                    'name' => 'Open-' . fake()->unique()->uuid(),
                    'is_default' => 0,
                    'is_open' => true,
                ],
            ]
        )->json('data.createLeadStatus.id');

        $this->graphQL(
            '
            mutation updateLeadStatus($id: ID!, $input: LeadStatusInput!) {
                updateLeadStatus(id: $id, input: $input){
                    is_open,
                }
            }
            ',
            [
                'id' => $id,
                'input' => [
                    'name' => 'Renamed-' . fake()->unique()->uuid(),
                    'is_default' => 0,
                ],
            ]
        )->assertJson([
            'data' => [
                'updateLeadStatus' => [
                    'is_open' => true,
                ],
            ],
        ]);
    }

    public function testDeleteLeadStatus(): void
    {
        $input = [
            'name' => fake()->word,
            'is_default' => (int) fake()->boolean,
        ];
        $response = $this->graphQL(
            '
            mutation createLeadStatus($input: LeadStatusInput!) {
                createLeadStatus(input: $input){
                    id,
                    name,
                    is_default,
                }
            }
            ',
            [
                'input' => $input,
            ]
        );

        $id = $response->json('data.createLeadStatus.id');
        $this->graphQL(
            '
            mutation deleteLeadStatus($id: ID!) {
                deleteLeadStatus(id: $id)
            }
            ',
            [
                'id' => $id,
            ]
        )->assertJson([
            'data' => [
                'deleteLeadStatus' => true,
            ],
        ]);
    }

    public function testLeadStatus(): void
    {
        $this->graphQL(
            '
                {
                    leadStatuses {
                        data {
                            id,
                            name,
                            is_default,
                            is_open,
                        }
                    }
                }
            '
        )->assertJsonStructure([
            'data' => [
                'leadStatuses' => [
                    'data' => [
                        '*' => [
                            'id',
                            'name',
                            'is_default',
                            'is_open',
                        ],
                    ],
                ],
            ],
        ]);
    }

    public function testGlobalLeadStatusesAreVisible(): void
    {
        $this->graphQL(
            '
                {
                    leadStatuses {
                        data {
                            id
                            name
                            is_default
                        }
                    }
                }
            '
        )->assertJsonStructure([
            'data' => [
                'leadStatuses' => [
                    'data' => [],
                ],
            ],
        ])->assertSuccessful();
    }

    public function testLeadStatusesCanBeOrderedByIsDefault(): void
    {
        $this->createLeadStatus('StatusDefault-' . fake()->unique()->uuid(), 1);
        $this->createLeadStatus('StatusRegular-' . fake()->unique()->uuid(), 0);

        $query = '
            query leadStatuses($orderBy: [QueryLeadStatusesOrderByOrderByClause!]) {
                leadStatuses(orderBy: $orderBy) {
                    data {
                        name
                        is_default
                    }
                }
            }
        ';

        $descending = $this->graphQL($query, [
            'orderBy' => [['column' => 'IS_DEFAULT', 'order' => 'DESC']],
        ])->assertOk()->json('data.leadStatuses.data');

        $ascending = $this->graphQL($query, [
            'orderBy' => [['column' => 'IS_DEFAULT', 'order' => 'ASC']],
        ])->assertOk()->json('data.leadStatuses.data');

        $this->assertSame(1, $descending[0]['is_default']);
        $this->assertSame(0, $ascending[0]['is_default']);
    }

    protected function createLeadStatus(string $name, int $isDefault): void
    {
        $this->graphQL(
            '
            mutation createLeadStatus($input: LeadStatusInput!) {
                createLeadStatus(input: $input) {
                    id
                }
            }
            ',
            [
                'input' => [
                    'name' => $name,
                    'is_default' => $isDefault,
                ],
            ]
        )->assertOk();
    }
}
