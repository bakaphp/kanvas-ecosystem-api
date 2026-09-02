<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Acumatica\DataTransferObject;

use Baka\Support\Str;
use Kanvas\Connectors\Acumatica\Enums\CustomFieldEnum;
use Kanvas\Souk\Orders\Enums\OrderFulfillmentStatusEnum;
use Kanvas\Souk\Orders\Enums\OrderStatusEnum;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

/**
 * Maps one Acumatica sales order (dbo.SOOrder header + dbo.SOLine lines) to the shape
 * PullSalesOrdersAction feeds into Souk's CreateOrderAction. People + variant resolution happen in
 * the pull action.
 *
 * @property DataCollection<AcumaticaImportOrderItem> $items
 */
class AcumaticaImportOrder extends Data
{
    /**
     * @param array<string, string> $customFields
     * @param array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly string $orderNumber,
        public readonly string $orderType,
        public readonly string $token,
        public readonly string $customerCode,
        public readonly string $status,
        public readonly string $fulfillmentStatus,
        public readonly float $total,
        public readonly float $taxes,
        public readonly float $totalDiscount,
        public readonly float $totalShipping,
        public readonly string $currency,
        public readonly ?string $orderDate,
        public readonly ?string $shippedDate,
        public readonly ?string $customerNote,
        public readonly ?string $reference,
        public readonly array $customFields,
        public readonly array $metadata,
        /** @var DataCollection<AcumaticaImportOrderItem> */
        public readonly DataCollection $items,
    ) {
    }

    /**
     * @param array<array-key, mixed>             $header SOOrder row (+ joined AcctCD)
     * @param array<int, array<array-key, mixed>> $lines  SOLine rows (+ joined sku)
     */
    public static function fromArray(array $header, array $lines): self
    {
        $orderType = trim((string) ($header['OrderType'] ?? ''));
        $orderNbr = trim((string) ($header['OrderNbr'] ?? ''));
        $externalId = $orderType . '-' . $orderNbr;

        $items = [];
        $shippedTotal = 0.0;
        $orderedTotal = 0.0;

        foreach ($lines as $line) {
            $sku = trim((string) ($line['sku'] ?? $line['InventoryCD'] ?? ''));

            if ($sku === '') {
                continue;
            }

            $qty = (float) ($line['OrderQty'] ?? 0);
            $shipped = (float) ($line['ShippedQty'] ?? 0);
            $orderedTotal += $qty;
            $shippedTotal += $shipped;

            $items[] = new AcumaticaImportOrderItem(
                sku: $sku,
                name: trim((string) ($line['TranDesc'] ?? '')) ?: $sku,
                quantity: $qty,
                quantityShipped: (int) $shipped,
                price: (float) ($line['UnitPrice'] ?? 0),
                discount: (float) ($line['DiscAmt'] ?? 0),
                tax: 0.0,
                warehouse: Str::trimToNull((string) ($line['warehouse'] ?? '')),
            );
        }

        return new self(
            orderNumber: $orderNbr,
            orderType: $orderType,
            token: ! empty($header['NoteID']) ? (string) $header['NoteID'] : $externalId,
            customerCode: trim((string) ($header['AcctCD'] ?? '')),
            status: self::status($header),
            fulfillmentStatus: self::fulfillmentStatus($header, $orderedTotal, $shippedTotal),
            total: (float) ($header['OrderTotal'] ?? 0),
            taxes: (float) ($header['TaxTotal'] ?? 0),
            totalDiscount: (float) ($header['DiscTot'] ?? 0),
            totalShipping: (float) ($header['FreightTot'] ?? 0),
            currency: trim((string) ($header['CuryID'] ?? '')) ?: 'USD',
            orderDate: ! empty($header['OrderDate']) ? (string) $header['OrderDate'] : null,
            shippedDate: ! empty($header['ShipDate']) ? (string) $header['ShipDate'] : null,
            customerNote: Str::trimToNull((string) ($header['OrderDesc'] ?? '')),
            reference: Str::trimToNull((string) ($header['CustomerOrderNbr'] ?? '')),
            customFields: [
                CustomFieldEnum::ORDER_ID->value => $externalId,
                CustomFieldEnum::ORDER_TYPE->value => $orderType,
            ],
            metadata: array_filter([
                'acumatica_order_type' => $orderType,
                'acumatica_order_number' => $orderNbr,
                'rma_number' => ! empty($header['UsrRMANbr']) ? (string) $header['UsrRMANbr'] : null,
            ], static fn ($v): bool => $v !== null),
            items: new DataCollection(AcumaticaImportOrderItem::class, $items),
        );
    }

    /**
     * @param array<array-key, mixed> $header
     */
    private static function status(array $header): string
    {
        return match (true) {
            ! empty($header['Cancelled']) => OrderStatusEnum::CANCELED->value,
            ! empty($header['Completed']) => OrderStatusEnum::COMPLETED->value,
            ! empty($header['Hold']) => OrderStatusEnum::DRAFT->value,
            default => OrderStatusEnum::PENDING->value,
        };
    }

    /**
     * @param array<array-key, mixed> $header
     */
    private static function fulfillmentStatus(array $header, float $ordered, float $shipped): string
    {
        if (! empty($header['Cancelled'])) {
            return OrderFulfillmentStatusEnum::CANCELLED->value;
        }

        return ($ordered > 0 && $shipped >= $ordered)
            ? OrderFulfillmentStatusEnum::COMPLETED->value
            : OrderFulfillmentStatusEnum::PENDING->value;
    }
}
