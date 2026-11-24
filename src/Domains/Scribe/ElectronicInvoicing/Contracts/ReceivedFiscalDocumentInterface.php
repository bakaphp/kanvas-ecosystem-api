<?php

declare(strict_types=1);

namespace Kanvas\Scribe\ElectronicInvoicing\Contracts;

/**
 * An electronic invoice a vendor issued to the company, as its country's provider hands it over. Bills and the
 * received fiscal row read only this, so a second country's provider feeds AP without touching either; the
 * country's regime turns it into the row (`receivedAttributes()`).
 *
 * The vendor's tax id plus the number is unique at the authority, so it is the bill's idempotency key.
 */
interface ReceivedFiscalDocumentInterface
{
    public function country(): string;

    public function provider(): string;

    public function documentType(): FiscalDocumentTypeInterface;

    public function number(): string;

    public function vendorTaxId(): string;

    public function vendorName(): string;

    /**
     * Y-m-d.
     */
    public function issueDate(): string;

    public function dueDate(): ?string;

    public function currency(): string;

    public function exchangeRate(): float;

    /**
     * The document's totals as the authority states them, kept on the bill for audit.
     *
     * @return array<string, float>
     */
    public function totalsSummary(): array;

    /**
     * @return list<ReceivedFiscalLineInterface>
     */
    public function lines(): array;

    /**
     * The signed document itself, kept on the bill as the legal record; null when the provider sent data only.
     */
    public function rawXml(): ?string;

    public function externalId(): string;
}
