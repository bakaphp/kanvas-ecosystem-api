<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Actions;

use Kanvas\Insurance\DataTransferObject\QuoteResult;
use Kanvas\Insurance\Enums\InsuranceCustomFieldEnum;
use Kanvas\Insurance\Enums\InsuranceStatusEnum;
use Kanvas\Souk\Orders\Models\Order;

class StampInsuranceQuoteAction
{
    public function __construct(
        private readonly Order $order,
        private readonly string $providerName,
        private readonly string $productCode,
        private readonly QuoteResult $quote,
        private readonly ?float $commission = null,
    ) {
    }

    public function execute(): void
    {
        $this->order->set(InsuranceCustomFieldEnum::PROVIDER->value, $this->providerName);
        $this->order->set(InsuranceCustomFieldEnum::QUOTE_NUMBER->value, $this->quote->quoteNumber);
        $this->order->set(InsuranceCustomFieldEnum::PRODUCT_CODE->value, $this->productCode);
        $this->order->set(InsuranceCustomFieldEnum::PREMIUM->value, $this->quote->premium);
        $this->order->set(InsuranceCustomFieldEnum::RATE_PER_KM->value, $this->quote->ratePerKm);
        $this->order->set(InsuranceCustomFieldEnum::TAX->value, $this->quote->tax);
        $this->order->set(InsuranceCustomFieldEnum::TOTAL->value, $this->quote->total);
        new RecordInsuranceStatusAction($this->order, InsuranceStatusEnum::QUOTED)->execute();

        if ($this->commission !== null) {
            $this->order->set(InsuranceCustomFieldEnum::COMMISSION->value, $this->commission);
        }

        if ($this->quote->canEmit !== null) {
            $this->order->set(InsuranceCustomFieldEnum::CAN_EMIT->value, $this->quote->canEmit ? '1' : '0');
        }

        if ($this->quote->inspectionStatus !== null) {
            $this->order->set(InsuranceCustomFieldEnum::INSPECTION_STATUS->value, $this->quote->inspectionStatus);
        }
    }
}
