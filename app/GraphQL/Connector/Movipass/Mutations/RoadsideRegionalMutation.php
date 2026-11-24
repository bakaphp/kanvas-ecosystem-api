<?php

declare(strict_types=1);

namespace App\GraphQL\Connector\Movipass\Mutations;

use App\GraphQL\Concerns\ResolvesActingContext;
use App\GraphQL\Connector\Movipass\Concerns\ResolvesRegionalRoadsideOrder;
use Illuminate\Support\Carbon;
use Kanvas\Connectors\Movipass\Actions\AcceptRoadsideOfferAction;
use Kanvas\Connectors\Movipass\Actions\AddRoadsideEvidenceAction;
use Kanvas\Connectors\Movipass\Actions\CancelRoadsideAttentionAction;
use Kanvas\Connectors\Movipass\Actions\CloseRoadsideServiceAction;
use Kanvas\Connectors\Movipass\Actions\ReassignRoadsideAssistanceAction;
use Kanvas\Connectors\Movipass\Actions\RecordRoadsideTrackingAction;
use Kanvas\Connectors\Movipass\Actions\RejectRoadsideOfferAction;
use Kanvas\Connectors\Movipass\Actions\ResolveRoadsideCabinReviewAction;
use Kanvas\Connectors\Movipass\Actions\SetRoadsideTechnicianAvailabilityAction;
use Kanvas\Connectors\Movipass\Actions\StartRoadsideServiceWithPinAction;
use Kanvas\Connectors\Movipass\Actions\UpdateRoadsideTariffsAction;
use Kanvas\Connectors\Movipass\Enums\RoadsideEvidencePhaseEnum;
use Kanvas\Connectors\Movipass\Enums\RoadsideTechnicianDeclineReasonEnum;
use Kanvas\Connectors\Movipass\Jobs\SyncRoadsideRegionalEventJob;
use Kanvas\Connectors\Movipass\Support\RoadsideProviderOutbox;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Users\Models\Users;

/**
 * The Asistencia Regional surface: the technician's APK (offers, tracking, PIN, evidence, close) and
 * the cabin's interventions. Each resolver only looks the order up and hands it to its action, where
 * every rule lives.
 */
class RoadsideRegionalMutation
{
    use ResolvesActingContext;
    use ResolvesRegionalRoadsideOrder;

    public function acceptOffer(mixed $rootValue, array $request): Order
    {
        $ctx = $this->actingContext();

        $order = $this->regionalRoadsideOrder($ctx->app, $request['order_id']);

        return new AcceptRoadsideOfferAction($order, $ctx->user)->execute();
    }

    public function rejectOffer(mixed $rootValue, array $request): Order
    {
        $ctx = $this->actingContext();
        $order = $this->regionalRoadsideOrder($ctx->app, $request['order_id']);

        new RejectRoadsideOfferAction(
            order: $order,
            technician: $ctx->user,
            reason: RoadsideTechnicianDeclineReasonEnum::from((string) $request['reason']),
            comment: $request['comment'] ?? null,
        )->execute();

        return $order->refresh();
    }

    public function cancelAttention(mixed $rootValue, array $request): Order
    {
        $ctx = $this->actingContext();

        return new CancelRoadsideAttentionAction(
            order: $this->regionalRoadsideOrder($ctx->app, $request['order_id']),
            technician: $ctx->user,
            reason: RoadsideTechnicianDeclineReasonEnum::from((string) $request['reason']),
            comment: $request['comment'] ?? null,
        )->execute();
    }

    public function setAvailability(mixed $rootValue, array $request): string
    {
        $ctx = $this->actingContext();

        return new SetRoadsideTechnicianAvailabilityAction($ctx->user, $ctx->app, (bool) $request['online'])
            ->execute()
            ->value;
    }

    public function track(mixed $rootValue, array $request): array
    {
        $ctx = $this->actingContext();

        return new RecordRoadsideTrackingAction(
            order: $this->regionalRoadsideOrder($ctx->app, $request['order_id']),
            technician: $ctx->user,
            lat: (float) $request['lat'],
            lng: (float) $request['lng'],
        )->execute();
    }

    public function validatePin(mixed $rootValue, array $request): Order
    {
        $ctx = $this->actingContext();
        $order = $this->regionalRoadsideOrder($ctx->app, $request['order_id']);

        new StartRoadsideServiceWithPinAction($order, (string) $request['pin'], $ctx->user)->execute();

        return $order->refresh();
    }

    public function addEvidence(mixed $rootValue, array $request): array
    {
        $ctx = $this->actingContext();
        $input = $request['input'];

        return new AddRoadsideEvidenceAction(
            order: $this->regionalRoadsideOrder($ctx->app, $input['order_id']),
            technician: $ctx->user,
            phase: isset($input['phase']) ? RoadsideEvidencePhaseEnum::from((string) $input['phase']) : null,
            fileUuids: (array) ($input['file_uuids'] ?? []),
            comment: $input['comment'] ?? null,
            source: $input['source'] ?? null,
        )->execute();
    }

    public function close(mixed $rootValue, array $request): Order
    {
        $ctx = $this->actingContext();

        return new CloseRoadsideServiceAction(
            order: $this->regionalRoadsideOrder($ctx->app, $request['order_id']),
            technician: $ctx->user,
            resolved: (bool) $request['resolved'],
            reason: $request['reason'] ?? null,
        )->execute();
    }

    public function resolveCabinReview(mixed $rootValue, array $request): Order
    {
        $ctx = $this->actingContext();

        return new ResolveRoadsideCabinReviewAction(
            order: $this->regionalRoadsideOrder($ctx->app, $request['order_id']),
            operator: $ctx->user,
            approved: (bool) $request['approved'],
            reason: $request['reason'] ?? null,
        )->execute();
    }

    public function reassign(mixed $rootValue, array $request): Order
    {
        $ctx = $this->actingContext();
        $input = $request['input'];

        return new ReassignRoadsideAssistanceAction(
            order: $this->regionalRoadsideOrder($ctx->app, $input['order_id']),
            operator: $ctx->user,
            reason: (string) $input['reason'],
            technician: isset($input['technician_id']) ? Users::getById((int) $input['technician_id']) : null,
            eligibleTechnicianIds: $input['eligible_technician_ids'] ?? null,
        )->execute();
    }

    public function retryProviderSync(mixed $rootValue, array $request): int
    {
        $ctx = $this->actingContext();
        $order = $this->regionalRoadsideOrder($ctx->app, $request['order_id']);
        $failed = RoadsideProviderOutbox::failedIds($order);

        foreach ($failed as $eventId) {
            RoadsideProviderOutbox::mark($order, $eventId, RoadsideProviderOutbox::PENDING);
            SyncRoadsideRegionalEventJob::dispatch($order->app, $order, $eventId);
        }

        return count($failed);
    }

    public function updateTariffs(mixed $rootValue, array $request): array
    {
        $ctx = $this->actingContext();
        $input = $request['input'];

        return new UpdateRoadsideTariffsAction(
            app: $ctx->app,
            company: $ctx->company,
            changedBy: $ctx->user,
            tariffs: (array) $input['tariffs'],
            reason: (string) $input['reason'],
            effectiveFrom: isset($input['effective_from']) ? Carbon::parse($input['effective_from']) : null,
        )->execute();
    }
}
