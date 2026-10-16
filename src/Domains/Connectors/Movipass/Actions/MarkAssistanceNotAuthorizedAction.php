<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Actions;

use Illuminate\Support\Carbon;
use Kanvas\Connectors\Movipass\Concerns\InteractsWithAssistanceCase;
use Kanvas\Connectors\Movipass\Enums\MovipassOrderStatusEnum;
use Kanvas\Connectors\Movipass\Enums\RoadsideNotProceedReasonEnum;
use Kanvas\Connectors\Movipass\Events\RefreshActiveAssistanceEvent;
use Kanvas\Connectors\Movipass\Notifications\RoadsideAssistanceStatusNotification;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Users\Models\Users;

/**
 * The authorization gate answered No: the operator declines the case and tells the client why. No
 * provider is assigned and nothing is dispatched, so this is a different ending from a cancellation
 * of an accepted case.
 */
class MarkAssistanceNotAuthorizedAction
{
    use InteractsWithAssistanceCase;

    public function __construct(
        private readonly Order $order,
        private readonly Users $user,
        private readonly RoadsideNotProceedReasonEnum $reason,
        private readonly ?string $notes = null,
    ) {
    }

    public function execute(): Order
    {
        $currentStatus = $this->order->orderStatus?->slug;

        if ($this->isTerminalAssistanceStatus($currentStatus)) {
            throw new ValidationException(
                'Cannot decline a roadside assistance case that is already in a terminal status',
            );
        }

        // Declining after a provider is on the way would leave a dispatched unit with no case, so
        // the gate only applies while the case has not been handed to anyone. An order with no
        // status row is refused too: its transition would silently no-op and leave the metadata
        // claiming a decline that never happened.
        if (! $this->isAtAuthorizationGate($currentStatus)) {
            throw new ValidationException(sprintf(
                'Cannot decline a roadside assistance case in "%s"; cancel it instead',
                $currentStatus ?? 'no status',
            ));
        }

        $assistanceCase = $this->assistanceCaseFrom($this->order);
        $now = Carbon::now()->toISOString();

        unset($assistanceCase['not_authorized']);
        $assistanceCase['not_authorized_reason'] = $this->reason->value;
        $assistanceCase['not_authorized_notes'] = $this->notes;
        $assistanceCase['not_authorized_at'] = $now;
        $assistanceCase['status'] = MovipassOrderStatusEnum::SERVICE_NOT_AUTHORIZED->slug();
        $assistanceCase['status_updated_at'] = $now;
        $assistanceCase['pin_hash'] = null;
        $assistanceCase['pin_invalidated_at'] = $now;

        $this->saveAssistanceCase($this->order, $assistanceCase);

        $this->order->transitionToStatus(
            $this->user,
            MovipassOrderStatusEnum::SERVICE_NOT_AUTHORIZED->slug(),
        );
        $this->order->fulfillCancelled();

        RefreshActiveAssistanceEvent::dispatch($this->order, (int) $this->order->users_id);

        $this->notifySafely($this->user, new RoadsideAssistanceStatusNotification(
            $this->order,
            'Service not available',
            sprintf(
                'We could not process this roadside assistance request: %s.',
                $this->notes !== null && trim($this->notes) !== ''
                    ? trim($this->notes)
                    : $this->reason->label(),
            ),
            MovipassOrderStatusEnum::SERVICE_NOT_AUTHORIZED->slug(),
        ));

        return $this->order->refresh();
    }

    private function isAtAuthorizationGate(?string $statusSlug): bool
    {
        return in_array($statusSlug, [
            MovipassOrderStatusEnum::REQUEST_SUBMITTED->slug(),
            MovipassOrderStatusEnum::AWAITING_OPERATOR->slug(),
        ], true);
    }
}
