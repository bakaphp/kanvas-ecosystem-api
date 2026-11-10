<?php

declare(strict_types=1);

namespace Kanvas\Souk\Shipping\Restrictions;

use Baka\Support\Str;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Souk\Shipping\DataTransferObject\ShippingDestination;

class CheckShippingRestrictionsAction
{
    public const string ATTRIBUTE_SLUG = 'shipping-destinations';

    public function __construct(
        protected array $variants,
        protected ShippingDestination $destination,
    ) {
    }

    public function blockedProductNames(): array
    {
        $blocked = [];

        foreach ($this->variants as $variant) {
            $product = $variant->product;

            if ($product !== null && ! $this->allows($product)) {
                $blocked[$product->getId()] = (string) $product->name;
            }
        }

        return array_values($blocked);
    }

    public function assertAllowed(): void
    {
        $blocked = $this->blockedProductNames();

        if ($blocked !== []) {
            throw new ValidationException('These products cannot be shipped to the destination: ' . implode(', ', $blocked));
        }
    }

    private function allows(Products $product): bool
    {
        $allowed = $this->allowedDestinations($product);

        return $allowed === [] || in_array(true, array_map($this->matchesDestination(...), $allowed), true);
    }

    private function allowedDestinations(Products $product): array
    {
        $value = $product->getAttributeBySlug(self::ATTRIBUTE_SLUG)?->value;
        $tokens = is_array($value) ? $value : explode(',', (string) $value);

        return array_values(array_filter(array_map(
            fn (mixed $token): ?string => Str::trimToNull(strtoupper((string) $token)),
            $tokens
        )));
    }

    private function matchesDestination(string $token): bool
    {
        $country = strtoupper($this->destination->countryCode);
        $state = Str::trimToNull(strtoupper((string) $this->destination->state));

        return $token === $country || ($state !== null && $token === "{$country}-{$state}");
    }
}
