<?php

declare(strict_types=1);

namespace Kanvas\Scribe\ElectronicInvoicing\Models\Concerns;

use Kanvas\Scribe\ElectronicInvoicing\Contracts\FiscalDocumentTypeInterface;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\FiscalRegimes;

/**
 * For a row that stores `country` + `document_type`: the code only means something within its country's regime.
 */
trait HasFiscalDocumentType
{
    public function fiscalDocumentType(): ?FiscalDocumentTypeInterface
    {
        return FiscalRegimes::find($this->country)?->documentType((string) $this->document_type);
    }

    public function documentTypeLabel(): ?string
    {
        return $this->fiscalDocumentType()?->label();
    }
}
