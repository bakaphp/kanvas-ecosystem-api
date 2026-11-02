<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Actions;

use Kanvas\Insurance\Contracts\CustomerDataProviderInterface;
use Kanvas\Insurance\DataTransferObject\InsuranceOrderData;
use Kanvas\Insurance\DataTransferObject\VerifiedInsuranceOrder;
use Kanvas\Insurance\Enums\InsuranceProductTypeEnum;
use Kanvas\Insurance\Enums\InsuranceStatusEnum;
use Kanvas\Insurance\Exceptions\InsuranceValidationException;
use Kanvas\Insurance\Models\InsurancePlan;
use Kanvas\Insurance\Providers\InsuranceProviderFactory;
use Kanvas\Insurance\Services\InsuranceContractGate;
use Kanvas\Insurance\Services\InsurancePlanProductResolver;
use Kanvas\Insurance\Services\InsuranceQuoteBinding;
use Kanvas\Insurance\Services\InsuranceStatus;
use Kanvas\Insurance\Services\InsuranceVehicleProduct;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Models\OrderItem;

class ValidateInsuranceOrderAction
{
    private const array AMOUNT_COLUMNS = [
        'tax_amount',
        'discount_amount',
        'shipping_price_gross_amount',
        'shipping_price_net_amount',
    ];

    public function __construct(
        private readonly Order $order,
        private readonly InsuranceOrderData $data,
    ) {
    }

    public function execute(): VerifiedInsuranceOrder
    {
        $items = $this->order->items;
        $vehicleItems = $items->filter(fn (OrderItem $item): bool => $this->isTyped($item, InsuranceProductTypeEnum::VEHICLE));
        $planItems = $items->filter(fn (OrderItem $item): bool => $this->isTyped($item, InsuranceProductTypeEnum::PLAN));

        if ($vehicleItems->count() !== 1) {
            throw new InsuranceValidationException('Choose exactly one vehicle to insure');
        }

        if ($planItems->count() !== 1) {
            throw new InsuranceValidationException('Choose exactly one insurance plan');
        }

        if ($items->count() !== 2 || $items->contains(fn (OrderItem $item): bool => (float) $item->quantity !== 1.0)) {
            throw new InsuranceValidationException('An insurance order takes one vehicle and one plan');
        }

        $planPrice = (float) $planItems->first()->unit_price_gross_amount;
        $this->assertChargedAmounts($vehicleItems->first(), $planPrice);
        $this->assertOwnsVehicle($vehicleItems->first());

        $plan = $this->activePlan();
        $this->assertQuoteBoundToPlan($plan);
        $this->assertQuoteNotContracted();

        $provider = InsuranceProviderFactory::make($plan->insurer->provider, $this->order->app, $this->order->company);

        InsuranceContractGate::assertContractable($provider);

        if ($provider instanceof CustomerDataProviderInterface) {
            if ($this->data->customer === null) {
                throw new InsuranceValidationException('Customer information is required to contract insurance');
            }

            $this->data->customer->assertHas($provider->requiredCustomerFields($this->data->customer));
        }

        return new VerifiedInsuranceOrder($plan, $provider, $planPrice);
    }

    private function isTyped(OrderItem $item, InsuranceProductTypeEnum $type): bool
    {
        return $type->isTypeOf($item->variant?->product);
    }

    private function assertChargedAmounts(OrderItem $vehicleItem, float $planPrice): void
    {
        $tolerance = VerifiedInsuranceOrder::PRICE_TOLERANCE;

        if (abs((float) $vehicleItem->unit_price_gross_amount) > $tolerance) {
            throw new InsuranceValidationException('The vehicle line cannot carry a price');
        }

        if (abs((float) $this->order->total_gross_amount - $planPrice) > $tolerance) {
            throw new InsuranceValidationException('The order total does not match the plan price');
        }

        foreach (self::AMOUNT_COLUMNS as $column) {
            if (abs((float) $this->order->{$column}) > $tolerance) {
                throw new InsuranceValidationException('An insurance order cannot carry tax, shipping or discounts');
            }
        }
    }

    private function assertOwnsVehicle(OrderItem $item): void
    {
        if (! InsuranceVehicleProduct::isOwnedBy($item->variant?->product, $this->order->app, (int) $this->order->users_id)) {
            throw new InsuranceValidationException('The vehicle does not belong to this account');
        }
    }

    private function activePlan(): InsurancePlan
    {
        if ($this->data->provider === null || $this->data->productCode === null) {
            throw new InsuranceValidationException('This plan is no longer available');
        }

        return $this->resolver()->activePlan($this->data->provider, $this->data->productCode)
            ?? throw new InsuranceValidationException('This plan is no longer available');
    }

    private function assertQuoteBoundToPlan(InsurancePlan $plan): void
    {
        $quoteNumber = $this->data->quoteNumber ?? throw new InsuranceValidationException('Select a plan quote before contracting');
        $appId = $this->order->app->getId();
        $userId = (int) $this->order->users_id;

        $boundPlanId = InsuranceQuoteBinding::planIdFor($appId, $userId, $quoteNumber);

        if ($boundPlanId === null) {
            throw new InsuranceValidationException(
                InsuranceQuoteBinding::wasBound($appId, $userId, $quoteNumber)
                    ? 'This quote has expired, please search again'
                    : 'This quote was not issued for this account, please search again'
            );
        }

        $bound = InsurancePlan::query()->with('insurer')->find($boundPlanId);

        if (
            $bound === null
            || $bound->insurer->provider !== $plan->insurer->provider
            || $bound->external_code !== $plan->external_code
        ) {
            throw new InsuranceValidationException('This quote belongs to a different plan');
        }
    }

    private function assertQuoteNotContracted(): void
    {
        $contracted = Order::query()
            ->fromApp($this->order->app)
            ->where('users_id', $this->order->users_id)
            ->where('id', '!=', $this->order->getId())
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.data.quote_number')) = ?", [$this->data->quoteNumber])
            ->get()
            ->contains(fn (Order $other): bool => $this->carriesLiveInsurance($other));

        if ($contracted) {
            throw new InsuranceValidationException('This quote has already been contracted');
        }
    }

    private function carriesLiveInsurance(Order $other): bool
    {
        if (in_array(InsuranceStatus::of($other), [InsuranceStatusEnum::FAILED, InsuranceStatusEnum::CANCELLED], true)) {
            return false;
        }

        return InsuranceStatus::hasQuote($other);
    }

    private function resolver(): InsurancePlanProductResolver
    {
        return InsurancePlanProductResolver::forActing($this->order->app, $this->order->company);
    }
}
