<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Actions\Corrections;

use Kanvas\Souk\Orders\Actions\Corrections\BaseOrderCorrectionAction;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Users\Models\Users;

class AddObservationsAction extends BaseOrderCorrectionAction
{
    public function __construct(
        Order $order,
        Users $user,
        protected string $observations,
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
            $oldObservations = $metadata['data']['observations'] ?? '';

            $metadata['data']['observations'] = $this->observations;
            $this->order->metadata = $metadata;

            $this->order->saveOrFail();

            $this->logCorrection(
                'add-observations',
                ['observations' => ['old' => $oldObservations, 'new' => $this->observations]],
                $this->reason,
                $this->evidenceUrls,
            );

            return $this->order;
        });
    }
}
