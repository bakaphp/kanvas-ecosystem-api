<?php
declare(strict_types=1);
namespace Tests\GraphQL\Inventory;

use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class RegionTest extends TestCase
{
    /**
     * testCreateRegion
     *
     * @return void
     */
    public function testCreateRegion()
    {
        $this->actingAs($this->createUser());
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
            'data' => $data
        ])->assertJson([
            'data' => ['createRegion' => $data]
        ]);
    }

    /**
     * testFindRegion
     *
     * @return void
     */
    public function testFindRegion()
    {
        $this->actingAs($this->createUser());
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
            'data' => $data
        ])->assertJson([
            'data' => ['createRegion' => $data]
        ]);

        $this->graphQL('
            query getMutation {
                regions {
                  data {
                    name
                  }
                }
            }
        ')->assertJson([
            'data' => ['regions' => ['data' => [['name' => 'Test Region']]]]
        ]);
    }
}
