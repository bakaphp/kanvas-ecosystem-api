<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Actions;

use Illuminate\Support\Carbon;
use Kanvas\Connectors\Movipass\Concerns\InteractsWithAssistanceCase;
use Kanvas\Connectors\Movipass\Enums\ConfigurationEnum;
use Kanvas\Connectors\Movipass\Enums\RoadsideAuditChannelEnum;
use Kanvas\Connectors\Movipass\Enums\RoadsideCaseStateEnum;
use Kanvas\Connectors\Movipass\Jobs\ExpireRoadsideOfferJob;
use Kanvas\Connectors\Movipass\Services\RoadsideAuditTrail;
use Kanvas\Connectors\Movipass\Services\RoadsideTechnicianPool;
use Kanvas\Connectors\Movipass\Support\RegionalAssistance;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Users\Models\Users;

/**
 * Direct dispatch to the APK (spec v6.0 §10.6): one offer round goes to every eligible technician,
 * the first to accept takes the case, and a round nobody answers expires and is offered again.
 * After the configured number of rounds the case stays in PENDING_ASSIGNMENT flagged for the cabin.
 *
 * Returns how many technicians were offered the case; zero is not an error, the expiry job keeps
 * looking until the rounds run out.
 */
class OfferRoadsideCaseAction
{
    use InteractsWithAssistanceCase;

    private const DEFAULT_TIMEOUT_SECONDS = 120;
    private const DEFAULT_MAX_ROUNDS = 5;

    public function __construct(
        private readonly Order $order,
        private readonly Users $requestedBy,
        private readonly RoadsideAuditChannelEnum $channel = RoadsideAuditChannelEnum::SYSTEM,
    ) {
    }

    public function execute(): int
    {
        $this->order->refresh();
        $assistanceCase = $this->assistanceCaseFrom($this->order);
        $round = (int) ($assistanceCase['offer']['round'] ?? 0) + 1;
        $maxRounds = (int) RegionalAssistance::parameter($this->order, ConfigurationEnum::ROADSIDE_OFFER_MAX_ROUNDS, self::DEFAULT_MAX_ROUNDS);

        if ($round > $maxRounds) {
            $this->flagForCabin($assistanceCase, 'No technician accepted after ' . $maxRounds . ' offer rounds');

            return 0;
        }

        $mechanics = new RoadsideTechnicianPool()->eligibleFor($this->order, $assistanceCase);
        $offered = 0;

        if ($mechanics->isNotEmpty()) {
            new NotifyAvailableMechanicsAction(
                order: $this->order,
                app: $this->order->app,
                user: $this->requestedBy,
                mechanics: $mechanics,
            )->execute();
            $offered = $mechanics->count();
        }

        $timeout = (int) RegionalAssistance::parameter($this->order, ConfigurationEnum::ROADSIDE_OFFER_TIMEOUT_SECONDS, self::DEFAULT_TIMEOUT_SECONDS);

        $this->order->refresh();
        $assistanceCase = $this->assistanceCaseFrom($this->order);
        $assistanceCase['offer'] = [
            'round' => $round,
            'offered_ids' => $mechanics->pluck('id')->all(),
            'offered_at' => Carbon::now()->toISOString(),
            'expires_at' => Carbon::now()->addSeconds($timeout)->toISOString(),
        ];

        if ($offered === 0) {
            $assistanceCase['assignment_alert'] = 'No eligible technician available (round ' . $round . ')';
        } else {
            unset($assistanceCase['assignment_alert']);
        }

        $this->saveAssistanceCase($this->order, $assistanceCase);

        $this->moveToPendingAssignment();

        RoadsideAuditTrail::forOrder(
            order: $this->order,
            module: 'technician',
            action: $offered === 0 ? 'offer_without_candidates' : 'offer_sent',
            actor: $this->requestedBy,
            after: ['round' => $round, 'offered_ids' => $mechanics->pluck('id')->all()],
            channel: $this->channel,
        );

        ExpireRoadsideOfferJob::dispatch($this->order->app, $this->order, $round)
            ->delay(now()->addSeconds(max($timeout, 30)));

        return $offered;
    }

    private function moveToPendingAssignment(): void
    {
        try {
            new TransitionRoadsideCaseStateAction(
                order: $this->order,
                to: RoadsideCaseStateEnum::PENDING_ASSIGNMENT,
                actor: $this->requestedBy,
                channel: $this->channel,
            )->execute();
        } catch (ValidationException) {
            // Already audited by the transition; a reoffer from REASSIGNMENT or INCIDENT that the
            // map does not allow keeps its state, the offer itself still went out.
        }
    }

    private function flagForCabin(array $assistanceCase, string $message): void
    {
        $assistanceCase['assignment_alert'] = $message;
        $this->saveAssistanceCase($this->order, $assistanceCase);

        RoadsideAuditTrail::forOrder(
            order: $this->order,
            module: 'technician',
            action: 'offer_rounds_exhausted',
            reason: $message,
            channel: $this->channel,
            succeeded: false,
        );
    }
}
