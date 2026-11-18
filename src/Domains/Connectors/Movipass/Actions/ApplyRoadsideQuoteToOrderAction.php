<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Actions;

use Kanvas\Connectors\Movipass\Concerns\InteractsWithAssistanceCase;
use Kanvas\Connectors\Movipass\Enums\OrderTypeEnum;
use Kanvas\Connectors\Movipass\Enums\RoadsideServiceTypeEnum;
use Kanvas\Connectors\Movipass\Services\RoadsideQuoteCalculator;
use Kanvas\Connectors\Movipass\Support\RegionalAssistance;
use Kanvas\Connectors\Movipass\Support\RoadsideIntakeRules;
use Kanvas\Connectors\Movipass\Support\RoadsideQuote;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;

/**
 * The cart price is whatever the client put in it, so the order is repriced from the tariff sheet.
 *
 * Every call recomputes instead of trusting a stored quote: the quote copy lives in metadata, which
 * the client can write, so "already priced" can never be read from there. Pricing at the order's
 * creation time keeps the recompute deterministic, which is what lets the workflow, the pre-payment
 * mutation and the payment guard all call this safely in any order.
 */
class ApplyRoadsideQuoteToOrderAction
{
    use InteractsWithAssistanceCase;

    public function __construct(
        private readonly Order $order,
    ) {
    }

    public function execute(): ?RoadsideQuote
    {
        if (! $this->appliesTo()) {
            return null;
        }

        $assistanceCase = $this->assistanceCaseFrom($this->order);
        $service = RoadsideServiceTypeEnum::tryFrom((string) ($assistanceCase['service_type'] ?? ''));

        if ($service === null) {
            throw new ValidationException('A recognised service_type is required to price a roadside assistance case');
        }

        $verdict = RoadsideIntakeRules::evaluate(
            $service,
            is_array($assistanceCase['intake'] ?? null) ? $assistanceCase['intake'] : [],
        );

        if (! $verdict->proceeds()) {
            throw new ValidationException(sprintf(
                'The %s service cannot be provided for this case (%s)%s',
                $service->label(),
                implode(', ', $verdict->reasons),
                $verdict->recommendedService !== null
                    ? '. Recommended service: ' . $verdict->recommendedService->value
                    : '',
            ));
        }

        $assistanceCase['intake_verdict'] = $verdict->toArray();

        $quote = new RoadsideQuoteCalculator(RegionalAssistance::tariffsFor($this->order))->quote(
            service: $service,
            location: is_array($assistanceCase['location'] ?? null) ? $assistanceCase['location'] : [],
            destination: is_array($assistanceCase['destination'] ?? null) ? $assistanceCase['destination'] : null,
            moment: $this->order->created_at,
        );

        $assistanceCase['quote'] = $quote->toArray();
        $this->saveAssistanceCase($this->order, $assistanceCase);

        $this->reprice($quote);

        return $quote;
    }

    /**
     * Outside Asistencia Regional the order keeps the cart price exactly as before. A paid order
     * keeps the price it was paid at; repricing it would put the order and the money out of step.
     */
    private function appliesTo(): bool
    {
        return $this->order->parent_id === null
            && $this->order->orderType?->name === OrderTypeEnum::ROADSIDE_ASSISTANCE->value
            && $this->order->payment_status !== PaymentStatusEnum::PAID->value
            && RegionalAssistance::isEnabledFor($this->order);
    }

    /**
     * The whole tax-inclusive total sits on the service line because Order::calculateTotal() sums
     * item prices and never adds tax on top; splitting it any other way gets undone the next time
     * anything recalculates the order.
     */
    private function reprice(RoadsideQuote $quote): void
    {
        $items = $this->order->items()->orderBy('id')->get();
        $serviceLine = $items->shift();

        if ($serviceLine === null) {
            throw new ValidationException('Roadside assistance order has no service line to price');
        }

        $serviceLine->quantity = 1;
        $serviceLine->unit_price_net_amount = $quote->price->total;
        $serviceLine->unit_price_gross_amount = $quote->price->total;
        $serviceLine->saveOrFail();

        $items->each(fn ($item) => $item->update([
            'unit_price_net_amount' => 0,
            'unit_price_gross_amount' => 0,
        ]));

        $this->order->total_gross_amount = $quote->price->total;
        $this->order->total_net_amount = $quote->price->total;
        $this->order->tax_amount = $quote->price->taxAmount;
        $this->order->discount_amount = 0;
        $this->order->saveQuietly();
    }
}
