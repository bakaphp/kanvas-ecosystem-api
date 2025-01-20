<?php

namespace Tests\Social\Integration;

use Kanvas\Inventory\Products\Models\Products;
use Tests\TestCase;

final class UserRatingTest extends TestCase
{
    public function testCreateUsersRating(): void
    {
        $product = Products::factory()->create();

        $response = auth()->user()->addRating($product, 5, 'Great product');
        $this->assertTrue($response);
    }
}
