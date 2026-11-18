<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Actions;

use Baka\Support\Str;
use Illuminate\Support\Carbon;
use Kanvas\Connectors\Movipass\Concerns\InteractsWithAssistanceCase;
use Kanvas\Connectors\Movipass\Enums\OrderTypeEnum;
use Kanvas\Connectors\Movipass\Enums\RoadsideAdditionalChargeConceptEnum;
use Kanvas\Connectors\Movipass\Enums\RoadsideAdditionalChargeStatusEnum;
use Kanvas\Connectors\Movipass\Enums\RoadsideServiceTypeEnum;
use Kanvas\Connectors\Movipass\Events\RefreshActiveAssistanceEvent;
use Kanvas\Connectors\Movipass\Notifications\RoadsideAssistanceStatusNotification;
use Kanvas\Connectors\Movipass\Services\RoadsideQuoteCalculator;
use Kanvas\Connectors\Movipass\Support\RegionalAssistance;
use Kanvas\Connectors\Movipass\Support\RoadsidePriceBreakdown;
use Kanvas\Currencies\Models\Currencies;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Souk\Orders\Actions\CreateOrderAction;
use Kanvas\Souk\Orders\DataTransferObject\Order as OrderData;
use Kanvas\Souk\Orders\DataTransferObject\OrderItem;
use Kanvas\Souk\Orders\Enums\OrderStatusEnum;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Users\Models\Users;
use Spatie\LaravelData\DataCollection;

/**
 * The base service is already paid when the technician learns what the fuel really costs, so the
 * cabin bills it as a child order: the user pays it through the normal card flow and the case only
 * tracks whether it is settled. The technician must not pump fuel until it reads approved.
 */
class AddRoadsideAdditionalChargeAction
{
    use InteractsWithAssistanceCase;

    public const string PENDING_PAYMENT_EVENT = 'additional_payment_pending';

    public function __construct(
        private readonly Order $order,
        private readonly Users $registeredBy,
        private readonly RoadsideAdditionalChargeConceptEnum $concept,
        private readonly float $amount,
        private readonly ?string $notes = null,
    ) {
    }

    public function execute(): array
    {
        $assistanceCase = $this->assistanceCaseFrom($this->order);

        $this->validate($assistanceCase);

        $price = new RoadsideQuoteCalculator(RegionalAssistance::tariffsFor($this->order))->priceAdditionalCharge(
            $this->amount,
            $this->order->created_at,
        );

        $chargeOrder = $this->createChargeOrder($price);

        $charge = [
            'id' => (string) Str::uuid(),
            'concept' => $this->concept->value,
            'amount' => round($this->amount, 2),
            ...$price->toArray(),
            'status' => RoadsideAdditionalChargeStatusEnum::PENDING->value,
            'order_id' => $chargeOrder->getId(),
            'order_uuid' => $chargeOrder->uuid,
            'notes' => Str::trimToNull($this->notes),
            'registered_by_users_id' => $this->registeredBy->getId(),
            'registered_at' => Carbon::now()->toISOString(),
            'status_updated_at' => Carbon::now()->toISOString(),
        ];

        $assistanceCase['additional_charges'] = [
            ...(is_array($assistanceCase['additional_charges'] ?? null) ? $assistanceCase['additional_charges'] : []),
            $charge,
        ];
        $assistanceCase['additional_payment_pending'] = true;
        $this->saveAssistanceCase($this->order, $assistanceCase);

        $mechanicId = (int) ($assistanceCase['mechanic']['user_id'] ?? 0);
        RefreshActiveAssistanceEvent::dispatch($this->order, $mechanicId > 0 ? $mechanicId : (int) $this->order->users_id);

        $this->notifySafely(
            $this->resolveCaseUser($this->order->users_id),
            new RoadsideAssistanceStatusNotification(
                $this->order,
                'Additional payment pending',
                sprintf(
                    'A %s charge of %s %.2f was added to your order. Pay it with your card so the technician can continue.',
                    strtolower($this->concept->label()),
                    $chargeOrder->currency,
                    $price->total,
                ),
                self::PENDING_PAYMENT_EVENT,
            ),
        );

        return $charge;
    }

    private function validate(array $assistanceCase): void
    {
        if (! RegionalAssistance::isEnabledFor($this->order)) {
            throw new ValidationException('Additional charges are only available for Asistencia Regional cases');
        }

        if ($this->order->parent_id !== null) {
            throw new ValidationException('Additional charges are added to the assistance order, not to another charge');
        }

        if ($this->order->orderType?->name !== OrderTypeEnum::ROADSIDE_ASSISTANCE->value) {
            throw new ValidationException('Additional charges only apply to roadside assistance orders');
        }

        if ($this->isTerminalAssistanceStatus($this->order->orderStatus?->slug)) {
            throw new ValidationException('Cannot add a charge to a roadside assistance case that is already closed');
        }

        if ($this->amount <= 0) {
            throw new ValidationException('The additional charge amount must be greater than zero');
        }

        $service = RoadsideServiceTypeEnum::tryFrom((string) ($assistanceCase['service_type'] ?? ''));

        if (! $this->concept->appliesTo($service)) {
            throw new ValidationException(sprintf(
                'A %s charge does not apply to a %s case',
                strtolower($this->concept->label()),
                $service?->label() ?? 'roadside assistance',
            ));
        }
    }

    /**
     * The charge reuses the service line's variant: Souk lines need one, and a dedicated product
     * per charge concept would be catalog clutter nobody can buy on their own.
     */
    private function createChargeOrder(RoadsidePriceBreakdown $price): Order
    {
        $serviceLine = $this->order->items()->orderBy('id')->first();

        if ($serviceLine?->variant === null) {
            throw new ValidationException('Roadside assistance order has no service line to bill the charge against');
        }

        $currency = Currencies::getByCode((string) $this->order->currency);

        $item = new OrderItem(
            app: $this->order->app,
            variant: $serviceLine->variant,
            name: $this->concept->label(),
            sku: (string) $serviceLine->variant->sku,
            quantity: 1,
            price: $price->total,
            tax: 0,
            discount: 0,
            currency: $currency,
            metadata: ['roadside_additional_charge' => $this->concept->value],
        );

        $chargeOrder = new CreateOrderAction(new OrderData(
            app: $this->order->app,
            region: $this->order->region,
            company: $this->order->company,
            people: $this->order->people,
            user: $this->order->user,
            token: Str::random(32),
            orderNumber: '',
            shippingAddress: null,
            billingAddress: null,
            // Tax is already inside the line price; passing it here would subtract it from the
            // amount due, since CreateOrderAction derives net = total - taxes.
            total: $price->total,
            taxes: 0.0,
            totalDiscount: 0.0,
            totalShipping: 0.0,
            status: OrderStatusEnum::COMPLETED->value,
            checkoutToken: '',
            currency: $currency,
            items: OrderItem::collect([$item], DataCollection::class),
            email: $this->order->user_email,
            metadata: ['data' => [
                'roadside_additional_charge' => [
                    'parent_order_id' => $this->order->getId(),
                    'concept' => $this->concept->value,
                ],
            ]],
            orderType: OrderTypeEnum::ROADSIDE_ADDITIONAL_CHARGE->value,
            phone: $this->order->user_phone,
            parent: $this->order,
        ))->disableWorkflow()->execute();

        $chargeOrder->tax_amount = $price->taxAmount;
        $chargeOrder->saveQuietly();

        return $chargeOrder;
    }
}
