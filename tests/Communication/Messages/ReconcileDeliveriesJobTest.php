<?php

declare(strict_types=1);

namespace Tests\Communication\Messages;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Kanvas\Communication\Messages\DataTransferObject\TransportResult;
use Kanvas\Communication\Messages\Enums\ChannelTypeEnum;
use Kanvas\Communication\Messages\Enums\DeliveryStatusEnum;
use Kanvas\Communication\Messages\Jobs\ReconcileDeliveriesJob;
use Kanvas\Communication\Messages\Models\Delivery;
use Tests\Communication\Concerns\BuildsCommunicationFixtures;
use Tests\Communication\Fixtures\FakeTransport;
use Tests\TestCase;

final class ReconcileDeliveriesJobTest extends TestCase
{
    use BuildsCommunicationFixtures;
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'workflow', 'crm', 'social'];

    private FakeTransport $transport;

    public function testALostOutcomeIsSettledByAskingTheProviderNotBySendingAgain(): void
    {
        $delivery = $this->deliveryLeftAs(DeliveryStatusEnum::UNKNOWN, ['external_id' => 'FAKE-9']);
        $this->transport->lookupResult = TransportResult::observed(DeliveryStatusEnum::DELIVERED, 'delivered', 'FAKE-9');

        new ReconcileDeliveriesJob($this->currentApp())->handle();

        $this->assertSame(DeliveryStatusEnum::DELIVERED, $delivery->fresh()->status);
        $this->assertSame([], $this->transport->sent);
    }

    public function testADeliveryStillSendingLongAfterItWasClaimedBecomesUnknown(): void
    {
        $delivery = $this->deliveryLeftAs(DeliveryStatusEnum::SENDING);

        new ReconcileDeliveriesJob($this->currentApp())->handle();

        $this->assertSame(DeliveryStatusEnum::UNKNOWN, $delivery->fresh()->status);
        $this->assertNull($delivery->fresh()->events_closed_at);
        $this->assertSame([], $this->transport->sent);
    }

    public function testARecentlyClaimedDeliveryIsLeftAlone(): void
    {
        $delivery = $this->deliveryLeftAs(DeliveryStatusEnum::SENDING, ['updated_at' => now()]);

        new ReconcileDeliveriesJob($this->currentApp())->handle();

        $this->assertSame(DeliveryStatusEnum::SENDING, $delivery->fresh()->status);
    }

    /**
     * Past the provider's window nothing more can arrive, so the delivery stops holding its
     * connection's registrations open — but one that never got an outcome says so on the way out.
     */
    public function testUnknownClosureIsLoggedWithReason(): void
    {
        Log::spy();
        $delivery = $this->deliveryLeftAs(DeliveryStatusEnum::UNKNOWN, ['last_event_at' => now()->subHours(73)]);

        new ReconcileDeliveriesJob($this->currentApp())->handle();

        $this->assertNotNull($delivery->fresh()->events_closed_at);
        $this->assertSame(DeliveryStatusEnum::UNKNOWN, $delivery->fresh()->status);
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context): bool => $context['delivery_id'] === $delivery->getId()
                && $context['reason'] === 'retention_window_elapsed')
            ->once();
    }

    public function testADeliveryInsideTheRetentionWindowStaysOpen(): void
    {
        $delivery = $this->deliveryLeftAs(DeliveryStatusEnum::DELIVERED, ['last_event_at' => now()->subHours(71)]);

        new ReconcileDeliveriesJob($this->currentApp())->handle();

        $this->assertNull($delivery->fresh()->events_closed_at);
    }

    private function deliveryLeftAs(DeliveryStatusEnum $status, array $attributes = []): Delivery
    {
        Queue::fake();
        $this->transport = $this->useFakeProvider();
        $this->defaultSmsConnection();

        $delivery = $this->communication()
            ->send($this->personWithCellphone(), 'Hello', channels: ['sms'])
            ->delivery(ChannelTypeEnum::SMS);

        Delivery::query()->whereKey($delivery->getKey())->update($attributes + [
            'status' => $status->value,
            'updated_at' => now()->subHour(),
        ]);

        return $delivery;
    }
}
