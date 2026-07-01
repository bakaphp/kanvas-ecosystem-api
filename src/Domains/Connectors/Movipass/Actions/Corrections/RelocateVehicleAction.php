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
    ) {
        parent::__construct($order, $user);
    }

    public function execute(): Order
    {
        return $this->transact(function () {
            $this->guardNotFinalStatus();

            $locationItem = $this->order
                ->allItems()
                ->with('variant.product.productType')
                ->get()
                ->first(fn ($item) => $item->variant?->product?->productType?->slug === 'impound_lot');

            if (! $locationItem) {
                throw new ValidationException('No location item found on this order');
            }

            $newVariant = Variants::with('product.productType')
                ->findOrFail($this->newVariantId);

            if ($newVariant->product?->productType?->slug !== 'impound_lot') {
                throw new ValidationException('New variant must be a location (impound_lot) variant');
            }

            $oldVariantName = $locationItem->variant_name;
            $metadata = is_array($this->order->metadata) ? $this->order->metadata : [];
            $oldCarDeposit = $metadata['data']['carDeposit'] ?? [];

            $locationItem->variant_id = $newVariant->id;
            $locationItem->variant_name = $newVariant->name;
            $locationItem->product_name = $newVariant->product->name;
            $locationItem->saveOrFail();

            $metadata['data']['carDeposit'] = $this->carDeposit;
            $this->order->metadata = $metadata;
            $this->appendEvidenceImages($this->evidenceUrls);
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
}
