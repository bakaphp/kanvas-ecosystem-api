<?php

declare(strict_types=1);

namespace Kanvas\Scribe\ElectronicInvoicing\Support;

use Baka\Support\Str;
use Kanvas\Guild\Organizations\Enums\TaxIdTypeEnum;
use Kanvas\Scribe\ElectronicInvoicing\Contracts\FiscalRegimeInterface;
use Kanvas\Scribe\Invoices\Models\Invoice;

/**
 * Which country an invoice buyer's tax id belongs to. The number's shape cannot say (a US EIN and a Dominican
 * RNC are both 9 digits), so the customer's stated type decides, then an inference from the customer's own
 * country, and only then from the regime's (the issuer is in it by definition).
 */
final class BuyerTaxId
{
    /**
     * The frozen snapshot once issued; the live customer while still a draft.
     */
    public static function of(Invoice $invoice): ?string
    {
        return Str::normalizeTaxId($invoice->billable_tax_id ?? $invoice->customer?->getBillableTaxId());
    }

    public static function type(Invoice $invoice, FiscalRegimeInterface $regime): ?TaxIdTypeEnum
    {
        $taxId = self::of($invoice);
        if ($taxId === null) {
            return null;
        }

        $customer = $invoice->customer;

        // A draft may carry an id typed in by hand; the customer's stated type only describes the customer's own id.
        if ($customer?->tax_id_type !== null && $taxId === Str::normalizeTaxId($customer->tax_id)) {
            return $customer->tax_id_type;
        }

        return TaxIdTypeEnum::infer($customer?->countryCode() ?? $regime->country(), $taxId);
    }

    public static function isLocalTo(Invoice $invoice, FiscalRegimeInterface $regime): bool
    {
        return self::type($invoice, $regime)?->country() === $regime->country();
    }

    /**
     * An id whose type is known and not the regime's; an id of unknown type is not assumed foreign.
     */
    public static function isForeignTo(Invoice $invoice, FiscalRegimeInterface $regime): bool
    {
        $type = self::type($invoice, $regime);

        return $type !== null && $type->country() !== $regime->country();
    }
}
