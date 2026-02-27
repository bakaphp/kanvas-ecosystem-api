<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Actions;

use Kanvas\Companies\Models\Companies;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Inventory\Variants\Models\Variants;
use Spatie\LaravelData\DataCollection;

class ValidateProductCompaniesAction
{
    public function __construct(
        protected DataCollection $orderItemsDto
    ) {
    }

    /**
     * Validate that all product companies are active (not soft-deleted).
     *
     * @throws ValidationException if any product belongs to a soft-deleted company
     */
    public function execute(): void
    {
        // Extract variant IDs from order items DTO (convert DataCollection to Laravel Collection)
        $variantIds = collect($this->orderItemsDto->toArray())
            ->pluck('variant_id')
            ->filter()
            ->unique();

        if ($variantIds->isEmpty()) {
            return;
        }

        // Load variants with products and companies
        $variants = Variants::with('product.company')
            ->whereIn('id', $variantIds->toArray())
            ->get();

        // Extract unique company IDs
        $companyIds = $variants
            ->pluck('product.companies_id')
            ->filter()
            ->unique();

        // Check for soft-deleted companies
        $deletedCompanies = Companies::whereIn('id', $companyIds->toArray())
            ->where('is_deleted', true)
            ->get();

        if ($deletedCompanies->isNotEmpty()) {
            $companyNames = $deletedCompanies->pluck('name')->join(', ');

            throw new ValidationException(
                "Cannot create order: Products belong to inactive companies: {$companyNames}"
            );
        }
    }
}
