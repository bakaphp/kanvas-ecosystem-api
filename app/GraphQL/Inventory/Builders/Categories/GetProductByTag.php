<?php

declare(strict_types=1);

namespace App\GraphQL\Inventory\Builders\Categories;

class GetProductByTag
{
    public function getProductsByTag(mixed $root, array $args): mixed
    {
        $tag = $args['tag'] ?? null;

        // Fetch products associated with the given tag
        return $root->products()
            ->whereHas('tags', function ($query) use ($tag) {
                $query->where('name', $tag);
            })
            ->inRandomOrder();
    }
}
