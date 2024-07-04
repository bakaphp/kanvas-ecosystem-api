<?php

declare(strict_types=1);

namespace Tests\GraphQL\Inventory;

use Tests\TestCase;

class RegionTest extends TestCase
{
    /**
     * testCreateRegion.
     *
     * @return void
     */
    public function testCreateRegion()
    {
        $data = [
            'name' => 'Test Region',
            'slug' => 'test-region',
            'short_slug' => 'test-region',
            'is_default' => 1,
            'currency_id' => 1,
        ];
        $this->graphQL('
            mutation($data: RegionInput!) {
                createRegion(input: $data)
                {
                    id
                    name
                    slug
                    short_slug
                    currency_id
                    is_default
                }
            }
        ', [
            'data' => $data,
        ])->assertJson([
            'data' => ['createRegion' => $data],
        ]);
    }

    /**
     * testFindRegion.
     *
     * @return void
     */
    public function testFindRegion()
    {
        $data = [
            'name' => 'Test Region',
            'slug' => 'test-region',
            'short_slug' => 'test-region',
            'is_default' => 1,
            'currency_id' => 1,
        ];
        $this->graphQL('
            mutation($data: RegionInput!) {
                createRegion(input: $data)
                {
                    id
                    name
                    slug
                    short_slug
                    currency_id
                    is_default
                }
            }
        ', [
            'data' => $data,
        ])->assertJson([
            'data' => ['createRegion' => $data],
        ]);

        $response = $this->graphQL('
            query {
                regions {
                  data {
                    id
                    name
                  }
                }
            }
        ');

        $this->assertArrayHasKey('id', $response->json()['data']['regions']['data'][0]);
    }

    /**
     * testUpdateRegion.
     *
     * @return void
     */
    public function testUpdateRegion()
    {
        $data = [
            'name' => 'Test Region',
            'slug' => 'test-region',
            'short_slug' => 'test-region',
            'is_default' => 1,
            'currency_id' => 1,
        ];
        $this->graphQL('
            mutation($data: RegionInput!) {
                createRegion(input: $data)
                {
                    id
                    name
                    slug
                    short_slug
                    currency_id
                    is_default
                }
            }
        ', [
            'data' => $data,
        ])->assertJson([
            'data' => ['createRegion' => $data],
        ]);

        $response = $this->graphQL('
            query getMutation {
                regions {
                  data {
                    name,
                    id
                  }
                }
            }
        ');
        $response = $response->decodeResponseJson();
        $data = [
            'name' => 'Test Region 2',
            'slug' => 'test-region-2',
            'short_slug' => 'test-region-2',
            'is_default' => 1,
            'currency_id' => 1,
        ];
        $this->graphQL('
            mutation($data: RegionInputUpdate! $id: ID!) {
                updateRegion(input: $data id: $id)
                {
                    id
                    name
                    slug
                    short_slug
                    currency_id
                    is_default
                }
            }
        ', [
            'data' => $data,
            'id' => $response['data']['regions']['data'][0]['id'],
        ])->assertJson([
            'data' => ['updateRegion' => $data],
        ]);
    }

    /**
     * testDeleteRegion.
     *
     * @return void
     */
    public function testDeleteRegion()
    {
        $data = [
            'name' => 'Test Region',
            'slug' => 'test-region',
            'short_slug' => 'test-region',
            'is_default' => 1,
            'currency_id' => 1,
        ];

        $response = $this->graphQL('
            mutation($data: RegionInput!) {
                createRegion(input: $data)
                {
                    id
                    name
                    slug
                    short_slug
                    currency_id
                    is_default
                }
            }
        ', [
            'data' => $data,
        ])->assertJson([
            'data' => ['createRegion' => $data],
        ]);
        
        $response = $this->graphQL('
            mutation($data: RegionInput!) {
                createRegion(input: $data)
                {
                    id
                    name
                    slug
                    short_slug
                    currency_id
                    is_default
                }
            }
        ', [
            'data' => $data,
        ])->assertJson([
            'data' => ['createRegion' => $data],
        ]);

        $response = $response->json();

        $this->graphQL('
            mutation($id: ID!) {
                deleteRegion(id: $id)
            }
        ', [
            'id' => $response['data']['createRegion']['id'],
        ])->assertJson([
            'data' => ['deleteRegion' => true],
        ]);
    }
}
