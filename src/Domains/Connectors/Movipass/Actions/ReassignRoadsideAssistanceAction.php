<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Actions;

use Baka\Support\Str;
use Kanvas\Connectors\Movipass\Concerns\InteractsWithAssistanceCase;
use Kanvas\Connectors\Movipass\Enums\MovipassOrderStatusEnum;
use Kanvas\Connectors\Movipass\Enums\RoadsideAuditChannelEnum;
use Kanvas\Connectors\Movipass\Enums\RoadsideCaseStateEnum;
use Kanvas\Connectors\Movipass\Services\RoadsideAuditTrail;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Users\Models\Users;

/**
 * The cabin intervenes on a case (spec v6.0 §10.6): it can narrow who may take it, take the current
 * technician off it, and either hand it to a specific technician or send it back to the pool. Every
 * step needs a reason and is audited.
 */
class ReassignRoadsideAssistanceAction
{
    use InteractsWithAssistanceCase;

    /**
     * @param array<int, int>|null $eligibleTechnicianIds null keeps the current shortlist, [] clears it
     */
    public function __construct(
        private readonly Order $order,
        private readonly Users $operator,
        private readonly string $reason,
        private readonly ?Users $technician = null,
        private readonly ?array $eligibleTechnicianIds = null,
    ) {
    }

    public function execute(): Order
    {
        $reason = Str::trimToNull($this->reason);

        if ($reason === null) {
            throw new ValidationException('A reason is required to reassign a case');
        }

        $assistanceCase = $this->assistanceCaseFrom($this->order);
        $state = RoadsideCaseStateEnum::tryFrom((string) ($assistanceCase['state'] ?? ''));

        if ($state === null || $state->isTerminal()) {
            throw new ValidationException('Only an open, dispatched case can be reassigned');
        }

        if ($this->eligibleTechnicianIds !== null) {
            $assistanceCase['eligible_mechanic_ids'] = array_values(array_unique(array_map('intval', $this->eligibleTechnicianIds)));
            $this->saveAssistanceCase($this->order, $assistanceCase);
        }

        RoadsideAuditTrail::forOrder(
            order: $this->order,
            module: 'technician',
            action: 'cabin_reassignment',
            actor: $this->operator,
            before: ['technician_id' => $assistanceCase['mechanic']['user_id'] ?? null],
            after: [
                'technician_id' => $this->technician?->getId(),
                'eligible_ids' => $assistanceCase['eligible_mechanic_ids'] ?? [],
            ],
            reason: $reason,
            channel: RoadsideAuditChannelEnum::WEB,
        );

        $current = $this->resolveCaseUser($assistanceCase['mechanic']['user_id'] ?? 0);

        if ($current instanceof Users) {
            new TransitionRoadsideCaseStateAction(
                order: $this->order,
                to: RoadsideCaseStateEnum::REASSIGNMENT,
                actor: $this->operator,
                channel: RoadsideAuditChannelEnum::WEB,
                reason: $reason,
            )->execute();

            // Releasing re-offers the case to the pool, which is what a reassignment without a
            // named technician wants.
            new CancelMechanicAssignmentAction($this->order, $current, $this->order->app)->execute();
            $this->order->refresh();
        } elseif ($this->technician === null) {
            new OfferRoadsideCaseAction($this->order, $this->operator, RoadsideAuditChannelEnum::WEB)
                ->execute();
        }

        if ($this->technician !== null) {
            $this->assignDirectly();
        }

        return $this->order->refresh();
    }

    private function assignDirectly(): void
    {
        $this->order->refresh();

        if ($this->order->orderStatus?->slug === MovipassOrderStatusEnum::REQUEST_SUBMITTED->slug()) {
            $this->order->transitionToStatus($this->operator, MovipassOrderStatusEnum::AWAITING_OPERATOR->slug());
            $this->order->refresh();
        }

        new AssignMechanicToOrderAction($this->order, $this->operator, $this->technician)->execute();
    }
}
