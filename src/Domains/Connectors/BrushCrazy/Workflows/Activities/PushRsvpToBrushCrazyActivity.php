<?php

declare(strict_types=1);

namespace Kanvas\Connectors\BrushCrazy\Workflows\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Connectors\BrushCrazy\Enums\ConfigurationEnum;
use Kanvas\Connectors\BrushCrazy\Support\SiteBooking;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Event\Events\Models\EventVersionParticipant;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Override;

/**
 * A $0 booking is confirmed by flipping each attendee's EVP to `state: confirmed`, one EVP at a
 * time, and every flip fires this. Only the flip that leaves the whole reservation confirmed
 * pushes, so BrushCrazy gets every attendee in one call; two flips racing to that point both push
 * and BrushCrazy dedupes them on the order uuid.
 */
#[WorkflowAction(
    name: 'BrushCrazy Push RSVP',
    description: 'Books a confirmed $0 site booking in BrushCrazy (POST /api/kanvas/rsvps). Hang it on EventVersion event-versions-workflow; it acts only on participant-updated.',
    integration: IntegrationsEnum::BRUSHCRAZY,
    requiresConfig: [ConfigurationEnum::BRUSHCRAZY_API_URL, ConfigurationEnum::BRUSHCRAZY_API_SECRET],
)]
class PushRsvpToBrushCrazyActivity extends BrushCrazyPushActivity
{
    /**
     * @param EventVersion $eventVersion
     */
    #[Override]
    public function execute(Model $eventVersion, AppInterface $app, array $params): array
    {
        return $this->runPush(
            $eventVersion,
            $app,
            $eventVersion->company,
            $params,
            function () use ($eventVersion, $app, $params) {
                if (($params['event_version_change'] ?? null) !== 'participant-updated') {
                    return $this->skip('Not a participant update.');
                }

                $evp = EventVersionParticipant::query()
                    ->where('id', (int) ($params['event_version_participant_id'] ?? 0))
                    ->where('event_version_id', $eventVersion->getId())
                    ->first();
                $metadata = is_array($evp?->metadata) ? $evp->metadata : [];

                if (! SiteBooking::isConfirmedReservation($metadata)) {
                    return $this->skip('Participant is not a confirmed site reservation.');
                }

                $order = SiteBooking::findOrder($eventVersion, $metadata['order_uuid'] ?? null);

                if ($order === null) {
                    return $this->skip('Confirmed reservation has no order yet.');
                }

                if (SiteBooking::isMirroredOrder($order) || SiteBooking::wasPushed($order)) {
                    return $this->skip('Order is already in BrushCrazy.');
                }

                if (SiteBooking::cents($order->total_net_amount) > 0) {
                    return $this->skip('Paid order; booked by the paid-order push.');
                }

                [$booking, $reason] = SiteBooking::resolve($order);

                if ($booking === null) {
                    return $this->failWorkflow(['message' => $reason, 'entity' => null]);
                }

                $pending = $booking->participants()
                    ->filter(fn (EventVersionParticipant $p) => ($p->metadata['state'] ?? null) !== SiteBooking::STATE_CONFIRMED);

                if ($pending->isNotEmpty()) {
                    return $this->skip("Waiting for {$pending->count()} more attendee(s) to be confirmed.");
                }

                $body = [
                    'kanvas_order_uuid' => $order->uuid,
                    'kanvas_key' => (string) ($metadata['reservation_key'] ?? $order->uuid),
                    'studio_id' => $booking->studioId,
                    'customer' => $booking->customer(),
                    'calendarable_type' => $booking->calendarableType->value,
                    'calendarable_id' => $booking->calendarableId,
                    'attendees' => $booking->attendees(),
                ];

                return $this->post(
                    $app,
                    'api/kanvas/rsvps',
                    $body,
                    function (array $response) use ($order) {
                        SiteBooking::stampPushed($order, $response);

                        return [
                            'message' => 'RSVP booked in BrushCrazy.',
                            'sale_id' => $response['sale_id'] ?? null,
                            'response' => $response,
                            'entity' => $order,
                        ];
                    }
                );
            }
        );
    }
}
