<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Actions;

use Illuminate\Support\Carbon;
use Kanvas\Connectors\Movipass\Concerns\InteractsWithAssistanceCase;
use Kanvas\Connectors\Movipass\Enums\ConfigurationEnum;
use Kanvas\Connectors\Movipass\Enums\MovipassOrderStatusEnum;
use Kanvas\Connectors\Movipass\Events\RefreshActiveAssistanceEvent;
use Kanvas\Connectors\Movipass\Jobs\RetryNotifyMechanicsJob;
use Kanvas\Connectors\Movipass\Notifications\RoadsideAssistanceStatusNotification;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Users\Models\Users;

/**
 * The "can be rescheduled" branch: the case goes back to the assignment stage so a new provider can
 * be dispatched, rather than closing as a failure.
 */
class RescheduleAssistanceAction
{
    use InteractsWithAssistanceCase;

    public const DEFAULT_MAX_RESCHEDULES = 3;

    public function __construct(
        private readonly Order $order,
        private readonly Users $requestedBy,
        private readonly ?string $notes = null,
    ) {
    }

    public function execute(): Order
    {
        $assistanceCase = $this->assistanceCaseFrom($this->order);

        if ($this->isTerminalAssistanceStatus($this->order->orderStatus?->slug)) {
            throw new ValidationException(
                'Cannot reschedule a roadside assistance case that is already closed',
            );
        }

        // Rescheduling is the answer to a registered incident. Without one there is nothing to
        // reschedule away from, and allowing it would let a caller bounce a healthy case in a loop.
        if (($assistanceCase['pending_reschedule_decision'] ?? false) !== true) {
            throw new ValidationException(
                'Cannot reschedule: no incident is awaiting a reschedule decision on this case',
            );
        }

        $rescheduleCount = (int) ($assistanceCase['reschedule_count'] ?? 0);
        $maxReschedules = $this->maxReschedules();

        if ($rescheduleCount >= $maxReschedules) {
            throw new ValidationException(sprintf(
                'This case has already been rescheduled %d times; close it with the incident instead',
                $rescheduleCount,
            ));
        }

        $now = Carbon::now()->toISOString();
        $assistanceCase['incidents'] = $this->markLatestIncidentRescheduled(
            is_array($assistanceCase['incidents'] ?? null) ? $assistanceCase['incidents'] : [],
            $now,
        );
        $assistanceCase['reschedule_count'] = $rescheduleCount + 1;
        $assistanceCase['rescheduled_at'] = $now;
        $assistanceCase['reschedule_notes'] = $this->notes;
        $assistanceCase['status_updated_at'] = $now;
        unset($assistanceCase['reschedule'], $assistanceCase['pending_reschedule_decision']);

        // Persist before releasing the mechanic: CancelMechanicAssignmentAction re-reads the order
        // inside its own transaction, so the incident bookkeeping has to already be committed.
        $this->saveAssistanceCase($this->order, $assistanceCase);

        $mechanic = $this->resolveCaseUser($assistanceCase['mechanic']['user_id'] ?? 0);

        if ($mechanic instanceof Users) {
            return new CancelMechanicAssignmentAction(
                $this->order,
                $mechanic,
                $this->order->app,
            )->execute();
        }

        return $this->returnToAssignmentPool();
    }

    /**
     * No mechanic was ever assigned (the incident happened at the dispatch stage), so there is
     * nothing to release — just put the case back in front of the available mechanics.
     */
    private function returnToAssignmentPool(): Order
    {
        $this->order->transitionToStatus(
            $this->requestedBy,
            MovipassOrderStatusEnum::REQUEST_SUBMITTED->slug(),
        );

        RefreshActiveAssistanceEvent::dispatch($this->order, (int) $this->order->users_id);

        $this->notifySafely(
            $this->resolveCaseUser($this->order->users_id),
            new RoadsideAssistanceStatusNotification(
                $this->order,
                'Service rescheduled',
                'Your roadside assistance service was rescheduled. We are looking for a new provider.',
                MovipassOrderStatusEnum::REQUEST_SUBMITTED->slug(),
            ),
        );

        try {
            new NotifyAvailableMechanicsAction(
                $this->order,
                $this->order->app,
                $this->requestedBy,
                $this->order->metadata['assistance_case']['cancelled_mechanic_ids'] ?? [],
            )->execute();
        } catch (ValidationException) {
            RetryNotifyMechanicsJob::dispatch(
                $this->order,
                $this->requestedBy,
                $this->order->metadata['assistance_case']['cancelled_mechanic_ids'] ?? [],
                attempt: 1,
                maxAttempts: 3,
                retryDelayMinutes: 1,
            )->delay(now()->addMinutes(1));
        }

        return $this->order->refresh();
    }

    private function markLatestIncidentRescheduled(array $incidents, string $timestamp): array
    {
        for ($index = count($incidents) - 1; $index >= 0; $index--) {
            if (($incidents[$index]['rescheduled_at'] ?? null) === null) {
                $incidents[$index]['rescheduled_at'] = $timestamp;

                break;
            }
        }

        return $incidents;
    }

    private function maxReschedules(): int
    {
        $configured = (int) ($this->order->app->get(ConfigurationEnum::ROADSIDE_MAX_RESCHEDULES->value) ?? 0);

        return $configured > 0 ? $configured : self::DEFAULT_MAX_RESCHEDULES;
    }
}
