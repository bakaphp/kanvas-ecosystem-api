<?php

declare(strict_types=1);

namespace Kanvas\Scribe\ElectronicInvoicing\Contracts;

interface ReceivedFiscalLineInterface
{
    public function lineNumber(): int;

    public function description(): string;

    public function quantity(): float;

    public function unitPrice(): float;

    public function discountAmount(): float;

    /**
     * As a fraction: 0.18 for an 18% tax.
     */
    public function taxRate(): float;

    public function taxAmount(): float;

    /**
     * The country's own tax code for the line (DR: `do_itbis`), kept on the bill line.
     *
     * @return array<string, mixed>
     */
    public function taxMetadata(): array;

    public function code(): ?string;
}
