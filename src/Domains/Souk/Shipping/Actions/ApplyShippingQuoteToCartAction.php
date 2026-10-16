<?php

declare(strict_types=1);

namespace Kanvas\Souk\Shipping\Actions;

use Baka\Contracts\CompanyInterface;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\ScrapperApi\Enums\ShippingCostEnum;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Regions\Models\Regions;
use Kanvas\Souk\Shipping\DataTransferObject\ShippingDestination;
use Kanvas\Souk\Shipping\DataTransferObject\ShippingQuote;
use Kanvas\Souk\Shipping\Enums\ShippingConditionEnum;
use Wearepixel\Cart\Cart;
use Wearepixel\Cart\CartCondition;

class ApplyShippingQuoteToCartAction
{
    public const UNAVAILABLE_MESSAGE = 'Shipping service not available';

    public function __construct(
        protected Apps $app,
        protected CompanyInterface $company,
        protected Regions $region,
        protected Cart $cart,
        protected string $provider,
        protected string $service,
        protected ShippingDestination $destination,
        protected string $unavailableMessage = self::UNAVAILABLE_MESSAGE,
    ) {
    }

    public function execute(): CartCondition
    {
        if ($this->app->get(ShippingCostEnum::LOCOMPRO_COST->value)) {
            throw new ValidationException('Provider shipping quotes are not available for this app');
        }

        $quote = $this->findQuote();

        $this->cart->removeCartCondition(ShippingConditionEnum::NAME->value);
        $condition = $this->buildCondition($quote);
        $this->cart->condition($condition);

        return $condition;
    }

    private function findQuote(): ShippingQuote
    {
        $request = new BuildShipmentRequestAction(
            app: $this->app,
            cart: $this->cart,
            destination: $this->destination,
        )->execute();

        $quotes = new GetShippingQuotesAction(
            app: $this->app,
            company: $this->company,
            region: $this->region,
            request: $request,
        )->execute();

        foreach ($quotes as $quote) {
            if ($quote->provider === $this->provider && $quote->serviceCode === $this->service) {
                return $quote;
            }
        }

        throw new ValidationException($this->unavailableMessage);
    }

    private function buildCondition(ShippingQuote $quote): CartCondition
    {
        return new CartCondition([
            'name' => ShippingConditionEnum::NAME->value,
            'type' => ShippingConditionEnum::TYPE->value,
            'target' => ShippingConditionEnum::TARGET->value,
            'value' => '+' . $quote->amount,
            'attributes' => [
                ShippingConditionEnum::PROVIDER->value => $quote->provider,
                ShippingConditionEnum::SERVICE->value => $quote->serviceCode,
                ShippingConditionEnum::SERVICE_NAME->value => $quote->serviceName,
                ShippingConditionEnum::METHOD_NAME->value => $quote->serviceName,
                ShippingConditionEnum::ESTIMATE_SHIPPING_DATE->value => $this->estimateDate($quote),
                ShippingConditionEnum::DESTINATION->value => $this->destination->toInput(),
                ShippingConditionEnum::QUOTED_AMOUNT->value => $quote->amount,
                ShippingConditionEnum::CURRENCY->value => $quote->currency,
                ShippingConditionEnum::QUOTED_AT->value => Carbon::now()->toIso8601String(),
            ],
        ]);
    }

    private function estimateDate(ShippingQuote $quote): ?string
    {
        return $quote->transitMaxDays === null
            ? null
            : Carbon::now()->addWeekdays($quote->transitMaxDays)->toDateString();
    }
}
