<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Invoices\Enums;

/**
 * Discriminator on `accounting.invoices` — one table holds both invoices and credit notes (Round-4 #8).
 *
 *   - INVOICE: a debt the customer owes us (positive amount).
 *   - CREDIT_NOTE: a credit we owe the customer (positive amount; sign is carried by the discriminator).
 *   - DEBIT_NOTE: more the customer owes on an issued invoice (DGII E33). A receivable like an invoice.
 *
 * Credit notes may be standalone; debit notes always carry parent_invoice_id.
 *
 * @see plan §7.3 — credit notes as discriminator column
 */
enum DocumentTypeEnum: string
{
    case INVOICE = 'invoice';
    case CREDIT_NOTE = 'credit_note';
    case DEBIT_NOTE = 'debit_note';

    /**
     * Documents the customer owes on: what AR aging, overdue lists and revenue read.
     *
     * @return list<self>
     */
    public static function receivables(): array
    {
        return [self::INVOICE, self::DEBIT_NOTE];
    }

    public function isNote(): bool
    {
        return $this !== self::INVOICE;
    }

    /**
     * source_type of the journal entry the document posts on issue.
     */
    public function journalSourceType(): string
    {
        return $this->value;
    }

    public function label(): string
    {
        return match ($this) {
            self::INVOICE => 'Invoice',
            self::CREDIT_NOTE => 'Credit Note',
            self::DEBIT_NOTE => 'Debit Note',
        };
    }
}
