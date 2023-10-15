<?php

declare(strict_types=1);

namespace Tests\GraphQL\Inventory;

use Tests\TestCase;

class ProductDashboardTest extends TestCase
{
    /**
     * test get product.
     */
    public function testGetProduct(): void
    {
        $this->graphQL('
            query {
                productDashboard {
                    total_products
                    product_status
                }
            }')->assertOk();
    }
}
