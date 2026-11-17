<?php

declare(strict_types=1);

namespace Kanvas\Event\Passes\DataTransferObject;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Variants\Models\Variants;

final readonly class EventAccess
{
    public const string ATTRIBUTE_NAME = 'event_access';

    public function __construct(
        public int $eventId,
    ) {
    }

    public static function forResource(?Model $resource): ?self
    {
        $attribute = match (true) {
            $resource instanceof Variants => $resource->getAttributeByName(self::ATTRIBUTE_NAME)
                ?? $resource->product?->getAttributeByName(self::ATTRIBUTE_NAME),
            $resource instanceof Products => $resource->getAttributeByName(self::ATTRIBUTE_NAME),
            default => null,
        };

        if ($attribute === null) {
            return null;
        }

        $config = is_string($attribute->value) ? json_decode($attribute->value, true) : $attribute->value;

        return new self(is_array($config) ? (int) ($config['event_id'] ?? 0) : 0);
    }
}
