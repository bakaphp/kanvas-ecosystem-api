<?php

declare(strict_types=1);

namespace Kanvas\Connectors\UniversalSeguros\Actions;

use Kanvas\Connectors\UniversalSeguros\Enums\CustomFieldEnum;
use Kanvas\Connectors\UniversalSeguros\Enums\InsuranceOrderStatusEnum;
use Kanvas\Connectors\UniversalSeguros\Services\UniversalSegurosService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Souk\Orders\Models\Order;

class RequestPaymentLinkAction
{
    public function __construct(
        protected Order $order,
        protected bool $byEmail = false,
    ) {
    }

    public function execute(): array
    {
        $quoteNumber = (string) $this->order->get(CustomFieldEnum::QUOTE_NUMBER->value);

        if ($quoteNumber === '') {
            throw new ValidationException('Order has no Universal Seguros quote number to request payment for');
        }

        $service = new UniversalSegurosService($this->order->app, $this->order->company);

        if ($this->byEmail) {
            $response = $service->sendPaymentLinkEmail($quoteNumber);
        } else {
            $response = $service->getPaymentLink($quoteNumber);

            if (! empty($response['url'])) {
                $this->order->set(CustomFieldEnum::PAYMENT_URL->value, (string) $response['url']);
            }
        }

        $this->order->set(CustomFieldEnum::STATUS->value, InsuranceOrderStatusEnum::AWAITING_PAYMENT->value);

        return $response;
    }
}
