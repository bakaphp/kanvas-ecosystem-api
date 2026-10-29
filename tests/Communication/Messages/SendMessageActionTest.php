<?php

declare(strict_types=1);

namespace Tests\Communication\Messages;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Kanvas\Communication\Messages\DataTransferObject\TransportResult;
use Kanvas\Communication\Messages\Enums\ChannelTypeEnum;
use Kanvas\Communication\Messages\Enums\DeliveryStatusEnum;
use Kanvas\Communication\Messages\Jobs\DispatchDeliveryJob;
use Kanvas\Communication\Messages\Models\Request;
use Kanvas\Guild\Customers\Enums\ConsentConfigurationEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Tests\Communication\Concerns\BuildsCommunicationFixtures;
use Tests\TestCase;

final class SendMessageActionTest extends TestCase
{
    use BuildsCommunicationFixtures;
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'workflow', 'crm', 'social'];

    public function testTheRequestIsARowBeforeAnyProviderIsCalled(): void
    {
        Queue::fake();
        $transport = $this->useFakeProvider();
        $connection = $this->defaultSmsConnection();
        $person = $this->personWithCellphone('+18095550199');

        $operation = $this->communication()->send(
            $person,
            'Hello',
            channels: ['sms'],
            tag: 'quote'
        );

        $delivery = $operation->delivery(ChannelTypeEnum::SMS);

        $this->assertSame([], $transport->sent);
        $this->assertSame('pending', $operation->status());
        $this->assertSame(DeliveryStatusEnum::PENDING, $delivery->status);
        $this->assertSame($connection->getId(), $delivery->connection_id);
        $this->assertSame('+18095550199', $delivery->to_address);
        $this->assertSame('Hello', $operation->request->content['text']);
        $this->assertSame($person->getId(), $operation->request->people_id);
        Queue::assertPushed(DispatchDeliveryJob::class, 1);
    }

    /**
     * No agent is involved anywhere: a domain that just needs to tell a person something can.
     */
    public function testSendWithoutAgent(): void
    {
        $transport = $this->useFakeProvider();
        $this->defaultSmsConnection();

        $operation = $this->communication()->send($this->personWithCellphone(), 'Your order shipped', channels: ['sms']);

        $this->assertSame('accepted', $operation->status());
        $this->assertCount(1, $transport->sent);
        $this->assertNull($operation->request->sender);
    }

    public function testIdempotencyKeyDedupes(): void
    {
        $transport = $this->useFakeProvider();
        $this->defaultSmsConnection();
        $person = $this->personWithCellphone();

        $first = $this->communication()->send(
            $person,
            'Quote ready',
            channels: ['sms'],
            idempotencyKey: 'quote:1'
        );
        $second = $this->communication()->send(
            $person,
            'Quote ready',
            channels: ['sms'],
            idempotencyKey: 'quote:1'
        );

        $this->assertSame($first->id(), $second->id());
        $this->assertCount(1, $transport->sent);
    }

    public function testNoContentDedupe(): void
    {
        $transport = $this->useFakeProvider();
        $this->defaultSmsConnection();
        $person = $this->personWithCellphone();

        $first = $this->communication()->send($person, 'Same words', channels: ['sms']);
        $second = $this->communication()->send($person, 'Same words', channels: ['sms']);

        $this->assertNotSame($first->id(), $second->id());
        $this->assertCount(2, $transport->sent);
    }

    public function testADisabledConnectionFailsTheDeliveryInsteadOfFallingBack(): void
    {
        Queue::fake();
        $this->useFakeProvider();
        $this->connection('fake', '+18095550111');
        $this->defaultSmsConnection(attributes: ['enabled' => false]);

        $operation = $this->communication()->send($this->personWithCellphone(), 'Hello', channels: ['sms']);

        $delivery = $operation->delivery(ChannelTypeEnum::SMS);

        $this->assertSame(DeliveryStatusEnum::FAILED, $delivery->status);
        $this->assertSame('connection_disabled', $delivery->error_code);
        $this->assertSame('failed', $operation->status());
        Queue::assertNotPushed(DispatchDeliveryJob::class);
    }

    public function testAChannelWithNoConnectionIsAFailedDeliveryNotAnException(): void
    {
        $this->useFakeProvider();

        $operation = $this->communication()->send($this->personWithCellphone(), 'Hello', channels: ['sms']);

        $this->assertSame('no_connection', $operation->delivery(ChannelTypeEnum::SMS)->error_code);
    }

    public function testAllOnlyUsesChannelsThatCanCarryTheMessageNow(): void
    {
        $transport = $this->useFakeProvider();
        $connection = $this->defaultSmsConnection(attributes: ['enabled' => false]);
        $person = $this->personWithCellphone();

        $whileOff = $this->communication()->send($person, 'Hello', channels: 'all');

        $connection->update(['enabled' => true]);
        $whileOn = $this->communication()->send($person, 'Hello', channels: 'all');

        $this->assertCount(0, $whileOff->deliveries());
        $this->assertCount(1, $whileOn->deliveries());
        $this->assertCount(1, $transport->sent);
    }

    public function testADoNotContactPersonIsSkippedAndNeverQueued(): void
    {
        Queue::fake();
        $this->useFakeProvider();
        $this->defaultSmsConnection();
        $person = $this->personWithCellphone();
        $person->set(ConsentConfigurationEnum::DO_NOT_CONTACT->value, true);

        $operation = $this->communication()->send($person, 'Hello', channels: ['sms']);

        $delivery = $operation->delivery(ChannelTypeEnum::SMS);

        $this->assertSame(DeliveryStatusEnum::SKIPPED, $delivery->status);
        $this->assertSame('do_not_contact', $delivery->error_code);
        Queue::assertNotPushed(DispatchDeliveryJob::class);
    }

    /**
     * Opt-outs recorded before the person-level flag existed live on one of their leads only.
     */
    public function testAnOptOutRecordedOnlyOnOneOfThePersonsLeadsStillStopsTheSend(): void
    {
        Queue::fake();
        $this->useFakeProvider();
        $this->defaultSmsConnection();
        $person = $this->personWithCellphone();

        Lead::factory()
            ->withAppAndCompany($this->currentApp()->getId(), $this->currentCompany()->getId())
            ->withPeopleId($person->getId())
            ->create()
            ->set(ConsentConfigurationEnum::DO_NOT_CONTACT->value, true);

        $delivery = $this->communication()->send($person, 'Hello', channels: ['sms'])->delivery(ChannelTypeEnum::SMS);

        $this->assertSame(DeliveryStatusEnum::SKIPPED, $delivery->status);
        $this->assertSame('do_not_contact', $delivery->error_code);
    }

    public function testRetryFailedLeavesAccepted(): void
    {
        $transport = $this->useFakeProvider();
        $this->defaultSmsConnection();
        $person = $this->personWithCellphone();

        $accepted = $this->communication()->send($person, 'First', channels: ['sms']);

        $transport->sendResult = TransportResult::failed('30003', 'Unreachable destination handset');
        $failed = $this->communication()->send($person, 'Second', channels: ['sms']);
        $this->assertSame('failed', $failed->status());

        $transport->sendResult = null;
        $accepted->retryFailed();
        $failed->retryFailed();

        $this->assertSame('accepted', $failed->status());
        $this->assertSame(2, $failed->delivery(ChannelTypeEnum::SMS)->attempts);
        $this->assertSame(1, $accepted->delivery(ChannelTypeEnum::SMS)->attempts);
        $this->assertCount(3, $transport->sent);
        $this->assertSame(2, Request::query()->whereIn('id', [$accepted->id(), $failed->id()])->count());
    }

    /**
     * A delivery that cannot be retried — here one that never had a connection — must not stop the
     * failed ones after it from going out again.
     */
    public function testRetryFailedRetriesEveryFailedDelivery(): void
    {
        $transport = $this->useFakeProvider();
        $this->defaultSmsConnection();
        $person = $this->personWithCellphone();

        $transport->sendResult = TransportResult::failed('30003', 'Unreachable destination handset');
        $operation = $this->communication()->send(
            $person,
            'Hello',
            channels: ['email', 'sms'],
            sync: true
        );

        $this->assertSame('failed', $operation->status());
        $this->assertSame('no_connection', $operation->delivery(ChannelTypeEnum::EMAIL)->error_code);

        $transport->sendResult = null;
        $operation->retryFailed();

        $this->assertSame(DeliveryStatusEnum::FAILED, $operation->delivery(ChannelTypeEnum::EMAIL)->status);
        $this->assertSame(DeliveryStatusEnum::ACCEPTED, $operation->delivery(ChannelTypeEnum::SMS)->status);
        $this->assertCount(2, $transport->sent);
    }
}
