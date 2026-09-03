<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Actions\Corrections;

use Kanvas\Exceptions\ValidationException;
use Kanvas\Souk\Orders\Actions\Corrections\BaseOrderCorrectionAction;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Users\Models\Users;

class CorrectVehicleDataAction extends BaseOrderCorrectionAction
{
    private const EDITABLE_FIELDS = [
        'brand' => 'vehicleBrand',
        'model' => 'vehicleModel',
        'color' => 'vehicleColor',
    ];

    public function __construct(
        Order $order,
        Users $user,
        protected array $vehicleData,
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
            $changes = [];

            foreach (self::EDITABLE_FIELDS as $input => $metadataKey) {
                if (! isset($this->vehicleData[$input]) || ! is_scalar($this->vehicleData[$input])) {
                    continue;
                }

                $new = trim((string) $this->vehicleData[$input]);
                $old = (string) ($metadata['data'][$metadataKey] ?? '');

                if ($new === '' || $new === $old) {
                    continue;
                }

                $metadata['data'][$metadataKey] = $new;
                $changes[$metadataKey] = ['old' => $old, 'new' => $new];
            }

            if (empty($changes)) {
                throw new ValidationException(
                    'data must include at least one changed value among: ' . implode(', ', array_keys(self::EDITABLE_FIELDS))
                );
            }

            $this->order->metadata = $metadata;

            if (isset($changes['vehicleBrand'])) {
                $plate = $metadata['data']['vehiclePlate'] ?? '';
                $this->order->reference = "{$changes['vehicleBrand']['new']} / {$plate} - #{$this->order->order_number}";
            }

            $this->order->saveOrFail();

            $this->logCorrection(
                'correct-vehicle-data',
                $changes,
                $this->reason,
                $this->evidenceUrls,
            );

            return $this->order;
        });
    }
}
