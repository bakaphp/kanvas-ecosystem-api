<?php

declare(strict_types=1);

namespace Kanvas\Souk\Taxes\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Souk\Taxes\Models\TaxCategory;

class AssignTaxCategoryToProductsAction
{
    public const int MAX_PRODUCTS = 1000;

    public function __construct(
        private readonly AppInterface $app,
        private readonly CompanyInterface $company,
        private readonly array $productIds,
        private readonly ?string $taxCategory,
    ) {
    }

    public function execute(): int
    {
        $productIds = array_values(array_unique(array_map('intval', $this->productIds)));

        if ($productIds === [] || count($productIds) > self::MAX_PRODUCTS) {
            throw new ValidationException('Select between 1 and ' . self::MAX_PRODUCTS . ' products');
        }

        TaxCategory::assertCodesExist($this->app, $this->taxCategory);

        $products = Products::query()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->notDeleted()
            ->whereIn('id', $productIds)
            ->get();

        foreach ($products as $product) {
            $product->disableWorkflows();
            $product->tax_category = $this->taxCategory;
            $product->saveOrFail();
        }

        return $products->count();
    }
}
