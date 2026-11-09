<?php

declare(strict_types=1);

namespace Kanvas\Scribe\ElectronicInvoicing\Services;

use Kanvas\Exceptions\ValidationException;
use Kanvas\Scribe\ElectronicInvoicing\Contracts\FiscalPayloadInterface;
use Kanvas\Scribe\ElectronicInvoicing\Models\FiscalDocument;

class FiscalPayloadService
{
    /**
     * Exactly the row's number and type, by the row's own regime, never the entity's current row, which may be
     * another one. The systemRef defaults to the row's latest attempt (what a status query must ask about); a new
     * send passes its own attempt's uuid. A row never sent falls back to its own uuid.
     */
    public static function forFiscal(FiscalDocument $fiscal, ?string $systemRef = null): FiscalPayloadInterface
    {
        $entity = $fiscal->scribeDocument()
            ?? throw new ValidationException("Fiscal document {$fiscal->uuid} has no Scribe document behind it.");

        $regime = $fiscal->regime();
        $type = $regime->documentType((string) $fiscal->document_type)
            ?? throw new ValidationException("Fiscal document {$fiscal->uuid} has a type its regime does not know ({$fiscal->document_type}).");

        return $regime->buildPayload(
            $entity,
            $type,
            (string) $fiscal->number,
            (string) ($fiscal->regimeValue('expires_at') ?? ''),
            $systemRef ?? $fiscal->latestSubmission?->uuid ?? $fiscal->uuid,
        );
    }
}
