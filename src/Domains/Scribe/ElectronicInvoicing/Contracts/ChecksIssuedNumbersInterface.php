<?php

declare(strict_types=1);

namespace Kanvas\Scribe\ElectronicInvoicing\Contracts;

/**
 * A provider that can say whether a fiscal number was already issued through it, by anyone: the range the authority authorized
 * may have been partly used outside Kanvas (a manual test, another POS), and issuing a used number loses it.
 */
interface ChecksIssuedNumbersInterface
{
    public function isNumberIssued(FiscalDocumentTypeInterface $type, string $number): bool;
}
