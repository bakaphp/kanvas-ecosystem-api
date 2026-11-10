<?php

declare(strict_types=1);

namespace Kanvas\Scribe\DocumentSequences\Enums;

use Kanvas\Scribe\ElectronicInvoicing\Contracts\FiscalDocumentTypeInterface;
use LogicException;

enum DocumentTypeEnum: string
{
    case INVOICE = 'invoice';
    case CREDIT_NOTE = 'credit_note';
    case DEBIT_NOTE = 'debit_note';
    case QUOTE = 'quote';
    case BILL = 'bill';
    case VENDOR_CREDIT = 'vendor_credit';
    case SALES_RECEIPT = 'sales_receipt';
    case EXPENSE = 'expense';
    case JOURNAL_ENTRY = 'journal_entry';

    // One sequence per fiscal document type, keyed by the authority's code: each has its own range and expiry. A
    // company issues for one country, so codes need not be unique across countries. A new country's types add
    // their cases here beside its type enum.
    case ECF_31 = 'ecf_31';
    case ECF_32 = 'ecf_32';
    case ECF_33 = 'ecf_33';
    case ECF_34 = 'ecf_34';
    case ECF_41 = 'ecf_41';
    case ECF_43 = 'ecf_43';
    case ECF_44 = 'ecf_44';
    case ECF_45 = 'ecf_45';
    case ECF_46 = 'ecf_46';
    case ECF_47 = 'ecf_47';

    public static function forFiscal(FiscalDocumentTypeInterface $type): self
    {
        return self::tryFrom('ecf_' . strtolower($type->code()))
            ?? throw new LogicException("No document sequence case for fiscal type {$type->code()}; add ecf_{$type->code()}.");
    }

    /**
     * The authority's document type code, for a fiscal sequence.
     */
    public function fiscalCode(): ?string
    {
        return str_starts_with($this->value, 'ecf_') ? substr($this->value, 4) : null;
    }

    /**
     * A fiscal sequence only exists once a range has been configured for it; inventing one at number 1 would
     * hand out numbers the authority never authorized.
     */
    public function requiresConfiguredRange(): bool
    {
        return $this->fiscalCode() !== null;
    }
}
