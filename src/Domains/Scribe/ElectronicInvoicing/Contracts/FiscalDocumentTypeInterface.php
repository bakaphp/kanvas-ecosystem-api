<?php

declare(strict_types=1);

namespace Kanvas\Scribe\ElectronicInvoicing\Contracts;

/**
 * One kind of fiscal document a country's authority defines (DR E31, SV 01, MX I). Each regime's types are an
 * enum in its own folder: a type carries rules the mappers and validators branch on, so a new one ships with code,
 * never as a row.
 */
interface FiscalDocumentTypeInterface
{
    /**
     * The code the authority uses and `fiscal_documents.document_type` stores.
     */
    public function code(): string;

    public function label(): string;

    /**
     * What the number starts with (DR: E31); a number is the series and the sequence padded to sequenceDigits().
     */
    public function series(): string;

    public function sequenceDigits(): int;

    /**
     * A correction that must reference the document it modifies.
     */
    public function isNote(): bool;

    public function requiresBuyerTaxId(): bool;

    /**
     * Issued by the company for what it buys or pays, from a bill or an expense.
     */
    public function isPurchase(): bool;
}
