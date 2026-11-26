<?php

declare(strict_types=1);

namespace Kanvas\Scribe\SalesOrders\Services;

use Kanvas\Scribe\SalesOrders\Enums\PoSourceEnum;
use Kanvas\Scribe\SalesOrders\Models\SalesOrder;

/**
 * What must hold before the customer's commitment counts. A purchase order needs an authorized signature; a signed
 * proforma also needs a legible stamp and date, the same legal name and tax id as the proforma, and every page
 * initialed.
 */
class SalesOrderValidityService
{
    public const string AUTHORIZED_SIGNER = 'authorized_signer';
    public const string STAMP_AND_DATE = 'legible_stamp_and_date';
    public const string MATCHING_LEGAL_IDENTITY = 'matching_legal_name_and_tax_id';
    public const string ALL_PAGES_INITIALED = 'all_pages_initialed';

    /**
     * @return list<string> the checklist items not yet confirmed
     */
    public function missing(SalesOrder $order): array
    {
        $confirmed = $order->signature_checklist ?? [];

        return array_values(array_filter(
            $this->required($order),
            static fn (string $item): bool => ($confirmed[$item] ?? false) !== true
        ));
    }

    /**
     * @return list<string>
     */
    private function required(SalesOrder $order): array
    {
        return $order->po_source === PoSourceEnum::SIGNED_PROFORMA
            ? [self::AUTHORIZED_SIGNER, self::STAMP_AND_DATE, self::MATCHING_LEGAL_IDENTITY, self::ALL_PAGES_INITIALED]
            : [self::AUTHORIZED_SIGNER];
    }
}
