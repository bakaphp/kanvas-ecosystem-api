<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Actions;

use Kanvas\Connectors\Movipass\Concerns\InteractsWithAssistanceCase;
use Kanvas\Connectors\Movipass\Enums\RoadsideCaseStateEnum;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Souk\Orders\Models\Order;

/**
 * The shared roadside flow (assignment, arrival, PIN, cancel, reschedule, close) moves the order
 * status; this keeps the homologated case state in step with it. A move the state map refuses is
 * already audited by the transition and never fails the workflow run: the order status is the
 * committed fact, the state is its reading.
 */
class SyncRoadsideCaseStateFromOrderStatusAction
{
    use InteractsWithAssistanceCase;

    public function __construct(
        private readonly Order $order,
        private readonly string $statusSlug,
    ) {
    }

    public function execute(): ?RoadsideCaseStateEnum
    {
        $this->order->refresh();
        $assistanceCase = $this->assistanceCaseFrom($this->order);

        // A case that was never dispatched (unpaid) has no state to follow yet.
        if (empty($assistanceCase['state'])) {
            return null;
        }

        $current = RoadsideCaseStateEnum::tryFrom((string) $assistanceCase['state']);
        $target = RoadsideCaseStateEnum::forOrderStatus($this->statusSlug, $current);

        if ($target === null) {
            return null;
        }

        try {
            new TransitionRoadsideCaseStateAction(
                order: $this->order,
                to: $target,
                reason: 'order status ' . $this->statusSlug,
            )->execute();
        } catch (ValidationException) {
            return null;
        }

        return $target;
    }
}
