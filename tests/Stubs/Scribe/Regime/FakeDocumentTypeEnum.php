<?php

declare(strict_types=1);

namespace Tests\Stubs\Scribe\Regime;

use Kanvas\Scribe\ElectronicInvoicing\Contracts\FiscalDocumentTypeInterface;

/**
 * Codes reuse the sequence cases the DR already ships (DocumentTypeEnum ecf_31/33/34): a real second country adds
 * its own cases there, a test country should not.
 */
enum FakeDocumentTypeEnum: string implements FiscalDocumentTypeInterface
{
    case INVOICE = '31';
    case DEBIT_NOTE = '33';
    case CREDIT_NOTE = '34';

    public function code(): string
    {
        return $this->value;
    }

    public function label(): string
    {
        return ucfirst(strtolower(str_replace('_', ' ', $this->name)));
    }

    public function series(): string
    {
        return 'ZZ' . $this->value . '-';
    }

    public function sequenceDigits(): int
    {
        return 6;
    }

    public function isNote(): bool
    {
        return $this !== self::INVOICE;
    }

    public function requiresBuyerTaxId(): bool
    {
        return false;
    }

    public function isPurchase(): bool
    {
        return false;
    }
}
