<?php

declare(strict_types=1);

namespace Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr;

use Illuminate\Support\Carbon;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\DataTransferObject\EcfDocument;

/**
 * What DGII would reject, caught before an e-NCF is spent on it. Runs on the document built without a
 * number, so a failure leaves the invoice a draft with nothing burned.
 */
class DrEcfValidator
{
    public const int MAX_LINES = 100;

    // E32 at or above this (DOP) must identify the buyer like any other type.
    public const float CONSUMER_ANONYMOUS_LIMIT_DOP = 250000.0;

    private const float TOLERANCE = 0.01;

    /**
     * @return list<string>
     */
    public function errors(EcfDocument $document): array
    {
        return [
            ...$this->buyerErrors($document),
            ...$this->lineErrors($document),
            ...$this->totalErrors($document),
            ...$this->sequenceErrors($document),
            ...$this->referenceErrors($document),
        ];
    }

    public function assertValid(EcfDocument $document): void
    {
        $errors = $this->errors($document);

        if ($errors !== []) {
            throw new ValidationException(
                "This document cannot be issued as an {$document->type->series()} e-CF: " . implode(' ', $errors)
            );
        }
    }

    /**
     * @return list<string>
     */
    private function buyerErrors(EcfDocument $document): array
    {
        $errors = [];
        $taxId = $document->buyer->taxId;

        if ($taxId !== null && ! $document->buyer->isForeign && ! DrFiscalRegime::isTaxId($taxId)) {
            $errors[] = "The customer tax id '{$taxId}' must be an RNC of 9 digits or a cédula of 11.";
        }

        if ($taxId === null && $this->requiresBuyerTaxId($document)) {
            $errors[] = "{$document->type->series()} requires the customer's RNC or cédula, and the customer has none on file.";
        }

        if (trim($document->buyer->name) === '' && $document->type !== EcfTypeEnum::GASTOS_MENORES) {
            $errors[] = 'The customer has no name.';
        }

        if ($document->type === EcfTypeEnum::PAGOS_EXTERIOR && ! $document->buyer->isForeign) {
            $errors[] = 'E47 is only for payments to a foreign payee.';
        }

        return $errors;
    }

    private function requiresBuyerTaxId(EcfDocument $document): bool
    {
        if ($document->type !== EcfTypeEnum::CONSUMO) {
            return $document->type->requiresBuyerTaxId();
        }

        return $document->totals->total * $document->exchangeRate >= self::CONSUMER_ANONYMOUS_LIMIT_DOP;
    }

    /**
     * @return list<string>
     */
    private function lineErrors(EcfDocument $document): array
    {
        $count = count($document->lines);

        if ($count === 0) {
            return ['It has no lines.'];
        }

        $errors = $count > self::MAX_LINES
            ? ["It has {$count} lines; an e-CF carries at most " . self::MAX_LINES . '.']
            : [];

        foreach ($document->lines as $line) {
            if ($line->quantity <= 0) {
                $errors[] = "Line {$line->lineNumber} has a quantity of {$line->quantity}; it must be greater than zero.";
            }
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    private function totalErrors(EcfDocument $document): array
    {
        $totals = $document->totals;
        $tolerance = self::TOLERANCE * max(1, count($document->lines));
        $errors = [];

        $lineTax = array_sum(array_map(fn ($line): float => $line->tax, $document->lines));
        $lineNet = array_sum(array_map(fn ($line): float => $line->subtotal, $document->lines));

        if (abs($totals->exempt + $totals->taxed + $totals->itbis - $totals->total) > $tolerance) {
            $errors[] = "Totals do not add up: exempt {$totals->exempt} + taxed {$totals->taxed} + ITBIS {$totals->itbis} is not {$totals->total}.";
        }

        if (abs($lineTax - $totals->itbis) > $tolerance) {
            $errors[] = "Line ITBIS adds to {$lineTax} but the document books {$totals->itbis}.";
        }

        if (abs($lineNet - ($totals->exempt + $totals->taxed)) > $tolerance) {
            $errors[] = "Line subtotals add to {$lineNet}, not the document's {$totals->exempt} + {$totals->taxed}.";
        }

        $positiveRateNet = array_sum(array_map(fn ($line): float => $line->itbis->percent() > 0 ? $line->subtotal : 0.0, $document->lines));
        if ($positiveRateNet > 0 && $totals->itbis <= 0) {
            $errors[] = 'Taxed lines carry no ITBIS.';
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    private function sequenceErrors(EcfDocument $document): array
    {
        if ($document->sequenceExpiresAt === '') {
            return [];
        }

        return Carbon::parse($document->sequenceExpiresAt)->endOfDay()->lt(Carbon::today())
            ? ["The {$document->type->series()} number range expired on {$document->sequenceExpiresAt}; configure a new range."]
            : [];
    }

    /**
     * @return list<string>
     */
    private function referenceErrors(EcfDocument $document): array
    {
        if (! $document->type->isNote()) {
            return [];
        }

        if ($document->reference === null || trim($document->reference->encf) === '') {
            return ["A {$document->type->series()} note must reference the e-CF it modifies; standalone notes cannot be issued as e-CF."];
        }

        return [];
    }
}
