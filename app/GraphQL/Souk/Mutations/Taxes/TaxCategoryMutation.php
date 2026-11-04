<?php

declare(strict_types=1);

namespace App\GraphQL\Souk\Mutations\Taxes;

use App\GraphQL\Concerns\ResolvesActingContext;
use Kanvas\Souk\Taxes\Actions\AssignTaxCategoryToProductsAction;
use Kanvas\Souk\Taxes\Actions\CreateTaxCategoryAction;
use Kanvas\Souk\Taxes\Actions\DeleteTaxCategoryAction;
use Kanvas\Souk\Taxes\Actions\UpdateTaxCategoryAction;
use Kanvas\Souk\Taxes\Models\TaxCategory;

class TaxCategoryMutation
{
    use ResolvesActingContext;

    public function create(mixed $rootValue, array $request): TaxCategory
    {
        $ctx = $this->actingContext();

        return new CreateTaxCategoryAction($ctx->app, $request['input'])->execute();
    }

    public function update(mixed $rootValue, array $request): TaxCategory
    {
        $ctx = $this->actingContext();

        /** @var TaxCategory $category */
        $category = TaxCategory::getById((int) $request['id'], $ctx->app);

        return new UpdateTaxCategoryAction($category, $request['input'])->execute();
    }

    public function delete(mixed $rootValue, array $request): bool
    {
        $ctx = $this->actingContext();

        /** @var TaxCategory $category */
        $category = TaxCategory::getById((int) $request['id'], $ctx->app);

        return new DeleteTaxCategoryAction($category)->execute();
    }

    public function assignToProducts(mixed $rootValue, array $request): int
    {
        $ctx = $this->actingContext();

        return new AssignTaxCategoryToProductsAction(
            app: $ctx->app,
            company: $ctx->company,
            productIds: $request['product_ids'],
            taxCategory: $request['tax_category'] ?? null,
        )->execute();
    }
}
