<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Services;

use Illuminate\Http\UploadedFile;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Souk\Cart\Actions\AddToCartAction;
use Kanvas\Users\Models\Users;
use League\Csv\Reader;

class OrderItemService
{
    public function __construct(
        protected Apps $app,
        protected Users $user,
        protected Companies $currentUsercompany,
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
                'variant_sku' => $variantEAN,
                'quantity' => $quantity,
            ];
        }

        return $results;
    }

    public function getValidOrderItems(array $orderItems): array
    {
        $validOrderItems = [];
        foreach ($orderItems as $orderItem) {
            $variant = Variants::where('sku', $orderItem['variant_sku'])->first();

            if (empty($variant)) {
                continue;
            }

            $validOrderItems[] = $orderItem;
        }

        return $validOrderItems;
    }

    public function processOrderItems(array $orderItems, int $channelId): void
    {
        $cartItems = [];
        foreach ($orderItems as $orderItem) {
            $variant = Variants::where('sku', $orderItem['variant_sku'])->first();
            $channel = $variant->variantChannels()->where('channels_id', $channelId)->first();

            if (empty($variant)) {
                continue;
            }

            $minimumOrderQuantity = $channel?->config['minimum_quantity'] ?? 0;
            $warehouse = $channel?->productVariantWarehouse()->first();
            $currentStock = $warehouse?->quantity ?? 0;

            if ($currentStock < $orderItem['quantity']) {
                throw new \Exception('Not enough stock for product ' . $variant->name);
            }

            if ($minimumOrderQuantity > $orderItem['quantity']) {
                throw new \Exception('Minimum order quantity for product ' . $variant->name . ' is ' . $minimumOrderQuantity);
            }

            $cartItems[] = [
                'variant_id' => $variant->id,
                'quantity' => $orderItem['quantity'],
            ];
        }

        $cart = app('cart')->session($this->user->getId());
        $addToCartAction = new AddToCartAction($this->app, $this->user, $this->currentUsercompany);
        $addToCartAction->execute($cart, $cartItems);
    }
}
