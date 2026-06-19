<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\TeeTime;

use Kanvas\Connectors\TeeTime\Handlers\TeeTimeHandler;
use Kanvas\Connectors\TeeTime\Workflows\Activities\ActivateBookingOnPaymentActivity;
use Kanvas\Event\Events\Enums\EventStatusEnum;
use Kanvas\Event\Events\Models\Event;
use Kanvas\Event\Participants\Models\ParticipantPass;
use Kanvas\Event\Participants\Models\ParticipantPassMotive;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\Models\StoredWorkflow;
use Tests\Connectors\Traits\HasIntegrationCompany;
use Tests\GraphQL\Event\ResourceBookingBase;

final class ActivateBookingOnPaymentActivityTest extends ResourceBookingBase
{
    use HasIntegrationCompany;

    public function testAfterPaymentIntentActivatesBookingAndGeneratesPasses(): void
    {
        $booking = $this->createBooking($this->getBasicBookingData());

        $this->setIntegration(
            $this->apps,
            IntegrationsEnum::TEE_TIME,
            TeeTimeHandler::class,
            $this->company,
            $this->user
        );

        $event = Event::find($booking['event']['id']);
        $order = $event->orders()->first();
        $this->assertNotNull($order, 'Booking should have created an order linked via the resources morph');

        $result = $this->runActivity($order, WorkflowEnum::AFTER_PAYMENT_INTENT->value);

        $this->assertEquals('success', $result['status']);
        $this->assertEquals($event->getId(), $result['event']);

        $event->refresh();
        $this->assertEquals(
            EventStatusEnum::ACTIVE->value,
            $event->eventStatus->name,
            'Event should be activated after the payment intent'
        );

        $motive = ParticipantPassMotive::fromCompany($this->company)
            ->fromApp($this->apps)
            ->where('name', 'Default')
            ->first();
        $this->assertNotNull($motive);

        $participantPasses = ParticipantPass::where('event_id', $event->getId())
            ->where('participant_id', '>', 0)
            ->get();
        $this->assertCount(1, $participantPasses);

        $eventPass = ParticipantPass::where('event_id', $event->getId())
            ->whereNull('participant_id')
            ->first();
        $this->assertNotNull($eventPass);
        $this->assertNotEmpty($eventPass->code);
    }

    public function testNonPaymentIntentEventIsIgnored(): void
    {
        $booking = $this->createBooking($this->getBasicBookingData());

        $this->setIntegration(
            $this->apps,
            IntegrationsEnum::TEE_TIME,
            TeeTimeHandler::class,
            $this->company,
            $this->user
        );

        $event = Event::find($booking['event']['id']);
        $order = $event->orders()->first();

        $result = $this->runActivity($order, WorkflowEnum::UPDATED->value);

        $this->assertEquals('skipped', $result['status']);
        $this->assertSame(
            0,
            ParticipantPass::where('event_id', $event->getId())->count(),
            'No passes should be generated for a non payment-intent event'
        );
    }

    private function runActivity(object $order, string $eventType): array
    {
        $activity = new ActivateBookingOnPaymentActivity(
            0,
            now()->toDateTimeString(),
            StoredWorkflow::make(),
            []
        );

        return $activity->execute($order, $this->apps, [
            'currentEventTypeName' => $eventType,
        ]);
    }
}
