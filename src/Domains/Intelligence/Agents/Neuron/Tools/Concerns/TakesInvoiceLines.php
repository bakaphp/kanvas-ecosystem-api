<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Concerns;

use Baka\Support\Money;
use Baka\Support\Str;
use Kanvas\Scribe\Invoices\DataTransferObject\InvoiceLine as InvoiceLineData;
use NeuronAI\Tools\ArrayProperty;
use NeuronAI\Tools\ObjectProperty;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;

/**
 * The invoice lines an agent gives, one schema and one reading for every tool that takes them, so a
 * line that creates an invoice and a line that edits one are priced and taxed the same way.
 */
trait TakesInvoiceLines
{
    protected function invoiceLinesProperty(string $description, bool $required = false): ArrayProperty
    {
        return new ArrayProperty(
            name: 'lines',
            description: $description,
            required: $required,
            items: new ObjectProperty(
                name: 'line',
                description: 'One invoice line.',
                properties: [
                    new ToolProperty(
                        name: 'description',
                        type: PropertyType::STRING,
                        description: 'What is billed.',
                        required: true
                    ),
                    new ToolProperty(
                        name: 'quantity',
                        type: PropertyType::NUMBER,
                        description: 'Quantity. Defaults to 1.',
                        required: false
                    ),
                    new ToolProperty(
                        name: 'unit_price',
                        type: PropertyType::NUMBER,
                        description: 'Unit price before tax.',
                        required: true
                    ),
                    new ToolProperty(
                        name: 'tax_rate',
                        type: PropertyType::NUMBER,
                        description: 'Tax rate as a percent (18 for ITBIS, 0 when exempt). The tax is computed from it.',
                        required: false,
                    ),
                ],
            ),
        );
    }

    /**
     * On an e-CF company every line has to state its rate, 0 included, so ITBIS is never dropped by omission.
     *
     * @param array<int, array<string, mixed>> $lines
     * @param string $refusal closes every refusal, so the model reads what did not happen ("Nothing was created.")
     * @return list<InvoiceLineData>|string the lines, or why they were refused
     */
    protected function invoiceLinesFrom(
        array $lines,
        ?string $fallbackDescription,
        bool $issuesEcf,
        string $refusal
    ): array|string {
        $data = [];

        foreach ($lines as $line) {
            $quantity = max(0.01, (float) ($line['quantity'] ?? 1));
            $unitPrice = (float) ($line['unit_price'] ?? 0);
            $rate = isset($line['tax_rate']) && is_numeric($line['tax_rate']) ? (float) $line['tax_rate'] : null;

            if ($unitPrice <= 0) {
                return 'Every line needs a positive unit_price. ' . $refusal;
            }

            if ($rate === null && $issuesEcf) {
                return 'This company issues e-CF: every line needs tax_rate (18 for ITBIS, 0 when exempt). ' . $refusal;
            }

            $data[] = new InvoiceLineData(
                description: Str::trimToNull((string) ($line['description'] ?? '')) ?? $fallbackDescription,
                quantity: $quantity,
                unit_price_native: $unitPrice,
                tax_rate: $rate,
                tax_amount_native: $rate !== null ? Money::cents($quantity * $unitPrice * $rate / 100) : 0.0,
            );
        }

        return $data;
    }
}
