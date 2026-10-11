<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Concerns;

use Kanvas\Insurance\Actions\RecordInsuranceStatusAction;
use Kanvas\Insurance\Actions\SettleInsurancePaymentAction;
use Kanvas\Insurance\Enums\InsuranceStatusEnum;
use Kanvas\Souk\Orders\Models\Order;

trait FailsReleasingHeldPayment
{
    protected function failReleasingHold(Order $order, string $message): array
    {
        new RecordInsuranceStatusAction($order, InsuranceStatusEnum::FAILED)->execute();
        $released = new SettleInsurancePaymentAction($order)->release();

        return $this->failWorkflow([
            'order' => $order->getId(),
            'message' => $message,
            'paymentReleased' => $released === true,
        ]);
    }
}
