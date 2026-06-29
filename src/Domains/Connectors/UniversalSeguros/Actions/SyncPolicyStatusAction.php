<?php

declare(strict_types=1);

namespace Kanvas\Connectors\UniversalSeguros\Actions;

use Kanvas\Connectors\UniversalSeguros\Enums\CustomFieldEnum;
use Kanvas\Connectors\UniversalSeguros\Enums\InsuranceOrderStatusEnum;
use Kanvas\Connectors\UniversalSeguros\Services\UniversalSegurosService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Souk\Orders\Models\Order;

class SyncPolicyStatusAction
{
    public function __construct(
        protected Order $order,
    ) {
    }

    public function execute(): array
    {
        $quoteNumber = (string) $this->order->get(CustomFieldEnum::QUOTE_NUMBER->value);

        if ($quoteNumber === '') {
            throw new ValidationException('Order has no Universal Seguros quote number to sync');
        }

        $service = new UniversalSegurosService($this->order->app, $this->order->company);
        $policy = $service->getPolicy($quoteNumber);

        $policyNumber = (string) ($policy['numeroPoliza'] ?? $policy['numero'] ?? '');

        if ($policyNumber !== '') {
            $this->order->set(CustomFieldEnum::POLICY_NUMBER->value, $policyNumber);
            $this->order->set(CustomFieldEnum::STATUS->value, InsuranceOrderStatusEnum::POLICY_ACTIVE->value);
        }

        return $policy;
    }
}
