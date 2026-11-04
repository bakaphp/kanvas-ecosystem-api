<?php

declare(strict_types=1);

namespace Kanvas\Souk\Taxes\Actions;

use Kanvas\Exceptions\ValidationException;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Souk\Taxes\Models\TaxCategory;
use Kanvas\Souk\Taxes\Models\TaxRoutingRule;

class DeleteTaxCategoryAction
{
    public function __construct(
        private readonly TaxCategory $category,
    ) {
    }

    public function execute(): bool
    {
        if ($this->isInUse()) {
            throw new ValidationException("Tax category {$this->category->code} is still assigned to tax rates or products");
        }

        $this->category->delete();

        return true;
    }

    private function isInUse(): bool
    {
        $appId = $this->category->apps_id;
        $code = $this->category->code;

        return TaxRoutingRule::query()
            ->where('apps_id', $appId)
            ->notDeleted()
            ->whereJsonContains('category_codes', $code)
            ->exists()
            || Products::query()
                ->where('apps_id', $appId)
                ->notDeleted()
                ->where('tax_category', $code)
                ->exists()
            || Variants::query()
                ->where('apps_id', $appId)
                ->notDeleted()
                ->where('tax_category', $code)
                ->exists();
    }
}
