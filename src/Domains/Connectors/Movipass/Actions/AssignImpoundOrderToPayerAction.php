<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Actions;

use Kanvas\Connectors\Movipass\Enums\OrderTypeEnum;
use Kanvas\Souk\Orders\Actions\AssignCorporateOrderOwnerAction;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Souk\Payments\Models\Payments;
use Kanvas\Users\Models\Users;

class AssignImpoundOrderToPayerAction
{
    private ?Payments $payment = null;

    public function __construct(
        private readonly Order $order,
    ) {
    }

    public function payer(): ?Users
    {
        if ($this->order->orderType?->name !== OrderTypeEnum::IMPOUND_LOT->value) {
            return null;
        }

        $this->payment ??= Payments::getLatestForEntity($this->order, [PaymentStatusEnum::PAID->value]);

        return $this->payment?->user;
    }

    public function needsMove(): bool
    {
        $payer = $this->payer();

        return $payer !== null && $payer->getId() !== (int) $this->order->users_id;
    }

    public function execute(): ?array
    {
        $payer = $this->payer();

        if ($payer === null) {
            return null;
        }

        $previousUserId = (int) $this->order->users_id;
        $previousPeopleId = (int) $this->order->people_id;

        if (new AssignCorporateOrderOwnerAction($this->order, $payer)->execute() === null) {
            return null;
        }

        $changes = [
            'payment_id' => $this->payment->getId(),
            'previous_users_id' => $previousUserId,
            'new_users_id' => (int) $this->order->users_id,
            'previous_people_id' => $previousPeopleId,
            'new_people_id' => (int) $this->order->people_id,
        ];

        activity()
            ->causedBy($payer)
            ->performedOn($this->order)
            ->withProperties($changes)
            ->log('IMPOUND_ORDER_ASSIGNED_TO_PAYER');

        return $changes;
    }
}
