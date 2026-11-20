<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Actions;

use Illuminate\Support\Carbon;
use Kanvas\Connectors\Movipass\Concerns\InteractsWithAssistanceCase;
use Kanvas\Connectors\Movipass\Enums\RoadsideAuditChannelEnum;
use Kanvas\Connectors\Movipass\Enums\RoadsideCaseStateEnum;
use Kanvas\Connectors\Movipass\Jobs\SyncRoadsideRegionalEventJob;
use Kanvas\Connectors\Movipass\Notifications\RoadsideAssistanceStatusNotification;
use Kanvas\Connectors\Movipass\Services\RoadsideAuditTrail;
use Kanvas\Connectors\Movipass\Support\RoadsideEvidenceRules;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Users\Models\Users;

/**
 * The one way an Asistencia Regional case changes state (spec v6.0 §12): the move must be declared
 * in RoadsideCaseStateEnum::next(), nothing reaches IN_PROGRESS without a validated PIN, nothing
 * closes as COMPLETED without the evidence and the operative comment, and every move records who,
 * from where, when, the previous and new state and the reason.
 *
 * Moving to the state the case is already in is a no-op returning false, which is what keeps a
 * jittering GPS from pushing "your technician is nearby" twice.
 */
class TransitionRoadsideCaseStateAction
{
    use InteractsWithAssistanceCase;

    public function __construct(
        private readonly Order $order,
        private readonly RoadsideCaseStateEnum $to,
        private readonly ?Users $actor = null,
        private readonly RoadsideAuditChannelEnum $channel = RoadsideAuditChannelEnum::SYSTEM,
        private readonly ?string $reason = null,
    ) {
    }

    public function execute(): bool
    {
        $assistanceCase = $this->assistanceCaseFrom($this->order);
        $from = RoadsideCaseStateEnum::tryFrom((string) ($assistanceCase['state'] ?? ''));

        if ($from === $this->to) {
            return false;
        }

        $this->guard($from, $assistanceCase);

        $now = Carbon::now()->toISOString();

        if ($this->to->isInterruption() && $from !== null && ! $from->isInterruption()) {
            $assistanceCase['interrupted_state'] = $from->value;
        } elseif (! $this->to->isInterruption()) {
            unset($assistanceCase['interrupted_state']);
        }

        $assistanceCase['state'] = $this->to->value;
        $assistanceCase['state_label'] = $this->to->userLabel();
        $assistanceCase['state_updated_at'] = $now;
        $assistanceCase['state_entered_at'] = [
            ...(array) ($assistanceCase['state_entered_at'] ?? []),
            $this->to->value => $assistanceCase['state_entered_at'][$this->to->value] ?? $now,
        ];
        $assistanceCase['state_history'] = [
            ...(array) ($assistanceCase['state_history'] ?? []),
            [
                'from' => $from?->value,
                'to' => $this->to->value,
                'at' => $now,
                'actor_id' => $this->actor?->getId(),
                'channel' => $this->channel->value,
                'reason' => $this->reason,
            ],
        ];

        $this->saveAssistanceCase($this->order, $assistanceCase);

        RoadsideAuditTrail::forOrder(
            order: $this->order,
            module: 'order',
            action: 'state_changed',
            actor: $this->actor,
            before: ['state' => $from?->value],
            after: ['state' => $this->to->value],
            reason: $this->reason,
            channel: $this->channel,
        );

        SyncRoadsideRegionalEventJob::dispatchFor($this->order, 'state', [
            'from' => $from?->value,
            'to' => $this->to->value,
            'reason' => $this->reason,
        ]);

        // The provider's contact body is whatever the integration stored, never a guessed mapping.
        $contact = $assistanceCase['provider_contact'] ?? null;
        if ($this->to === RoadsideCaseStateEnum::TECHNICIAN_ASSIGNED && is_array($contact) && $contact !== []) {
            SyncRoadsideRegionalEventJob::dispatchFor($this->order, 'contact', $contact);
        }

        if ($this->to->notifiesUser()) {
            $this->notifySafely($this->resolveCaseUser($this->order->users_id), new RoadsideAssistanceStatusNotification(
                $this->order,
                $this->to->userLabel(),
                $this->to->userLabel(),
                strtolower($this->to->value),
            ));
        }

        return true;
    }

    private function guard(?RoadsideCaseStateEnum $from, array $assistanceCase): void
    {
        if ($from !== null && ! $this->isAllowedFrom($from, $assistanceCase)) {
            $this->reject(sprintf('A case in %s cannot move to %s', $from->value, $this->to->value));
        }

        if ($this->to === RoadsideCaseStateEnum::IN_PROGRESS && empty($assistanceCase['pin_validated_at'])) {
            $this->reject('The service cannot start before the PIN is validated');
        }

        if ($this->to === RoadsideCaseStateEnum::COMPLETED) {
            $missing = RoadsideEvidenceRules::missingForClosing($assistanceCase);

            if ($missing !== []) {
                $this->reject('The case cannot be closed: ' . implode('; ', $missing));
            }
        }

        if (in_array($this->to, [RoadsideCaseStateEnum::NOT_RESOLVED, RoadsideCaseStateEnum::CANCELLED], true)
            && trim((string) $this->reason) === ''
        ) {
            $this->reject('A reason is required to move a case to ' . $this->to->value);
        }
    }

    private function isAllowedFrom(RoadsideCaseStateEnum $from, array $assistanceCase): bool
    {
        if ($from->canMoveTo($this->to)) {
            return true;
        }

        if ($this->to->isInterruption() && ! $from->isTerminal()) {
            return true;
        }

        return $from->isInterruption()
            && ($assistanceCase['interrupted_state'] ?? null) === $this->to->value;
    }

    /**
     * A refused move is recorded too: §11 lists rejected attempts among the audited results.
     */
    private function reject(string $message): never
    {
        RoadsideAuditTrail::forOrder(
            order: $this->order,
            module: 'order',
            action: 'state_change_rejected',
            actor: $this->actor,
            after: ['state' => $this->to->value],
            reason: $message,
            channel: $this->channel,
            succeeded: false,
        );

        throw new ValidationException($message);
    }
}
