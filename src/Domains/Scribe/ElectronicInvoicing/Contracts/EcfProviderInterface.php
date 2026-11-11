<?php

declare(strict_types=1);

namespace Kanvas\Scribe\ElectronicInvoicing\Contracts;

use Kanvas\Scribe\ElectronicInvoicing\DataTransferObject\EcfSubmission;

/**
 * One implementation per e-invoicing provider and country (WebPOS and Alanube for DR), constructed for one company.
 * Scribe never names a provider: EcfProviderResolver picks the implementation from the company's ECF_PROVIDER
 * setting, and builds it as `new $class(app:, company:, environment:)`, so every implementation takes those three
 * named arguments (environment null = the company's current setting).
 *
 * It receives the payload its country's regime built, and narrows it to that regime's class.
 */
interface EcfProviderInterface
{
    /**
     * The ISO country whose documents it maps; decides the company's regime.
     */
    public static function country(): string;

    /**
     * Exactly what submit() puts on the wire for this document. Stored as the attempt's request artifact
     * before the call is made, so the record of what was sent never depends on the call succeeding.
     *
     * @return array<string, mixed>
     */
    public function requestPayload(FiscalPayloadInterface $document): array;

    /**
     * The answer carries the provider's raw response (rawResponse) for the attempt's response artifact.
     */
    public function submit(FiscalPayloadInterface $document): EcfSubmission;

    public function fetchResult(FiscalPayloadInterface $document): EcfSubmission;

    /**
     * Legal name in the authority's taxpayer registry, or null when the id is not registered.
     */
    public function lookupTaxpayer(string $taxId): ?string;
}
