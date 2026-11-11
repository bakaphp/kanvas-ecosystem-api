<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Reynolds\DataTransferObject\Crm;

use Spatie\LaravelData\Data;

/**
 * Whether the customer opted out of each channel. False covers "has not opted out" and "the CRM
 * never recorded an answer": neither silences anyone.
 */
class CrmOptOut extends Data
{
    public function __construct(
        public readonly bool $email = false,
        public readonly bool $phone = false
    ) {
    }
}
