<?php

declare(strict_types=1);

namespace App\GraphQL\Inventory\Builders\Attributes;

use Illuminate\Database\Eloquent\Builder;
use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Attributes\Models\AttributesValues;

class AttributeValuesBuilder
{
    public function getAttributeValues(mixed $root, array $args): Builder
    {
        $app = app(Apps::class);

        return AttributesValues::query()
            ->whereHas(
                'attribute',
                fn (Builder $q) => $q->where('apps_id', $app->getId())
                    ->where('is_deleted', 0)
            );
    }
}
