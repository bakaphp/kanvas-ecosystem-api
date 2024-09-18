<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Apps\Models\Apps;
use Kanvas\Currencies\Models\Currencies;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Inventory\Variants\Models\Variants;
use Spatie\LaravelData\Data;

class OrderItem extends Data
{
    public function __construct(
        public readonly Apps $app,
        public readonly Variants $variant,
        public readonly string $name,
        public readonly string $sku,
        public readonly int $quantity,
        public readonly float $price,
        public readonly float $tax,
        public readonly float $discount,
        public readonly Currencies $currency,
        public readonly int $quantityShipped = 0,
    ) {
    }

    public static function viaRequest(AppInterface $app, CompanyInterface $company, Regions $region, array $request): self
    {
        $variant = Variants::getByIdFromCompanyApp($request['variant_id'], $company, $app);
        $warehouse = $region->warehouses()->firstOrFail(); //@todo get product warehouse with  stock
        $price = $variant->getPrice($warehouse);

        return new self(
            app: $app,
            variant: $variant,
            name: $variant->name,
            sku: $variant->sku,
            quantity: $request['quantity'],
            price: $price,
            tax: 0, //@todo get from region
            discount: 0,
            currency: $region->currency,
            quantityShipped: $request['quantity_shipped'] ?? 0
        );
    }

    public function getTotal(): float
    {
        return $this->price * $this->quantity;
    }

    public function getTotalDiscount(): float
    {
        return $this->discount * $this->quantity;
    }

    public function getTotalTax(): float
    {
        return $this->tax * $this->quantity;
    }
}
