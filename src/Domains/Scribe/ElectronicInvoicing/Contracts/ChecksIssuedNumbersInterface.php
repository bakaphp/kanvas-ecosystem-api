<?php

declare(strict_types=1);

namespace Kanvas\Scribe\ElectronicInvoicing\Contracts;

use Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\EcfTypeEnum;

/**
 * A provider that can say whether an e-NCF was already issued through it, by anyone: the range DGII authorized
 * may have been partly used outside Kanvas (a manual test, another POS), and issuing a used number loses it.
 */
interface ChecksIssuedNumbersInterface
{
    public function isNumberIssued(EcfTypeEnum $type, string $encf): bool;
}
