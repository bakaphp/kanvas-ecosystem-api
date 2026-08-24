<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Actions\Corrections;

use Kanvas\Exceptions\ValidationException;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Souk\Orders\Actions\Corrections\BaseOrderCorrectionAction;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Users\Models\Users;

class RelocateVehicleAction extends BaseOrderCorrectionAction
{
    public function __construct(
        Order $order,
        Users $user,
        protected int $newVariantId,
        protected array $carDeposit,
        protected string $reason,
        protected array $evidenceUrls = [],
        protected ?int $currentVariantId = null,
    ) {
        parent::__construct($order, $user);
    }

    public function execute(): Order
    {
        return $this->transact(function () {
            $this->guardNotFinalStatus();

            $items = $this->order->allItems()->get();

            if ($this->currentVariantId !== null) {
                $locationItem = $items->first(fn ($item) => (int) $item->variant_id === $this->currentVariantId);
            } else {
                $impoundLotIds = $this->resolveImpoundLotVariantIds($items->pluck('variant_id')->filter()->values()->all());
                $locationItem = $items->first(fn ($item) => in_array($item->variant_id, $impoundLotIds));
            }

            if (! $locationItem) {
                throw new ValidationException('No location item found on this order');
            }

            $newVariant = Variants::findOrFail($this->newVariantId);

            if (! in_array($newVariant->id, $this->resolveImpoundLotVariantIds([$newVariant->id]))) {
                throw new ValidationException('New variant must be a location (impound_lot) variant');
            }

            $oldVariantName = $locationItem->variant_name;
            $metadata = is_array($this->order->metadata) ? $this->order->metadata : [];
            $oldCarDeposit = $metadata['data']['carDeposit'] ?? [];

            $newVariant->load('product');

            $locationItem->variant_id = $newVariant->id;
            $locationItem->variant_name = $newVariant->name;
            $locationItem->product_name = $newVariant->product->name;
            $locationItem->saveOrFail();

            $metadata['data']['carDeposit'] = $this->carDeposit;
            $this->order->metadata = $metadata;
            $this->order->saveOrFail();

            $this->order->calculateTotal();

            $this->logCorrection(
                'relocate',
                [
                    'center' => [
                        'old' => ['variant_name' => $oldVariantName, 'car_deposit' => $oldCarDeposit],
                        'new' => ['variant_name' => $newVariant->name, 'car_deposit' => $this->carDeposit],
                    ],
                ],
                $this->reason,
                $this->evidenceUrls,
            );

            return $this->order;
        });
    }

    private function resolveImpoundLotVariantIds(array $variantIds): array
    {
        if (empty($variantIds)) {
            return [];
        }

        return Variants::query()
            ->whereIn('id', $variantIds)
            ->whereHas('product.productType', fn ($q) => $q->where('slug', 'impound-lot'))
            ->pluck('id')
            ->toArray();
    }
}
