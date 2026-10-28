<?php

declare(strict_types=1);

namespace App\GraphQL\Souk\Queries\Taxes;

use App\GraphQL\Concerns\ResolvesActingContext;
use Kanvas\Souk\Taxes\Models\TaxRoutingRule;

class TaxCategoriesQuery
{
    use ResolvesActingContext;

    public function resolve(mixed $rootValue, array $request): array
    {
        $ctx = $this->actingContext();

        return TaxRoutingRule::query()
            ->fromApp($ctx->app)
            ->notDeleted()
            ->where('is_active', true)
            ->whereNotNull('category_codes')
            ->get()
            ->flatMap(fn (TaxRoutingRule $rule) => (array) $rule->category_codes)
            ->unique()
            ->values()
            ->toArray();
    }
}
