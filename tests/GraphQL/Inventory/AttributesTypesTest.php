<?php

declare(strict_types=1);

namespace Tests\GraphQL\Inventory;

use Tests\TestCase;

class AttributesTypesTest extends TestCase
{
    /**
     * testSearch.
     *
     * @return void
     */
    public function testSearch(): void
    {
        $response = $this->graphQL('
            query {
                attributesTypes {
                    data {
                        name
                    }
                }
            }');
        $this->assertArrayHasKey('name', $response->json()['data']['attributesTypes']['data'][0]);
    }

}
