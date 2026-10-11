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
        protected string $providerName,
        protected string $quoteNumber,
    ) {
    }

    public function execute(): QuoteResult
    {
        $provider = InsuranceProviderFactory::make(
            $this->providerName,
            $this->order->app,
            $this->order->company
        );

        $quote = new PushCustomerToQuoteAction(
            $provider,
            $this->quoteNumber,
            empty($this->order->metadata['insurance']['cliente'])
                ? null
                : new BuildQuoteRequestFromOrderAction($this->order)->execute()
        )->execute();

        new StampInsuranceQuoteAction(
            order: $this->order,
            providerName: $provider->name(),
            productCode: (string) ($this->order->metadata['insurance']['product'] ?? ''),
            quote: $quote,
            commission: $quote->commission,
        )->execute();

        return $quote;
    }
}
