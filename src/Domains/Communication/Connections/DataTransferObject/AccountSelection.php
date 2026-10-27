<?php

declare(strict_types=1);

namespace Kanvas\Communication\Connections\DataTransferObject;

use Spatie\LaravelData\Data;

/**
 * One of the integrated account's resources the tenant chose to communicate through: a phone
 * number, a mailbox, a paired session. Each becomes one connection.
 */
class AccountSelection extends Data
{
    /**
     * @param string $key the identity the vendor knows the account by (an E.164 number, an address);
     *                    stable across saves, because it is what a connection is matched on
     * @param array<string, mixed> $attributes vendor ids needed to act on the account (a number's SID)
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly bool $isDefault = false,
        public readonly array $attributes = []
    ) {
    }
}
