<?php

declare(strict_types=1);

namespace Tests\GraphQL\Guild;

use Tests\TestCase;

class LeadTest extends TestCase
{
    /**
     * test get product.
     */
    public function testGetProduct(): void
    {
        /*  $data = [
             'name' => fake()->name,
             'description' => fake()->text,
         ];

         $this->graphQL('
             mutation($data: ProductInput!) {
                 createProduct(input: $data)
                 {
                     name
                     description
                 }
             }', ['data' => $data])->assertJson([
             'data' => ['createProduct' => $data],
         ]);
 */
        $this->graphQL('
            query {
                leads {
                    data {
                        uuid
                        title
                    }
                }
            }')->assertJson([
            'data' => ['products' => ['data' => [$data]]],
        ]);
    }
}
