<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Actions;

use Illuminate\Support\Carbon;
use Kanvas\Connectors\Movipass\Concerns\InteractsWithAssistanceCase;
use Kanvas\Connectors\Movipass\Events\RefreshActiveAssistanceEvent;
use Kanvas\Connectors\Movipass\Notifications\RoadsideAssistanceStatusNotification;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Users\Models\Users;

/**
 * The "service did not succeed" branch: record why the service failed and park the case on the
 * reschedule decision. The order status is deliberately left alone — the case stays open until
 * someone decides whether it can be rescheduled, which is what RescheduleAssistanceAction and
 * CloseAssistanceWithIncidentAction do.
 */
class RegisterAssistanceIncidentAction
{
    use InteractsWithAssistanceCase;

    public function __construct(
        private readonly Order $order,
        private readonly Users $reportedBy,
        private readonly string $reason,
    ) {
    }

    public function execute(): array
    {
        $reason = trim($this->reason);

        if ($reason === '') {
            throw new ValidationException('An incident reason is required');
        }

        if ($this->isTerminalAssistanceStatus($this->order->orderStatus?->slug)) {
            throw new ValidationException(
                'Cannot register an incident on a roadside assistance case that is already closed',
            );
        }

        $assistanceCase = $this->assistanceCaseFrom($this->order);
        $now = Carbon::now()->toISOString();

        $incident = [
            'reason' => $reason,
            'registered_at' => $now,
            'registered_by_users_id' => $this->reportedBy->getId(),
            'mechanic_users_id' => (int) ($assistanceCase['mechanic']['user_id'] ?? 0) ?: null,
            'status_at_incident' => $this->order->orderStatus?->slug,
            'rescheduled_at' => null,
        ];

        $incidents = is_array($assistanceCase['incidents'] ?? null) ? $assistanceCase['incidents'] : [];
        $incidents[] = $incident;

        unset($assistanceCase['register_incident'], $assistanceCase['incident_reason']);
        $assistanceCase['incidents'] = $incidents;
        $assistanceCase['pending_reschedule_decision'] = true;
        $assistanceCase['status_updated_at'] = $now;

        $this->saveAssistanceCase($this->order, $assistanceCase);

        RefreshActiveAssistanceEvent::dispatch($this->order, (int) $this->order->users_id);

        $this->notifySafely(
            $this->resolveCaseUser($this->order->users_id),
            new RoadsideAssistanceStatusNotification(
                $this->order,
                'Service incident registered',
                'We hit a problem completing your roadside assistance service. We are reviewing whether it can be rescheduled.',
                $this->order->orderStatus?->slug ?? '',
            ),
        );

        return $incident;
    }
}
