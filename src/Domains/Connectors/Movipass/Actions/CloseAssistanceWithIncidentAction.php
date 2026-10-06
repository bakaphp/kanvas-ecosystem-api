<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Actions;

use Illuminate\Support\Carbon;
use Kanvas\Connectors\Movipass\Concerns\InteractsWithAssistanceCase;
use Kanvas\Connectors\Movipass\Enums\MovipassOrderStatusEnum;
use Kanvas\Connectors\Movipass\Events\RefreshActiveAssistanceEvent;
use Kanvas\Connectors\Movipass\Notifications\RoadsideAssistanceStatusNotification;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Users\Models\Users;

/**
 * The "cannot be rescheduled" branch: the case is closed with the incident. It ends unresolved,
 * which is the same terminal state as an unresolved completion, flagged so reporting can tell an
 * incident closure from a service the mechanic simply could not fix.
 */
class CloseAssistanceWithIncidentAction
{
    use InteractsWithAssistanceCase;

    public function __construct(
        private readonly Order $order,
        private readonly Users $closedBy,
        private readonly ?string $notes = null,
    ) {
    }

    public function execute(): Order
    {
        if ($this->isTerminalAssistanceStatus($this->order->orderStatus?->slug)) {
            throw new ValidationException(
                'Cannot close a roadside assistance case that is already in a terminal status',
            );
        }

        $assistanceCase = $this->assistanceCaseFrom($this->order);

        if (($assistanceCase['incidents'] ?? []) === []) {
            throw new ValidationException(
                'Cannot close with an incident: no incident has been registered on this case',
            );
        }

        $now = Carbon::now()->toISOString();
        $targetStatus = MovipassOrderStatusEnum::SERVICE_COMPLETED_NOT_RESOLVED->slug();

        unset($assistanceCase['close_with_incident'], $assistanceCase['pending_reschedule_decision']);
        $assistanceCase['closed_with_incident'] = true;
        $assistanceCase['close_notes'] = $this->notes;
        $assistanceCase['completed_at'] = $now;
        $assistanceCase['resolved'] = false;
        $assistanceCase['status'] = $targetStatus;
        $assistanceCase['status_updated_at'] = $now;
        $assistanceCase['pin_hash'] = null;
        $assistanceCase['pin_invalidated_at'] = $now;

        $this->saveAssistanceCase($this->order, $assistanceCase);

        $this->order->transitionToStatus($this->closedBy, $targetStatus);
        $this->order->fulfill();

        $mechanicId = (int) ($assistanceCase['mechanic']['user_id'] ?? 0);
        RefreshActiveAssistanceEvent::dispatch(
            $this->order,
            $mechanicId > 0 ? $mechanicId : (int) $this->order->users_id,
        );

        $notification = new RoadsideAssistanceStatusNotification(
            $this->order,
            'Service closed with an incident',
            'Your roadside assistance case was closed without resolution. Our team will follow up.',
            $targetStatus,
        );

        $this->notifySafely($this->resolveCaseUser($this->order->users_id), $notification);
        $this->notifySafely($this->resolveCaseUser($mechanicId), $notification);

        return $this->order->refresh();
    }
}
