<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Actions;

use Illuminate\Support\Facades\Cache;
use Kanvas\Connectors\Movipass\Concerns\InteractsWithAssistanceCase;
use Kanvas\Connectors\Movipass\Enums\OrderTypeEnum;
use Kanvas\Connectors\Movipass\Enums\RoadsideCaseStateEnum;
use Kanvas\Connectors\Movipass\Enums\RoadsideIntakeOutcomeEnum;
use Kanvas\Connectors\Movipass\Services\RoadsideAuditTrail;
use Kanvas\Connectors\Movipass\Support\RegionalAssistance;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;

/**
 * Spec v6.0 §6.1: an Asistencia Regional order only becomes a service once its payment is
 * approved. The payment and the case preparation (the CREATED workflow) can land in either order,
 * so both call this and the second one to arrive dispatches: a held case goes to the cabin, any
 * other is offered to the technicians.
 */
class DispatchRegionalRoadsideCaseAction
{
    use InteractsWithAssistanceCase;

    public function __construct(
        private readonly Order $order,
    ) {
    }

    public function execute(): bool
    {
        if (! $this->appliesTo()) {
            return false;
        }

        return (bool) Cache::lock('movipass-roadside-dispatch-' . $this->order->getId(), 30)->get(function () {
            $this->order->refresh();
            $assistanceCase = $this->assistanceCaseFrom($this->order);

            if (! empty($assistanceCase['state']) || empty($assistanceCase['prepared_at'])
                || $this->order->payment_status !== PaymentStatusEnum::PAID->value
            ) {
                return false;
            }

            new TransitionRoadsideCaseStateAction($this->order, RoadsideCaseStateEnum::CREATED, $this->order->user)->execute();

            RoadsideAuditTrail::forOrder(
                order: $this->order,
                module: 'payment',
                action: 'initial_payment_approved',
                actor: $this->order->user,
                after: ['total' => (float) $this->order->total_net_amount],
            );

            $outcome = $assistanceCase['intake_verdict']['outcome'] ?? null;

            if ($outcome === RoadsideIntakeOutcomeEnum::CABIN_REVIEW->value) {
                new TransitionRoadsideCaseStateAction(
                    order: $this->order,
                    to: RoadsideCaseStateEnum::CABIN_REVIEW,
                    reason: implode(', ', (array) ($assistanceCase['intake_verdict']['reasons'] ?? [])),
                )->execute();

                return true;
            }

            new OfferRoadsideCaseAction($this->order, $this->order->user)->execute();

            return true;
        });
    }

    private function appliesTo(): bool
    {
        return $this->order->parent_id === null
            && $this->order->orderType?->name === OrderTypeEnum::ROADSIDE_ASSISTANCE->value
            && RegionalAssistance::isEnabledFor($this->order);
    }
}
