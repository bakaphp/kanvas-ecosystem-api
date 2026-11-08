<?php

declare(strict_types=1);

namespace Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr;

use Kanvas\Scribe\ElectronicInvoicing\Support\BuyerTaxId;
use Kanvas\Scribe\ElectronicInvoicing\Support\EcfCompliance;
use Kanvas\Scribe\Invoices\Models\Invoice;

/**
 * Which DGII document an AR document becomes. A credit note is always E34, a debit note E33; an invoice to a buyer with an
 * RNC or cédula is E31 so the buyer can take the ITBIS credit, anyone else gets E32. Whether the id is Dominican is
 * BuyerTaxId's answer, never the digit count, and DrFiscalRegime::assertMayIssue() asks DGII before an E31 uses a number.
 *
 * E44/E45/E46 are never inferred — a special-regime, government or export sale is a fact about the deal,
 * so the draft states it in `regional_compliance.do_ecf.type` and that wins here.
 */
class EcfTypeResolver
{
    private const array PRESETTABLE = [
        EcfTypeEnum::CREDITO_FISCAL,
        EcfTypeEnum::CONSUMO,
        EcfTypeEnum::REGIMEN_ESPECIAL,
        EcfTypeEnum::GUBERNAMENTAL,
        EcfTypeEnum::EXPORTACION,
    ];

    public function resolve(Invoice $invoice): EcfTypeEnum
    {
        if ($invoice->isCreditNote()) {
            return EcfTypeEnum::NOTA_CREDITO;
        }

        if ($invoice->isDebitNote()) {
            return EcfTypeEnum::NOTA_DEBITO;
        }

        $preset = EcfTypeEnum::tryFrom((string) (EcfCompliance::read($invoice)['type'] ?? ''));

        if ($preset !== null && in_array($preset, self::PRESETTABLE, true)) {
            return $preset;
        }

        return BuyerTaxId::isLocalTo($invoice, new DrFiscalRegime())
            ? EcfTypeEnum::CREDITO_FISCAL
            : EcfTypeEnum::CONSUMO;
    }
}
