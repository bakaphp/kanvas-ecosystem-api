<?php

declare(strict_types=1);

namespace Baka\Contracts;

use Baka\Users\Contracts\UserInterface;

/**
 * A model that can be paid against via Souk.Payments (polymorphic payable morph).
 *
 * Today implemented by Souk\Orders\Models\Order (via Souk\Traits\PayableTrait's
 * defaults). Tomorrow implemented by Scribe\Invoices\Models\Invoice and
 * Scribe\Bills\Models\Bill, which override the *Native methods to read their
 * own dual-currency columns.
 */
interface PayableInterface
{
    public function getPayableTotalNative(): float;

    public function getPayableBalanceDueNative(): float;

    public function getPayableCurrency(): string;

    public function isPaid(): bool;

    public function markAsPaid(UserInterface $user): void;
}
