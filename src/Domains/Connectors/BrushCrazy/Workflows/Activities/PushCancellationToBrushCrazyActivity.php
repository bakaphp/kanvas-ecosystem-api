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
 * Sends how many attendees the booking still has rather than "remove one": a retried removal would
 * otherwise take a second seat out of BrushCrazy. Held or expired holds being released never
 * reached BrushCrazy, so only a confirmed attendee of an order that was pushed is sent.
 */
#[WorkflowAction(
    name: 'BrushCrazy Push Cancellation',
    description: 'Removes a cancelled site attendee from the BrushCrazy registration (POST /api/kanvas/cancellations). Hang it on EventVersion event-versions-workflow; it acts only on participant-removed.',
    integration: IntegrationsEnum::BRUSHCRAZY,
    requiresConfig: [ConfigurationEnum::BRUSHCRAZY_API_URL, ConfigurationEnum::BRUSHCRAZY_API_SECRET],
)]
class PushCancellationToBrushCrazyActivity extends BrushCrazyPushActivity
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
                if (($params['event_version_change'] ?? null) !== 'participant-removed') {
                    return $this->skip('Not a participant removal.');
                }

                $evp = EventVersionParticipant::withTrashed()
                    ->where('id', (int) ($params['event_version_participant_id'] ?? 0))
                    ->where('event_version_id', $eventVersion->getId())
                    ->with('participant.people')
                    ->first();
                $metadata = is_array($evp?->metadata) ? $evp->metadata : [];

                if (($metadata['schema'] ?? null) !== SiteBooking::RESERVATION_SCHEMA || ($metadata['state'] ?? null) !== SiteBooking::STATE_CONFIRMED) {
                    return $this->skip('Released participant was a hold, not a confirmed booking.');
                }

                $order = SiteBooking::findOrder($eventVersion, $metadata['order_uuid'] ?? null);

                if ($order === null || ! SiteBooking::wasPushed($order)) {
                    return $this->skip('The participant order was never booked in BrushCrazy.');
                }

                [$booking, $reason] = SiteBooking::resolve($order);

                if ($booking === null) {
                    return $this->failWorkflow(['message' => $reason, 'entity' => null]);
                }

                $remaining = $booking->participants()
                    ->filter(fn (EventVersionParticipant $p) => ($p->metadata['state'] ?? null) === SiteBooking::STATE_CONFIRMED)
                    ->count();
                $name = trim((string) $evp->participant?->people?->getName());

                $body = [
                    'kanvas_order_uuid' => $order->uuid,
                    'calendarable_type' => $booking->calendarableType->value,
                    'calendarable_id' => $booking->calendarableId,
                    'attendee_count' => 1,
                    'remaining_attendees' => $remaining,
                    'removed_attendees' => $name !== '' ? [['name' => $name]] : [],
                ];

                return $this->post(
                    $app,
                    'api/kanvas/cancellations',
                    $body,
                    fn (array $response) => [
                        'message' => 'Cancellation sent to BrushCrazy.',
                        'status' => $response['status'] ?? null,
                        'response' => $response,
                        'entity' => $order,
                    ]
                );
            }
        );
    }
}
