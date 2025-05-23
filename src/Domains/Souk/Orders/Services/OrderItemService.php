<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Services;

use Illuminate\Http\UploadedFile;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Users\Models\Users;
use League\Csv\Reader;

class OrderItemService
{
    public function __construct(
        protected Apps $app,
        protected Users $user,
        protected Companies $currentUserCompany,
    ) {
    }

    public function getOrderItemsFromCsv(UploadedFile $file, int $headerOffset = 1): array
    {
        $csv = Reader::createFromPath($file->getRealPath());
        $csv->setHeaderOffset($headerOffset);
        $csv->skipEmptyRecords();
        $records = $csv->getRecords();
        $results = [];
        foreach ($records as $offset => $record) {
            if ($offset < $headerOffset) {
                continue;
            }

            $quantity = (int) $record['Order Qty'];
            if ($quantity <= 0) {
                continue;
            }

            $variantEAN = $record['Copic Item No/ UPC'];
            if (empty($variantEAN)) {
                continue;
            }

            $results[] = [
                'variant_ean' => $variantEAN,
                'quantity' => $quantity,
            ];
        }

        return $results;
    }

    public function getValidOrderItems(array $orderItems): array
    {
        $validOrderItems = [];
        foreach ($orderItems as $orderItem) {
            $variant = Variants::where('ean', $orderItem['variant_ean'])
                ->orWhere('barcode', $orderItem['variant_ean'])
                ->first();

            if (empty($variant)) {
                continue;
            }

            $validOrderItems[] = [
                ...$orderItem,
                'variant_id' => $variant->id
            ];
        }

        return $validOrderItems;
    }

    public function processOrderItems(array $orderItems, int $channelId): array
    {
        $cartItems = [];
        $errors = [];
        foreach ($orderItems as $orderItem) {
            $variant = Variants::where('id', $orderItem['variant_id'])->first();
            $channel = $variant->variantChannels()->where('channels_id', $channelId)->first();

            if (empty($variant)) {
                continue;
            }

            $minimumOrderQuantity = $channel?->config['minimum_quantity'] ?? 0;
            $warehouse = $channel?->productVariantWarehouse()->first();
            $currentStock = $warehouse?->quantity ?? 0;

            if ($currentStock < $orderItem['quantity']) {
                $errors[] = 'Not enough stock for product ' . $variant->name;
                continue;
            }

            if ($minimumOrderQuantity > $orderItem['quantity']) {
                $errors[] = 'Minimum order quantity for product ' . $variant->name . ' is ' . $minimumOrderQuantity;
                continue;
            }

            $cartItems[] = [
                'variant_id' => $variant->id,
                'quantity' => $orderItem['quantity'],
            ];
        }

        return [
            'validItems' => $cartItems,
            'errors' => $errors,
        ];
    }
}
