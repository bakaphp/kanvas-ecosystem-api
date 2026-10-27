<?php

declare(strict_types=1);

namespace Tests\Communication\Messages;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Communication\Messages\Actions\RecordDeliveryEventAction;
use Kanvas\Communication\Messages\Enums\ChannelTypeEnum;
use Kanvas\Communication\Messages\Enums\DeliveryStatusEnum;
use Kanvas\Communication\Messages\Models\Delivery;
use Tests\Communication\Concerns\BuildsCommunicationFixtures;
use Tests\TestCase;

final class RecordDeliveryEventActionTest extends TestCase
{
    use BuildsCommunicationFixtures;
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'workflow', 'crm', 'social'];

    public function testDuplicateStatusCallbackRecordsOneEvent(): void
    {
        $delivery = $this->acceptedDelivery();

        $first = new RecordDeliveryEventAction($delivery, DeliveryStatusEnum::DELIVERED, 'delivered')->execute();
        $second = new RecordDeliveryEventAction($delivery, DeliveryStatusEnum::DELIVERED, 'delivered')->execute();

        $this->assertTrue($first);
        $this->assertFalse($second);
        $this->assertSame(1, $delivery->events()->count());
        $this->assertSame(DeliveryStatusEnum::DELIVERED, $delivery->fresh()->status);
    }

    public function testLateSentAfterDeliveredDoesNotRegress(): void
    {
        $delivery = $this->acceptedDelivery();

        new RecordDeliveryEventAction($delivery, DeliveryStatusEnum::DELIVERED, 'delivered')->execute();
        new RecordDeliveryEventAction($delivery, DeliveryStatusEnum::ACCEPTED, 'sent')->execute();

        $this->assertSame(DeliveryStatusEnum::DELIVERED, $delivery->fresh()->status);
        $this->assertSame(2, $delivery->events()->count());
    }

    public function testAFailureAfterDeliveryIsRecordedButDoesNotUndoIt(): void
    {
        $delivery = $this->acceptedDelivery();

        new RecordDeliveryEventAction($delivery, DeliveryStatusEnum::DELIVERED, 'delivered')->execute();
        new RecordDeliveryEventAction(
            delivery: $delivery,
            status: DeliveryStatusEnum::FAILED,
            providerStatus: 'failed',
            errorCode: '30008'
        )->execute();

        $this->assertSame(DeliveryStatusEnum::DELIVERED, $delivery->fresh()->status);
        $this->assertNull($delivery->fresh()->error_code);
    }

    /**
     * The fake provider still reports `read` after `delivered`, so a delivered message is not one it
     * has finished with — only the terminal event closes it.
     */
    public function testDeliveredIsNotClosedUntilATerminalEvent(): void
    {
        $delivery = $this->acceptedDelivery();

        new RecordDeliveryEventAction($delivery, DeliveryStatusEnum::DELIVERED, 'delivered')->execute();
        $this->assertNull($delivery->fresh()->events_closed_at);

        new RecordDeliveryEventAction($delivery, DeliveryStatusEnum::READ, 'read')->execute();

        $this->assertSame(DeliveryStatusEnum::READ, $delivery->fresh()->status);
        $this->assertNotNull($delivery->fresh()->events_closed_at);
    }

    public function testAFailureEventCarriesItsErrorOntoTheDelivery(): void
    {
        $delivery = $this->acceptedDelivery();

        new RecordDeliveryEventAction(
            delivery: $delivery,
            status: DeliveryStatusEnum::FAILED,
            providerStatus: 'failed',
            errorCode: '30005',
            error: 'Unknown destination handset'
        )->execute();

        $this->assertSame(DeliveryStatusEnum::FAILED, $delivery->fresh()->status);
        $this->assertSame('30005', $delivery->fresh()->error_code);
        $this->assertNotNull($delivery->fresh()->events_closed_at);
    }

    private function acceptedDelivery(): Delivery
    {
        $this->useFakeProvider();
        $this->defaultSmsConnection();

        return $this->communication()
            ->send($this->personWithCellphone(), 'Hello', channels: ['sms'])
            ->delivery(ChannelTypeEnum::SMS);
    }
}
