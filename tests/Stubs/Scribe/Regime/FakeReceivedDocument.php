<?php

declare(strict_types=1);

namespace Tests\Stubs\Scribe\Regime;

use Kanvas\Scribe\ElectronicInvoicing\Contracts\FiscalDocumentTypeInterface;
use Kanvas\Scribe\ElectronicInvoicing\Contracts\ReceivedFiscalDocumentInterface;

final readonly class FakeReceivedDocument implements ReceivedFiscalDocumentInterface
{
    public function __construct(
        private string $number,
        private FakeReceivedLine $line,
    ) {
    }

    public function country(): string
    {
        return FakeFiscalRegime::COUNTRY;
    }

    public function provider(): string
    {
        return 'fake';
    }

    public function documentType(): FiscalDocumentTypeInterface
    {
        return FakeDocumentTypeEnum::INVOICE;
    }

    public function number(): string
    {
        return $this->number;
    }

    public function vendorTaxId(): string
    {
        return 'ZZ-VENDOR-7';
    }

    public function vendorName(): string
    {
        return 'Zeta Supplies';
    }

    public function issueDate(): string
    {
        return '2026-06-15';
    }

    public function dueDate(): ?string
    {
        return null;
    }

    public function currency(): string
    {
        return 'USD';
    }

    public function exchangeRate(): float
    {
        return 1.0;
    }

    public function totalsSummary(): array
    {
        return ['total' => $this->line->unitPrice() + $this->line->taxAmount()];
    }

    public function lines(): array
    {
        return [$this->line];
    }

    public function rawXml(): ?string
    {
        return null;
    }

    public function externalId(): string
    {
        return $this->vendorTaxId() . '-' . $this->number;
    }
}
