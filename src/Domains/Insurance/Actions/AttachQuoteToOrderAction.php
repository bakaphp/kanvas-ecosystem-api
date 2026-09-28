<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Actions;

use Kanvas\Insurance\DataTransferObject\QuoteResult;
use Kanvas\Insurance\Enums\InsuranceCustomFieldEnum;
use Kanvas\Insurance\Enums\InsuranceStatusEnum;
use Kanvas\Insurance\Providers\InsuranceProviderFactory;
use Kanvas\Souk\Orders\Models\Order;

/**
 * Binds a quote the customer already compared to the Order they decided to buy.
 * Prices are re-read from the insurer rather than trusted from the client, so a
 * tampered quote payload can't set its own premium.
 */
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

        $quote = $provider->getQuote($this->quoteNumber);

        $this->order->set(InsuranceCustomFieldEnum::PROVIDER->value, $provider->name());
        $this->order->set(InsuranceCustomFieldEnum::QUOTE_NUMBER->value, $this->quoteNumber);
        $this->order->set(InsuranceCustomFieldEnum::PREMIUM->value, $quote->premium);
        $this->order->set(InsuranceCustomFieldEnum::RATE_PER_KM->value, $quote->ratePerKm);
        $this->order->set(InsuranceCustomFieldEnum::TAX->value, $quote->tax);
        $this->order->set(InsuranceCustomFieldEnum::TOTAL->value, $quote->total);
        $this->order->set(InsuranceCustomFieldEnum::STATUS->value, InsuranceStatusEnum::QUOTED->value);

        return $quote;
    }
}
