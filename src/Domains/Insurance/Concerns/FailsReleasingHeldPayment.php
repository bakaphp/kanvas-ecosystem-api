<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Concerns;

use Kanvas\Insurance\Actions\SettleInsurancePaymentAction;
use Kanvas\Insurance\Enums\InsuranceCustomFieldEnum;
use Kanvas\Insurance\Enums\InsuranceStatusEnum;
use Kanvas\Souk\Orders\Models\Order;

trait FailsReleasingHeldPayment
{
    protected function failReleasingHold(Order $order, string $message): array
    {
        $order->set(InsuranceCustomFieldEnum::STATUS->value, InsuranceStatusEnum::FAILED->value);
        $released = new SettleInsurancePaymentAction($order)->release();

        return $this->failWorkflow([
            'order' => $order->getId(),
            'message' => $message,
            'paymentReleased' => $released === true,
        ]);
    }
}
