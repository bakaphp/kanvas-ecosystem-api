<?php

declare(strict_types=1);

namespace Tests\Communication\Messages;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Kanvas\Communication\Messages\Actions\DispatchDeliveryAction;
use Kanvas\Communication\Messages\DataTransferObject\Operation;
use Kanvas\Communication\Messages\DataTransferObject\TransportResult;
use Kanvas\Communication\Messages\Enums\ChannelTypeEnum;
use Kanvas\Communication\Messages\Enums\DeliveryStatusEnum;
use Kanvas\Communication\Messages\Models\Delivery;
use Kanvas\Guild\Customers\Enums\ConsentConfigurationEnum;
use Kanvas\Guild\Customers\Models\Contact;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use RuntimeException;
use Tests\Communication\Concerns\BuildsCommunicationFixtures;
use Tests\TestCase;

final class DispatchDeliveryActionTest extends TestCase
{
    use BuildsCommunicationFixtures;
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'workflow', 'crm', 'social'];

    public function testAnAcceptedDeliveryIsFiledInTheConversation(): void
    {
        $this->useFakeProvider();
        $connection = $this->defaultSmsConnection();
        $person = $this->personWithCellphone('+18095550142');

        $delivery = $this->communication()
            ->send($person, 'Your quote is ready', channels: ['sms'])
            ->delivery(ChannelTypeEnum::SMS);

        $message = Message::query()->findOrFail($delivery->message_id);
        $channel = Channel::query()
            ->fromApp($this->currentApp())
            ->fromCompany($this->currentCompany())
            ->where('slug', 'fake-18095550142')
            ->firstOrFail();

        $this->assertSame(DeliveryStatusEnum::ACCEPTED, $delivery->status);
        $this->assertSame('FAKE-1', $delivery->external_id);
        $this->assertNotNull($delivery->accepted_at);
        $this->assertNull($delivery->events_closed_at);
        $this->assertSame('Your quote is ready', $message->message['content']);
        $this->assertTrue($message->message['from_me']);
        $this->assertSame($connection->getId(), $message->message['communication']['connection_id']);
        $this->assertInstanceOf(People::class, $message->entity());
        $this->assertSame($person->getId(), $message->entity()->getId());
        $this->assertTrue($channel->messages()->where('messages.id', $message->getId())->exists());
    }

    public function testStaleActivationVersionSkips(): void
    {
        $transport = $this->useFakeProvider();
        $connection = $this->defaultSmsConnection();
        $delivery = $this->queuedDelivery($this->personWithCellphone());

        // Switched off and on again while the delivery sat in the queue.
        $connection->update(['activation_version' => $connection->activation_version + 1]);

        new DispatchDeliveryAction($delivery)->execute();

        $this->assertSame(DeliveryStatusEnum::SKIPPED, $delivery->fresh()->status);
        $this->assertSame('stale_activation', $delivery->fresh()->error_code);
        $this->assertSame([], $transport->sent);
    }

    public function testAConnectionSwitchedOffWhileQueuedIsNotSentThrough(): void
    {
        $transport = $this->useFakeProvider();
        $connection = $this->defaultSmsConnection();
        $delivery = $this->queuedDelivery($this->personWithCellphone());

        $connection->update(['enabled' => false]);

        new DispatchDeliveryAction($delivery)->execute();

        $this->assertSame('connection_disabled', $delivery->fresh()->error_code);
        $this->assertSame([], $transport->sent);
    }

    public function testBothConsentGuardsAtDispatch(): void
    {
        $transport = $this->useFakeProvider();
        $this->defaultSmsConnection();

        $flagged = $this->personWithCellphone('+18095550151');
        $flaggedDelivery = $this->queuedDelivery($flagged);
        $flagged->set(ConsentConfigurationEnum::DO_NOT_CONTACT->value, true);

        $optedOut = $this->personWithCellphone('+18095550152');
        $optedOutDelivery = $this->queuedDelivery($optedOut);
        $optedOut->setPhoneOptOut('+18095550152');

        new DispatchDeliveryAction($flaggedDelivery)->execute();
        new DispatchDeliveryAction($optedOutDelivery)->execute();

        $this->assertSame('do_not_contact', $flaggedDelivery->fresh()->error_code);
        $this->assertSame('destination_opted_out', $optedOutDelivery->fresh()->error_code);
        $this->assertSame(DeliveryStatusEnum::SKIPPED, $optedOutDelivery->fresh()->status);
        $this->assertSame([], $transport->sent);
    }

    public function testAProviderRefusalForAnUnsubscribedRecipientRecordsTheOptOut(): void
    {
        $transport = $this->useFakeProvider();
        $transport->sendResult = TransportResult::failed('21610', 'opted_out', recipientOptedOut: true);
        $this->defaultSmsConnection();
        $person = $this->personWithCellphone('+18095550161');

        $delivery = $this->communication()->send($person, 'Hello', channels: ['sms'])->delivery(ChannelTypeEnum::SMS);

        $this->assertSame(DeliveryStatusEnum::FAILED, $delivery->status);
        $this->assertSame('21610', $delivery->error_code);
        $this->assertTrue($delivery->recipientOptedOut(), 'a caller can tell the opt-out without knowing the vendor code');
        $this->assertNotNull($delivery->events_closed_at);
        $this->assertNull($delivery->message_id);
        $this->assertTrue(
            $person->contacts()->get()->contains(fn (Contact $contact): bool => (bool) $contact->is_opt_out)
        );
    }

    /**
     * The provider may have taken the message before the connection dropped, so the delivery is not
     * failed — and a retry, which would be a second message, leaves it alone.
     */
    public function testALostOutcomeIsUnknownAndNeverRetried(): void
    {
        $transport = $this->useFakeProvider();
        $transport->sendException = new RuntimeException('Connection timed out');
        $this->defaultSmsConnection();

        $operation = $this->communication()->send($this->personWithCellphone(), 'Hello', channels: ['sms']);

        $transport->sendException = null;
        $operation->retryFailed();

        $delivery = $operation->delivery(ChannelTypeEnum::SMS);

        $this->assertSame(DeliveryStatusEnum::UNKNOWN, $delivery->status);
        $this->assertSame('outcome_lost', $delivery->error_code);
        $this->assertNull($delivery->events_closed_at);
        $this->assertCount(1, $transport->sent);
    }

    public function testTheSameDeliveryDispatchedTwiceIsSentOnce(): void
    {
        $transport = $this->useFakeProvider();
        $this->defaultSmsConnection();
        $delivery = $this->queuedDelivery($this->personWithCellphone());

        new DispatchDeliveryAction($delivery)->execute();
        new DispatchDeliveryAction(Delivery::query()->findOrFail($delivery->getId()))->execute();

        $this->assertCount(1, $transport->sent);
    }

    private function queuedDelivery(People $person): Delivery
    {
        Queue::fake();

        /** @var Operation $operation */
        $operation = $this->communication()->send($person, 'Hello', channels: ['sms']);

        return $operation->delivery(ChannelTypeEnum::SMS);
    }
}
