<?php

declare(strict_types=1);

namespace Kanvas\Scribe\ElectronicInvoicing\Contracts;

use Kanvas\Scribe\ElectronicInvoicing\DataTransferObject\EcfDocument;
use Kanvas\Scribe\ElectronicInvoicing\DataTransferObject\EcfSubmission;

/**
 * One implementation per e-CF provider (WebPOS, Alanube), constructed for one company. Scribe never names a
 * provider: EcfProviderResolver picks the implementation from the company's ECF_PROVIDER setting, and builds
 * it as `new $class(app:, company:, environment:)`, so every implementation takes those three named
 * arguments (environment null = the company's current setting).
 */
interface EcfProviderInterface
{
    /**
     * Exactly what submit() puts on the wire for this document. Stored as the attempt's request artifact
     * before the call is made, so the record of what was sent never depends on the call succeeding.
     *
     * @return array<string, mixed>
     */
    public function requestPayload(EcfDocument $document): array;

    /**
     * The answer carries the provider's raw response (rawResponse) for the attempt's response artifact.
     */
    public function submit(EcfDocument $document): EcfSubmission;

    public function fetchResult(EcfDocument $document): EcfSubmission;

    /**
     * Legal name registered at DGII, or null when the RNC/cédula is not found.
     */
    public function lookupTaxpayer(string $taxId): ?string;
}
