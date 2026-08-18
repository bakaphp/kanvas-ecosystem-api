<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Actions\Corrections;

use Kanvas\Souk\Orders\Actions\Corrections\BaseOrderCorrectionAction;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Users\Models\Users;

class CorrectVehiclePlateAction extends BaseOrderCorrectionAction
{
    public function __construct(
        Order $order,
        Users $user,
        protected string $newPlate,
        protected string $reason,
        protected array $evidenceUrls = [],
    ) {
        parent::__construct($order, $user);
    }

    public function execute(): Order
    {
        return $this->transact(function () {
            $this->guardNotFinalStatus();

            $metadata = is_array($this->order->metadata) ? $this->order->metadata : [];
            $oldPlate = $metadata['data']['vehiclePlate'] ?? '';
            $brand = $metadata['data']['vehicleBrand'] ?? '';

            $metadata['data']['vehiclePlate'] = $this->newPlate;
            $this->order->metadata = $metadata;

            $this->order->reference = "{$brand} / {$this->newPlate} - #{$this->order->order_number}";

            $this->order->saveOrFail();

            $this->logCorrection(
                'correct-plate',
                ['vehiclePlate' => ['old' => $oldPlate, 'new' => $this->newPlate]],
                $this->reason,
                $this->evidenceUrls,
            );

            return $this->order;
        });
    }
}
