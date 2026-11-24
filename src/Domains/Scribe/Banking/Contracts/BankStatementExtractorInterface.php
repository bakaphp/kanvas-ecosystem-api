<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Banking\Contracts;

use Kanvas\Scribe\Banking\DataTransferObject\ParsedBankStatement;

/**
 * Reads a PDF (or a photo) of a bank statement into rows and header balances. Whatever it returns still
 * goes through the balance check, which is what catches a misread.
 */
interface BankStatementExtractorInterface
{
    public function extract(string $bytes): ParsedBankStatement;
}
