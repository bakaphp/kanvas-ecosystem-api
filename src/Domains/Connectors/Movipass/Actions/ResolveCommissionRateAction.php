<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Actions;

use Kanvas\Souk\Enums\ConfigurationEnum;
use Kanvas\Souk\Orders\Enums\CommissionEnum;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Souk\Orders\Models\Order;

class ResolveCommissionRateAction
{
    public function __construct(
        protected readonly Order $order,
    ) {
    }

    /**
     * @return array{rate: float, source: string}
     */
    public function execute(): array
    {
        $result = $this->resolveFromVariantAttribute()
            ?? $this->resolveFromProductAttribute()
            ?? $this->resolveFromCompanyConfig()
            ?? ['rate' => 0.0, 'source' => 'default'];

        if ($result['rate'] < 0 || $result['rate'] > 100) {
            throw new ValidationException(
                "Commission rate {$result['rate']} is out of valid range (0–100)."
            );
        }

        return $result;
    }

    private function getFirstVariant(): ?Variants
    {
        return $this->order->items()->first()?->variant;
    }

    /**
     * @return array{rate: float, source: string}|null
     */
    private function resolveFromVariantAttribute(): ?array
    {
        $variant = $this->getFirstVariant();

        if ($variant === null) {
            return null;
        }

        $attribute = $variant->getAttributeBySlug(CommissionEnum::COMMISSION_RATE->value);

        if ($attribute === null || $attribute->value === null) {
            return null;
        }

        return ['rate' => (float) $attribute->value, 'source' => 'variant'];
    }

    /**
     * @return array{rate: float, source: string}|null
     */
    private function resolveFromProductAttribute(): ?array
    {
        $variant = $this->getFirstVariant();

        if ($variant === null) {
            return null;
        }

        $attribute = $variant->product?->getAttributeBySlug(CommissionEnum::COMMISSION_RATE->value);

        if ($attribute === null || $attribute->value === null) {
            return null;
        }

        return ['rate' => (float) $attribute->value, 'source' => 'product'];
    }

    /**
     * @return array{rate: float, source: string}|null
     */
    private function resolveFromCompanyConfig(): ?array
    {
        $value = $this->order->company->get(ConfigurationEnum::DEFAULT_COMMISSION_RATE->value);

        if ($value === null || $value === '') {
            return null;
        }

        return ['rate' => (float) $value, 'source' => 'company_config'];
    }
}
