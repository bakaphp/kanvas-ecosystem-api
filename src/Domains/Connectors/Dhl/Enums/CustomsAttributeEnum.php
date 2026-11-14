<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Dhl\Enums;

use Baka\Support\Str;
use Kanvas\Inventory\Variants\Models\Variants;

enum CustomsAttributeEnum: string
{
    case DESCRIPTION = 'customs-description';
    case MANUFACTURER_COUNTRY = 'manufacturer-country';
    case HS_CODE = 'hs-code';

    public const string WEIGHT = 'weight';

    public function isRequired(): bool
    {
        return $this !== self::HS_CODE;
    }

    public function valueFor(Variants $variant): ?string
    {
        $value = $variant->product?->getAttributeBySlug($this->value)?->value;

        return is_scalar($value) ? Str::trimToNull((string) $value) : null;
    }

    public static function missingFor(Variants $variant): array
    {
        $missing = array_map(
            fn (self $case): string => $case->value,
            array_filter(self::cases(), fn (self $case): bool => $case->isRequired() && $case->valueFor($variant) === null)
        );

        if ((float) $variant->weight <= 0) {
            $missing[] = self::WEIGHT;
        }

        return array_values($missing);
    }

    public static function missingByProduct(iterable $variants): array
    {
        $missing = [];

        foreach ($variants as $variant) {
            $fields = self::missingFor($variant);

            if ($fields !== []) {
                $missing[(string) $variant->product?->name] = $fields;
            }
        }

        return $missing;
    }
}
