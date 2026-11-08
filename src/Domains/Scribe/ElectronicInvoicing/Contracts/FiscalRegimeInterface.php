<?php

declare(strict_types=1);

namespace Kanvas\Scribe\ElectronicInvoicing\Contracts;

use Kanvas\Scribe\FxRates\DataTransferObject\OfficialRateSource;

/**
 * A country's electronic-invoicing rules. Country-neutral code asks the regime of a row's `country` instead of
 * naming one country's enum.
 */
interface FiscalRegimeInterface
{
    /**
     * ISO 3166-1 alpha-2, as stored in `fiscal_documents.country`.
     */
    public function country(): string;

    /**
     * @return list<FiscalDocumentTypeInterface>
     */
    public function documentTypes(): array;

    public function documentType(string $code): ?FiscalDocumentTypeInterface;

    /**
     * ISO 4217 code the authority keeps its books in (DOP, MXN, COP; USD where the dollar is legal tender).
     */
    public function currency(): string;

    /**
     * The central bank and rate a foreign-currency document declares; null where the local currency is the dollar
     * and nothing needs converting.
     */
    public function officialRateSource(): ?OfficialRateSource;
}
