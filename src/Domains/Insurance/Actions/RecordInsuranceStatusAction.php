<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Actions;

use Illuminate\Support\Facades\Log;
use Kanvas\Insurance\Enums\InsuranceCustomFieldEnum;
use Kanvas\Insurance\Enums\InsuranceOrderStatusEnum;
use Kanvas\Insurance\Enums\InsuranceOrderTypeEnum;
use Kanvas\Insurance\Enums\InsuranceStatusEnum;
use Kanvas\Souk\Orders\Actions\TransitionOrderStateAction;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Models\OrderStatus;
use Throwable;

class RecordInsuranceStatusAction
{
    public function __construct(
        private readonly Order $order,
        private readonly InsuranceStatusEnum $status,
    ) {
    }

    public function execute(): void
    {
        if (! InsuranceOrderTypeEnum::INSURANCE->isTypeOf($this->order)) {
            $this->order->set(InsuranceCustomFieldEnum::STATUS->value, $this->status->value);

            return;
        }

        try {
            $this->mirror();
        } catch (Throwable $e) {
            report($e);
        } finally {
            $this->order->unsetRelation('orderStatus');
        }
    }

    private function mirror(): void
    {
        $target = OrderStatus::query()
            ->where('order_types_id', $this->order->order_types_id)
            ->where('slug', InsuranceOrderStatusEnum::fromInsuranceStatus($this->status)->value)
            ->first();

        $this->order->unsetRelation('orderStatus');

        if ($target === null || (int) $this->order->order_status_id === $target->getId()) {
            return;
        }

        $user = $this->order->user;

        if ($user === null) {
            return;
        }

        $result = new TransitionOrderStateAction($this->order, $user, $target)->execute(processQuietly: true);

        if ($result['status'] !== 'success') {
            Log::warning('Insurance order status was not mirrored', [
                'order_id' => $this->order->getId(),
                'insurance_status' => $this->status->value,
                'reason' => $result['message'],
            ]);
        }
    }
}
