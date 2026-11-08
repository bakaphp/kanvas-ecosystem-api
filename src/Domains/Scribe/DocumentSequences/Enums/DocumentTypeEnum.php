<?php

declare(strict_types=1);

namespace Kanvas\Scribe\DocumentSequences\Enums;

use Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\EcfTypeEnum;

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

    // One sequence per DGII e-CF type: each type has its own authorized range and expiry.
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

    public static function forEcf(EcfTypeEnum $type): self
    {
        return self::from('ecf_' . $type->value);
    }

    public function ecfType(): ?EcfTypeEnum
    {
        return str_starts_with($this->value, 'ecf_')
            ? EcfTypeEnum::from(substr($this->value, 4))
            : null;
    }

    /**
     * An e-CF sequence only exists once a DGII range has been configured for it; inventing one at
     * number 1 would hand out e-NCFs DGII never authorized.
     */
    public function requiresConfiguredRange(): bool
    {
        return $this->ecfType() !== null;
    }
}
