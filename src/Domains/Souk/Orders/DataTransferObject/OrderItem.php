<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Apps\Models\Apps;
use Kanvas\Currencies\Models\Currencies;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
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
        public readonly ?int $channelId = null,
    ) {
    }

    public static function viaRequest(AppInterface $app, CompanyInterface $company, Regions $region, array $request): self
    {
        $allowCrossCompanyVariants = $app->get(ConfigurationEnum::ALLOW_CROSS_COMPANY_VARIANTS->value) ?? false;

        if ($allowCrossCompanyVariants || $app->get(ConfigurationEnum::B2B_GLOBAL_COMPANY->value)) {
            $variant = Variants::getById($request['variant_id'], $app);
        } else {
            $variant = Variants::getByIdFromCompanyApp($request['variant_id'], $company, $app);
        }

        // Warehouse resolution with fallback logic
        if ($allowCrossCompanyVariants) {
            $warehouse = null;

            // Strategy 1: Try to find provider's warehouse in order's region (preferred)
            $regionWarehouse = $region->warehouses()
                ->where('companies_id', $variant->product->companies_id)
                ->first();

            // Check if Strategy 1 warehouse has pricing configured
            if ($regionWarehouse) {
                $hasPricing = $variant->variantWarehouses()
                    ->where('warehouses_id', $regionWarehouse->id)
                    ->where('price', '>', 0)
                    ->exists();

                if ($hasPricing) {
                    $warehouse = $regionWarehouse;
                }
            }

            // Strategy 2: Fallback to product's default warehouse from its own company (region-aware)
            if (! $warehouse) {
                $variantWarehouse = $variant->variantWarehouses()
                    ->whereHas('warehouse', fn ($q) =>
                        $q->where('companies_id', $variant->product->companies_id)
                          ->where('is_deleted', 0)
                    )
                    ->where('price', '>', 0) // Only select warehouses with pricing
                    ->orderBy('is_default', 'desc')
                    ->first();

                if ($variantWarehouse) {
                    $warehouse = Warehouses::find($variantWarehouse->warehouses_id);
                }
            }

            // No warehouse found - throw clear error
            if (! $warehouse) {
                throw new ValidationException(
                    "No warehouse with pricing found for product '{$variant->name}' (SKU: {$variant->sku}) " .
                    "from company '{$variant->product->company->name}'. " .
                    "Provider must configure pricing in a warehouse in region '{$region->name}' or in their default warehouse."
                );
            }
        } else {
            $warehouse = $region->warehouses()->firstOrFail();
        }

        // Price resolution with multiple fallback strategies
        if (! isset($request['price'])) {
            $price = 0.0;

            // Strategy 1: Try provider's channel if using provider's warehouse
            if ($allowCrossCompanyVariants && $warehouse->companies_id !== $company->getId()) {
                $warehouseCompany = $variant->product->company;
                $channel = Channels::getDefault($warehouseCompany, $app);
                if ($channel) {
                    $price = (float) $variant->getPrice($warehouse, $channel);
                }
            }

            // Strategy 2: Try platform company's channel if no price yet
            if ($price == 0.0) {
                $channel = Channels::getDefault($company, $app);
                if ($channel) {
                    $price = (float) $variant->getPrice($warehouse, $channel);
                }
            }

            // Strategy 3: Try to get price directly from variant warehouse (no channel)
            if ($price == 0.0) {
                $variantWarehouse = $variant->variantWarehouses()
                    ->where('warehouses_id', $warehouse->id)
                    ->first();
                if ($variantWarehouse && $variantWarehouse->price > 0) {
                    $price = (float) $variantWarehouse->price;
                }
            }

            // No price found - throw detailed error
            if ($price == 0.0) {
                throw new ValidationException(
                    "No price found for product '{$variant->name}' (SKU: {$variant->sku}) " .
                    "in warehouse '{$warehouse->name}' (ID: {$warehouse->id}, Company: {$warehouse->companies_id}). " .
                    "Please configure pricing for this product in the warehouse or provide an explicit price."
                );
            }
        } else {
            $price = (float) $request['price'];
        }

        $channelId = $request['channel_id'] ?? $request['attributes']['channel_id'] ?? null;
        if ($channelId !== null) {
            $channel = $allowCrossCompanyVariants || $app->get(ConfigurationEnum::B2B_GLOBAL_COMPANY->value)
                        ? Channels::getById($channelId, $app)
                        : Channels::getByIdFromCompanyApp($channelId, $company, $app);
            $channelId = $channel->getId();
        }

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
            metadata: $request['attributes'] ?? $request['metadata'] ?? null,
            channelId: $channelId,
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
