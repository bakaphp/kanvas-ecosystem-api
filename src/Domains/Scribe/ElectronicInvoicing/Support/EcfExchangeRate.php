<?php

declare(strict_types=1);

namespace Kanvas\Scribe\ElectronicInvoicing\Support;

use Illuminate\Support\Carbon;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\DrFiscalRegime;
use Kanvas\Scribe\Expenses\Models\Expense;
use Kanvas\Scribe\FxRates\Services\FxRateService;
use Kanvas\Scribe\Invoices\Models\Invoice;

/**
 * The DOP rate a foreign-currency e-CF declares to DGII (TipoCambio). The document stays in its own currency; DGII
 * records its peso value from this rate, so declaring 1 under-reports a USD invoice about sixty times.
 *
 * Frozen on the document when its e-NCF is assigned, so every later send (a retry, a resend) declares the same
 * rate: a person's stated rate wins, a note takes its parent's (it modifies the same peso value), otherwise the
 * Banco Central buy rate in force on the issue date.
 */
final class EcfExchangeRate
{
    public const string KEY = 'exchange_rate';

    public static function declared(Invoice|Bill|Expense $document): float
    {
        if (self::isPesos($document)) {
            return 1.0;
        }

        $frozen = EcfCompliance::draftSettings($document)[self::KEY] ?? null;

        // Documents issued before rates were frozen keep the rate they were sent with.
        return round(is_numeric($frozen) && (float) $frozen > 0 ? (float) $frozen : (float) $document->fx_rate_to_base, 4);
    }

    public static function freeze(Invoice|Bill|Expense $document, ?Invoice $parent = null): void
    {
        if (self::isPesos($document)) {
            return;
        }

        $stated = EcfCompliance::draftSettings($document)[self::KEY] ?? null;
        if (is_numeric($stated) && (float) $stated > 0) {
            return;
        }

        $parentRate = $parent !== null ? (EcfCompliance::draftSettings($parent)[self::KEY] ?? null) : null;
        if (is_numeric($parentRate) && (float) $parentRate > 0) {
            EcfCompliance::writeDraftSettings($document, [self::KEY => (float) $parentRate, 'exchange_rate_source' => 'parent']);

            return;
        }

        $currency = strtoupper((string) $document->currency);
        $date = Carbon::parse($document->issued_date ?? $document->bill_date ?? $document->expense_date ?? Carbon::today());
        $rate = new FxRateService()->rateOn($document->app, $currency, DrFiscalRegime::CURRENCY, $date);

        if ($rate === null) {
            throw new ValidationException(
                "There is no Banco Central {$currency}→DOP rate on file for {$date->toDateString()}, and an e-CF in "
                . "{$currency} has to declare it to DGII. Nothing was issued. Sync the rates (scribe:sync-fx-rates) "
                . 'or state the rate on the document.'
            );
        }

        EcfCompliance::writeDraftSettings($document, [
            self::KEY => (float) $rate->rate,
            'exchange_rate_date' => $rate->rate_date->toDateString(),
            'exchange_rate_source' => $rate->source,
        ]);
    }

    private static function isPesos(Invoice|Bill|Expense $document): bool
    {
        return strtoupper((string) $document->currency) === DrFiscalRegime::CURRENCY;
    }
}
