<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Actions;

use Baka\Support\Str;
use Illuminate\Support\Carbon;
use Kanvas\Connectors\Movipass\Concerns\InteractsWithAssistanceCase;
use Kanvas\Connectors\Movipass\Enums\MovipassOrderStatusEnum;
use Kanvas\Connectors\Movipass\Enums\RoadsideAuditChannelEnum;
use Kanvas\Connectors\Movipass\Enums\RoadsideCaseStateEnum;
use Kanvas\Connectors\Movipass\Events\RefreshActiveAssistanceEvent;
use Kanvas\Connectors\Movipass\Notifications\RoadsideAssistanceStatusNotification;
use Kanvas\Connectors\Movipass\Support\RoadsideEvidenceRules;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Users\Models\Users;

/**
 * The technician closes the service from the APK (spec v6.0 §9.1): Resolved or Not resolved. Both
 * need the before/after photos and the operative comment, Not resolved needs a reason, and Resolved
 * is refused while an additional charge is unpaid. The close is final; Asistencia Regional skips the
 * user-confirmation step the shared flow uses, because the spec ends the case at the technician.
 */
class CloseRoadsideServiceAction
{
    use InteractsWithAssistanceCase;

    public function __construct(
        private readonly Order $order,
        private readonly Users $technician,
        private readonly bool $resolved,
        private readonly ?string $reason = null,
    ) {
    }

    public function execute(): Order
    {
        $assistanceCase = $this->assistanceCaseFrom($this->order);
        $reason = Str::trimToNull($this->reason);

        if ((int) ($assistanceCase['mechanic']['user_id'] ?? 0) !== $this->technician->getId()) {
            throw new ValidationException('You are not assigned to this order');
        }

        if (! $this->resolved && $reason === null) {
            throw new ValidationException('A reason is required to close the service as not resolved');
        }

        $missing = RoadsideEvidenceRules::missingForClosing($assistanceCase);
        if ($missing !== []) {
            throw new ValidationException('The service cannot be closed: ' . implode('; ', $missing));
        }

        if ($this->resolved && $this->hasOutstandingAdditionalCharges($assistanceCase)) {
            throw new ValidationException('Cannot close the case as resolved while an additional charge is unpaid');
        }

        $targetState = $this->resolved ? RoadsideCaseStateEnum::COMPLETED : RoadsideCaseStateEnum::NOT_RESOLVED;
        $targetStatus = $this->resolved
            ? MovipassOrderStatusEnum::SERVICE_COMPLETED->slug()
            : MovipassOrderStatusEnum::SERVICE_COMPLETED_NOT_RESOLVED->slug();

        // State first: it holds the guards, and a refusal there must leave the order untouched.
        new TransitionRoadsideCaseStateAction(
            order: $this->order,
            to: $targetState,
            actor: $this->technician,
            channel: RoadsideAuditChannelEnum::APK,
            reason: $reason,
        )->execute();

        $now = Carbon::now()->toISOString();
        $assistanceCase = $this->assistanceCaseFrom($this->order);
        $assistanceCase['completed_at'] = $now;
        $assistanceCase['resolved'] = $this->resolved;
        $assistanceCase['closing_reason'] = $reason;
        $assistanceCase['closed_by'] = $this->technician->getId();
        $assistanceCase['status'] = $targetStatus;
        $assistanceCase['status_updated_at'] = $now;
        $assistanceCase['pin_hash'] = null;
        $assistanceCase['pin_invalidated_at'] = $now;
        $this->saveAssistanceCase($this->order, $this->markStatusNotified($assistanceCase, $targetStatus));

        $this->order->transitionToStatus($this->technician, $targetStatus);
        $this->order->fulfill();

        RefreshActiveAssistanceEvent::dispatch($this->order, $this->technician->getId());

        $this->notifySafely($this->resolveCaseUser($this->order->users_id), new RoadsideAssistanceStatusNotification(
            $this->order,
            $targetState->userLabel(),
            $this->resolved
                ? 'The roadside assistance service has been completed successfully.'
                : 'The roadside assistance service has ended without resolution.',
            $targetStatus,
        ));

        return $this->order->refresh();
    }
}
