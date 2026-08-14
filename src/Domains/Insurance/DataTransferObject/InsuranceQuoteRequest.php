<?php

declare(strict_types=1);

namespace Kanvas\Insurance\DataTransferObject;

use Kanvas\Inventory\Variants\Models\Variants;

/**
 * A quote is a price, not an order — comparing insurers must not leave a trail of
 * half-finished orders behind. Nothing here is persisted; the Order is created at
 * contract time carrying the chosen provider + quoteNumber.
 *
 * `payload` is the insurer-shaped body. Mapping a Kanvas vehicle into it is still
 * the caller's job; `vehicle` is carried so an adapter can take that over once the
 * vehicle custom fields for insurance exist.
 */
class InsuranceQuoteRequest
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public readonly string $product,
        public readonly array $payload = [],
        public readonly ?Variants $vehicle = null,
    ) {
    }
}
