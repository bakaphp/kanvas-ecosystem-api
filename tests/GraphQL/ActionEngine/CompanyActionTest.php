<?php

declare(strict_types=1);

namespace Tests\GraphQL\ActionEngine;

use Tests\TestCase;

class CompanyActionTest extends TestCase
{
    public function testGetCompanyActions(): void
    {
        $this->graphQL('
            query {
                companyActions {
                    data {
                        id
                        name
                        description
                        form_config
                        status
                        is_active
                        is_published
                        weight
                        pipeline {
                            id
                        }
                        parent {
                            id
                        }
                        children {
                            id
                        }
                    }
                }
            }
        ')
        ->assertSuccessful()
        ->assertJsonStructure([
            'data' => [
                'companyActions' => [
                    'data' => [
                        '*' => [
                            'id',
                            'name',
                            'description',
                            'form_config',
                            'status',
                            'is_active',
                            'is_published',
                            'weight',
                            'pipeline' => ['id'],
                            'parent'   => ['id'],
                            'children' => [['id']],
                        ],
                    ],
                ],
            ],
        ]);
    }
}
