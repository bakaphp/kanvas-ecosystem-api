<?php

declare(strict_types=1);

namespace Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\DataTransferObject;

use Spatie\LaravelData\Data;

/**
 * How an E47 (payment to a foreign payee) was paid: the account and bank it left from, and the amount in
 * the payee's currency. The payee itself is the document's buyer, flagged foreign.
 */
class EcfForeignPayment extends Data
{
    public function __construct(
        public readonly ?string $accountType = null,
        public readonly ?string $accountNumber = null,
        public readonly ?string $bank = null,
        public readonly ?string $foreignCurrency = null,
        public readonly ?float $foreignAmount = null,
    ) {
    }
}
