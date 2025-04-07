<?php

declare(strict_types=1);

namespace Tests\GraphQL\Inventory;

use Baka\Support\Str;
use Tests\GraphQL\Inventory\Traits\InventoryCases;
use Tests\TestCase;

class RegionTest extends TestCase
{
    use InventoryCases;
    /**
     * testCreateRegion.
     *
     * @return void
     */
    public function testCreateRegion()
    {
        $regionResponse = $this->createRegion();
        $this->assertArrayHasKey('id', $regionResponse['data']['createRegion']);
        $regionResponse = $regionResponse->json()['data']['createRegion'];
    }

    /**
     * testFindRegion.
     *
     * @return void
     */
    public function testFindRegion()
    {
        $regionResponse = $this->createRegion();
        $this->assertArrayHasKey('id', $regionResponse['data']['createRegion']);

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
        $regionResponse = $this->createRegion();
        $this->assertArrayHasKey('id', $regionResponse['data']['createRegion']);
        $regionResponse = $regionResponse->json()['data']['createRegion'];

        $data = [
            'name' => fake()->name . '2',
            'slug' => Str::slug(fake()->name),
            'short_slug' =>  Str::slug(fake()->name),
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
            'id' => $regionResponse['id'],
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
            'name' => 'delete test',
            'slug' => 'delete-test',
            'short_slug' => 'delete-test',
            'is_default' => 0,
            'currency_id' => 1,
        ];

        $regionResponse = $this->createRegion(
            data: $data
        );
        $this->assertArrayHasKey('id', $regionResponse['data']['createRegion']);
        $regionResponse = $regionResponse->json()['data']['createRegion'];

        $this->graphQL('
            mutation($id: ID!) {
                deleteRegion(id: $id)
            }
        ', [
            'id' => $regionResponse['id'],
        ])->assertJson([
            'data' => ['deleteRegion' => true],
        ]);
    }
}
