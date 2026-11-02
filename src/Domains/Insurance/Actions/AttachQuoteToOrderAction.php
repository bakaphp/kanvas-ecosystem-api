<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Actions;

use Kanvas\Insurance\DataTransferObject\QuoteResult;
use Kanvas\Insurance\Providers\InsuranceProviderFactory;
use Kanvas\Souk\Orders\Models\Order;

class AttachQuoteToOrderAction
{
    public function __construct(
        protected Order $order,
    ) {
    }

    public function execute(): QuoteResult
    {
        $data = new ReadInsuranceOrderAction($this->order)->execute();

        $provider = InsuranceProviderFactory::make(
            (string) $data->provider,
            $this->order->app,
            $this->order->company
        );

        $quote = new PushCustomerToQuoteAction(
            $provider,
            (string) $data->quoteNumber,
            $data->hasCustomerData() ? $data->quoteRequest($provider) : null
        )->execute();

        new StampInsuranceQuoteAction(
            order: $this->order,
            providerName: $provider->name(),
            productCode: (string) $data->productCode,
            quote: $quote,
            commission: $quote->commission,
        )->execute();

        return $quote;
    }
}
