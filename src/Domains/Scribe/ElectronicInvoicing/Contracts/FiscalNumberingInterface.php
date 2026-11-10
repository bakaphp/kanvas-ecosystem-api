<?php

declare(strict_types=1);

namespace Kanvas\Scribe\ElectronicInvoicing\Contracts;

use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\ElectronicInvoicing\DataTransferObject\FiscalNumberSlot;
use Kanvas\Scribe\Expenses\Models\Expense;
use Kanvas\Scribe\Invoices\Models\Invoice;

/**
 * How a country numbers its fiscal documents (DR and Colombia: ranges the authority authorized; El Salvador: a
 * control number the issuer generates; Mexico: none until the authority stamps it). The regime picks one.
 *
 * Two steps, both inside the issue transaction: reserve() checks a number can be taken and returns what the
 * document must carry before it has one (a range expiry); take() spends the number only after the numberless
 * document validated.
 */
interface FiscalNumberingInterface
{
    /**
     * Throws when the document's company could not number this type at all, so a draft is refused before anyone
     * approves it.
     */
    public function assertAvailable(Invoice|Bill|Expense $document, FiscalDocumentTypeInterface $type): void;

    public function reserve(Invoice|Bill|Expense $document, FiscalDocumentTypeInterface $type): FiscalNumberSlot;

    public function take(Invoice|Bill|Expense $document, FiscalNumberSlot $slot): FiscalNumberSlot;
}
