<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\DataTransferObject;

/**
 * The resolved order filters of one report call. `null` means "no filter on this axis"; an empty
 * list means the filter resolved to nothing and the report must come back empty, never widened.
 */
final readonly class OrderReportScope
{
    /**
     * @param int[]|null $typeIds
     * @param int[]|null $orderIds the orders carrying any of the requested tags, read from the social pivot
     */
    public function __construct(
        public ?array $typeIds = null,
        public ?array $orderIds = null,
    ) {
    }

    public static function everything(): self
    {
        return new self();
    }
}
