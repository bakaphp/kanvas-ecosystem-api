<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Actions\Corrections;

use Kanvas\Souk\Orders\Actions\Corrections\BaseOrderCorrectionAction;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Users\Models\Users;

class MarkOrderAsDuplicateAction extends BaseOrderCorrectionAction
{
    public function __construct(
        Order $order,
        Users $user,
        protected Order $originalOrder,
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
            $metadata['data']['duplicate_of_order_id'] = $this->originalOrder->id;
            $metadata['data']['duplicate_of_order_number'] = $this->originalOrder->order_number;
            $this->order->metadata = $metadata;

            $this->order->saveOrFail();

            $this->logCorrection(
                'mark-duplicate',
                [
                    'duplicate_of_order_id' => ['old' => null, 'new' => $this->originalOrder->id],
                    'duplicate_of_order_number' => ['old' => null, 'new' => $this->originalOrder->order_number],
                ],
                $this->reason,
                $this->evidenceUrls,
            );

            return $this->order;
        });
    }
}
