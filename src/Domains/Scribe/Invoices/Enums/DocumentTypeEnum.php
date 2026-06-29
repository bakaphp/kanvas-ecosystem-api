<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Invoices\Enums;

/**
 * Discriminator on `accounting.invoices` — one table holds both invoices and credit notes (Round-4 #8).
 *
 *   - INVOICE: a debt the customer owes us (positive amount).
 *   - CREDIT_NOTE: a credit we owe the customer (positive amount; sign is carried by the discriminator).
 *
 * Credit notes require parent_invoice_id to be set.
 *
 * @see plan §7.3 — credit notes as discriminator column
 */
enum DocumentTypeEnum: string
{
    case INVOICE = 'invoice';
    case CREDIT_NOTE = 'credit_note';
}
