<?php

declare(strict_types=1);

namespace App\GraphQL\Inventory\Builders\Variants;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VariantBuilderPaginate
{
    public function getVariants(
        mixed $root,
        array $args
    ): HasMany {
        $includeUnpublished = (bool) ($args['includeUnpublished'] ?? false);

        return $root->variants()
            ->when($includeUnpublished !== true, function (Builder $query) {
                $query->where('is_published', true);
            });
    }
}
