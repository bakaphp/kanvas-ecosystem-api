<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Contracts;

use BackedEnum;

/**
 * A provider's delivery status, as each connector spells it. The message body and the live
 * `message.delivery.updated` push only need to know whose status it is and whether it is the
 * final word on delivery.
 */
interface DeliveryStatus extends BackedEnum
{
    public function provider(): string;

    /** Whether it reached the recipient or definitively did not — what a client is told live. */
    public function isDeliveryOutcome(): bool;
}
