<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Internal\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Apps\Models\Apps;
use Kanvas\Souk\Enums\ConfigurationEnum;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\KanvasActivity;
use Override;

class CalculateWarehouseQuantityActivity extends KanvasActivity implements WorkflowActivityInterface
{
    #[Override]
    public function execute(Model $order, AppInterface $app, array $params): array
    {
        $this->overwriteAppService($app);
        $checkExpiredOrders = $app->get(ConfigurationEnum::CHECK_EXPIRED_ORDERS->value) == 1;
        $isMainOrder = ! $order->parent_id;

        // If the order is not related to another order. it means that is not an extension
        // but a main order, we can update the warehouse quantity when the order is completed/paid
        if (count($order->items) > 0 && $checkExpiredOrders && $isMainOrder) {
            $variant = $order->items->first(function ($item) {
                return $item->variant->product?->attributes
                ->contains(fn ($attribute) => in_array($attribute->slug, ['capacity', 'slots']) && ! empty($attribute->value));
            })->variant;

            $product = $variant->product;
            $channel = $variant->variantChannels()->first();
            $capacity = $product->getAttributeByName('capacity')?->value;
            $legacySlots = $capacity['occupiedParkingSpaces'] ?? null;
            $newSlots = $product->getAttributeByName('slots')?->value;
            $slots = $newSlots ?? $legacySlots;
            $variantWarehouse = $channel?->productVariantWarehouse()->first();

            $activeOrders = $this->getActiveOrders($variant->getId(), $app);
            $available = $slots - $activeOrders;
            $variant->updateQuantityInWarehouse($variantWarehouse->warehouse, $available);
            $product->addAttribute('capacity', [
                'occupiedParkingSpaces' => $activeOrders,
                'availableParkingSpaces' => $available,
                'totalParkingSpaces' => $slots,
            ]);
            $product->addAttribute('slots', $slots);
            $product->save();
        }

        return [
            'order' => $order->getId(),
            'status' => 'success',
            'message' => 'Warehouse quantity calculated',
        ];
    }

    private function getActiveOrders(int|string $productVariantId, Apps $app): int
    {
        return Order::fromApp($app)
            ->notDeleted()
            ->whereNotFulfilled()
            ->whereNotNull('metadata')
            ->whereRaw("JSON_LENGTH(COALESCE(NULLIF(metadata, ''), '{}')) > 0")
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(COALESCE(metadata, '{}'), '$.data.end_at')) is not null")
            ->orderBy('id', 'desc')
            ->with('items')
            ->whereHas('items', function ($query) use ($productVariantId) {
                $query->where('variant_id', $productVariantId);
            })->count();
    }
}
