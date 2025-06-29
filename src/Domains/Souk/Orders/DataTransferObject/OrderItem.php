<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Apps\Models\Apps;
use Kanvas\Currencies\Models\Currencies;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Regions\Models\Regions;
use Kanvas\Souk\Enums\ConfigurationEnum;
use Spatie\LaravelData\Data;

class OrderItem extends Data
{
    public function __construct(
        public readonly Apps $app,
        public readonly Variants $variant,
        public readonly string|int|float $name,
        public readonly string $sku,
        public readonly int|float $quantity,
        public readonly float $price,
        public readonly float $tax,
        public readonly float $discount,
        public readonly Currencies $currency,
        public readonly int $quantityShipped = 0,
        public readonly ?array $metadata = null,
    ) {
    }

    public static function viaRequest(AppInterface $app, CompanyInterface $company, Regions $region, array $request): self
    {
        if ($app->get(ConfigurationEnum::B2B_GLOBAL_COMPANY->value)) {
            $variant = Variants::getById($request['variant_id'], $app);
        } else {
            $variant = Variants::getByIdFromCompanyApp($request['variant_id'], $company, $app);
        }

        $warehouse = $region->warehouses()->firstOrFail(); //@todo get product warehouse with  stock
        $price = (float) ($request['price'] ?? $variant->getPrice($warehouse, Channels::getDefault($company, $app)));

        return new self(
            app: $app,
            variant: $variant,
            name: $variant->name,
            sku: $variant->sku,
            quantity: $request['quantity'],
            price: $price,
            tax: 0, // @todo get from region
            discount: 0,
            currency: $region->currency,
            quantityShipped: $request['quantity_shipped'] ?? 0,
            metadata: $request['metadata'] ?? [],
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
