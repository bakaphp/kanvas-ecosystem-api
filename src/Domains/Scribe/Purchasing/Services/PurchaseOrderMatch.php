<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Purchasing\Services;

/**
 * The result of matching a vendor bill with a purchase order and what was received against it.
 */
final readonly class PurchaseOrderMatch
{
    /**
     * @param list<array{key: string, message: string, order_value: string|null, received_value: string|null, billed_value: string|null}> $discrepancies
     */
    public function __construct(
        public array $discrepancies,
    ) {
    }

    public function matched(): bool
    {
        return $this->discrepancies === [];
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_column($this->discrepancies, 'key');
    }

    /**
     * @return array{matched: bool, discrepancies: list<array{key: string, message: string, order_value: string|null, received_value: string|null, billed_value: string|null}>}
     */
    public function toArray(): array
    {
        return ['matched' => $this->matched(), 'discrepancies' => $this->discrepancies];
    }
}
