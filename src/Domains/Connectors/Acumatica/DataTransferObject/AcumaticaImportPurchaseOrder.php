<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Acumatica\DataTransferObject;

use Baka\Support\DateHelper;
use Baka\Support\Str;
use Illuminate\Support\Carbon;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

/**
 * Maps one Acumatica purchase order (dbo.POOrder header + dbo.POLine lines) into the shape
 * PullPurchaseOrdersAction upserts into Scribe's purchase-order read-mirror. Vendor / account / sub
 * / variant resolution (code → Kanvas id) happens in the pull action.
 *
 * @property DataCollection<AcumaticaImportPurchaseOrderLine> $lines
 */
class AcumaticaImportPurchaseOrder extends Data
{
    public const string SOURCE = 'acumatica';

    public function __construct(
        public readonly string $externalId,
        public readonly string $orderType,
        public readonly string $orderNumber,
        public readonly string $vendorCode,
        public readonly ?string $status,
        public readonly ?Carbon $orderDate,
        public readonly string $currency,
        public readonly float $orderTotal,
        /** @var DataCollection<AcumaticaImportPurchaseOrderLine> */
        public readonly DataCollection $lines,
    ) {
    }

    /**
     * @param array<array-key, mixed>             $header POOrder row (+ joined vendor AcctCD)
     * @param array<int, array<array-key, mixed>> $lines  POLine rows (+ joined sku / account / sub)
     */
    public static function fromArray(array $header, array $lines): self
    {
        $orderType = trim((string) ($header['OrderType'] ?? ''));
        $orderNbr = trim((string) ($header['OrderNbr'] ?? ''));

        return new self(
            externalId: $orderType . '-' . $orderNbr,
            orderType: $orderType,
            orderNumber: $orderNbr,
            vendorCode: trim((string) ($header['AcctCD'] ?? '')),
            status: Str::trimToNull((string) ($header['Status'] ?? '')),
            orderDate: DateHelper::tryParseCarbon($header['OrderDate'] ?? null),
            currency: trim((string) ($header['CuryID'] ?? '')) ?: 'USD',
            orderTotal: (float) ($header['CuryOrderTotal'] ?? ($header['OrderTotal'] ?? 0)),
            lines: new DataCollection(
                AcumaticaImportPurchaseOrderLine::class,
                self::mapLines($lines)
            ),
        );
    }

    /**
     * @param array<int, array<array-key, mixed>> $lines
     *
     * @return array<int, AcumaticaImportPurchaseOrderLine>
     */
    private static function mapLines(array $lines): array
    {
        $mapped = [];

        foreach ($lines as $line) {
            $sku = trim((string) ($line['sku'] ?? ''));
            $accountCd = trim((string) ($line['AccountCD'] ?? ''));
            $subCode = trim((string) ($line['SubCD'] ?? ''));
            $description = trim((string) ($line['TranDesc'] ?? ''));

            $mapped[] = new AcumaticaImportPurchaseOrderLine(
                lineNumber: (int) ($line['LineNbr'] ?? 0),
                sku: $sku !== '' ? $sku : null,
                description: $description !== '' ? $description : null,
                accountCd: $accountCd !== '' ? $accountCd : null,
                subCode: $subCode !== '' ? $subCode : null,
                orderQty: (float) ($line['OrderQty'] ?? 0),
                openQty: (float) ($line['OpenQty'] ?? 0),
                receivedQty: (float) ($line['ReceivedQty'] ?? 0),
                unitCost: (float) ($line['CuryUnitCost'] ?? 0),
                extCost: (float) ($line['CuryExtCost'] ?? 0),
            );
        }

        return $mapped;
    }
}
