<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Actions;

use Kanvas\Connectors\Movipass\Concerns\InteractsWithAssistanceCase;
use Kanvas\Connectors\Movipass\Enums\RoadsideAuditChannelEnum;
use Kanvas\Connectors\Movipass\Enums\RoadsideCaseStateEnum;
use Kanvas\Connectors\Movipass\Services\RoadsideAuditTrail;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Users\Models\Users;

/**
 * First accept wins (spec v6.0 §10.6): AcceptOrderAssignmentAction locks the order row, so a second
 * technician tapping accept a moment later gets "no longer available" instead of a double booking.
 */
class AcceptRoadsideOfferAction
{
    use InteractsWithAssistanceCase;

    public function __construct(
        private readonly Order $order,
        private readonly Users $technician,
    ) {
    }

    public function execute(): Order
    {
        $assistanceCase = $this->assistanceCaseFrom($this->order);

        if (in_array($this->technician->getId(), array_map('intval', (array) ($assistanceCase['rejected_mechanic_ids'] ?? [])), true)) {
            throw new ValidationException('You already rejected this order');
        }

        $order = new AcceptOrderAssignmentAction($this->order, $this->technician)->execute();

        // The assignment is committed; a state the map refuses is audited by the transition.
        try {
            new TransitionRoadsideCaseStateAction(
                order: $order,
                to: RoadsideCaseStateEnum::TECHNICIAN_ASSIGNED,
                actor: $this->technician,
                channel: RoadsideAuditChannelEnum::APK,
            )->execute();
        } catch (ValidationException) {
        }

        RoadsideAuditTrail::forOrder(
            order: $order,
            module: 'technician',
            action: 'offer_accepted',
            actor: $this->technician,
            channel: RoadsideAuditChannelEnum::APK,
        );

        return $order;
    }
}
