<?php

declare(strict_types=1);

namespace Tests\Communication\Connections;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Kanvas\Communication\Connections\Actions\SetConnectionEnabledAction;
use Kanvas\Communication\Connections\Actions\SetIntegrationActiveAction;
use Kanvas\Communication\Connections\Models\Connection;
use Kanvas\Communication\Messages\Actions\DispatchDeliveryAction;
use Kanvas\Communication\Messages\Enums\ChannelTypeEnum;
use Kanvas\Communication\Messages\Enums\DeliveryStatusEnum;
use Kanvas\Communication\Messages\Models\Delivery;
use Tests\Communication\Concerns\BuildsCommunicationFixtures;
use Tests\TestCase;

/**
 * Three independent facts — setup, the person's switch, the integration's switch — and a version
 * that moves only on an actual transition.
 */
final class ConnectionSwitchesTest extends TestCase
{
    use BuildsCommunicationFixtures;
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'workflow', 'crm', 'social'];

    public function testOffCancelsPending(): void
    {
        [$connection, $delivery] = $this->connectionWithQueuedDelivery();

        new SetConnectionEnabledAction($connection, false)->execute();

        $this->assertFalse($connection->fresh()->enabled);
        $this->assertSame(DeliveryStatusEnum::SKIPPED, $delivery->fresh()->status);
        $this->assertSame('connection_disabled', $delivery->fresh()->error_code);
    }

    public function testOnTwiceBumpsVersionOnce(): void
    {
        $connection = $this->defaultSmsConnection(attributes: ['enabled' => false]);

        new SetConnectionEnabledAction($connection, true)->execute();
        new SetConnectionEnabledAction($connection, true)->execute();

        $this->assertTrue($connection->fresh()->enabled);
        $this->assertSame(2, $connection->fresh()->activation_version);
    }

    /**
     * The Off cancelled what was queued; work that escaped the cancellation (already handed to a
     * worker) carries the old version and is dropped rather than released by the On.
     */
    public function testOffThenOnDoesNotReleaseWorkQueuedBeforeTheOff(): void
    {
        $transport = $this->useFakeProvider();
        [$connection, $delivery] = $this->connectionWithQueuedDelivery();

        Connection::query()->whereKey($connection->getKey())->update(['enabled' => false]);
        new SetConnectionEnabledAction($connection->fresh(), true)->execute();

        new DispatchDeliveryAction(Delivery::query()->findOrFail($delivery->getId()))->execute();

        $this->assertSame('stale_activation', $delivery->fresh()->error_code);
        $this->assertSame([], $transport->sent);
    }

    public function testIntegrationDeactivationCancelsPending(): void
    {
        [$connection, $delivery] = $this->connectionWithQueuedDelivery();

        new SetIntegrationActiveAction($this->currentApp(), $connection->integrationCompany, false)->execute();

        $this->assertSame(DeliveryStatusEnum::SKIPPED, $delivery->fresh()->status);
        $this->assertSame('integration_inactive', $delivery->fresh()->error_code);
        $this->assertSame('integration_inactive', $connection->fresh()->unavailableReason());
    }

    public function testIntegrationOnKeepsUserDisabledOffAndBumpsOnce(): void
    {
        $connection = $this->defaultSmsConnection(attributes: ['enabled' => false]);
        $integration = $connection->integrationCompany;

        new SetIntegrationActiveAction($this->currentApp(), $integration, false)->execute();
        new SetIntegrationActiveAction($this->currentApp(), $integration, true)->execute();
        new SetIntegrationActiveAction($this->currentApp(), $integration, true)->execute();

        $this->assertFalse($connection->fresh()->enabled);
        $this->assertSame(2, $connection->fresh()->activation_version);
    }

    /**
     * @return array{0: Connection, 1: Delivery}
     */
    private function connectionWithQueuedDelivery(): array
    {
        Queue::fake();
        $this->useFakeProvider();
        $connection = $this->defaultSmsConnection();

        $delivery = $this->communication()
            ->send($this->personWithCellphone(), 'Hello', channels: ['sms'])
            ->delivery(ChannelTypeEnum::SMS);

        return [$connection, $delivery];
    }
}
