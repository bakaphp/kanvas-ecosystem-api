<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Souk\Orders\Models\OrderTypes;

class ResolveEventBookingOrderTypeAction
{
    public function __construct(
        protected readonly AppInterface $app,
        protected readonly CompanyInterface $company,
        protected readonly ?Model $resource,
    ) {
    }

    public function execute(): ?OrderTypes
    {
        $productTypeSlug = $this->productTypeSlug();

        if ($productTypeSlug === null) {
            return null;
        }

        return OrderTypes::query()
            ->fromApp($this->app)
            ->notDeleted()
            ->orderByRaw(
                'CASE WHEN companies_id = ? THEN 0 WHEN companies_id = 0 THEN 1 ELSE 2 END',
                [$this->company->getId()]
            )
            ->get()
            ->first(fn (OrderTypes $orderType): bool => $orderType->isEventBookingFor($productTypeSlug));
    }

    private function productTypeSlug(): ?string
    {
        $product = $this->resource instanceof Variants ? $this->resource->product : $this->resource;

        return $product instanceof Products ? $product->productsType?->slug : null;
    }
}
