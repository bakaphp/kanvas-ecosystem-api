<?php

declare(strict_types=1);

namespace Tests\Guild\Leads;

use Illuminate\Events\CallQueuedListener;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Queue;
use Kanvas\Communication\Messages\DataTransferObject\TransportResult;
use Kanvas\Communication\Messages\Models\Delivery;
use Kanvas\Guild\Leads\Actions\DeliverConversationMessageAction;
use Kanvas\Guild\Leads\Listeners\DeliverHumanReplyListener;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Channels\Enums\ChannelNameEnum;
use Kanvas\Social\Channels\Events\ChannelMessageAttachedEvent;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\Messages\Repositories\MessagesRepository;
use Tests\Communication\Concerns\BuildsCommunicationFixtures;
use Tests\Communication\Fixtures\FakeTransport;
use Tests\TestCase;

/**
 * A person's reply in a lead conversation reaches the customer with no workflow rule: the
 * conversation says who, the message's kind says how.
 */
final class DeliverHumanReplyTest extends TestCase
{
    use BuildsCommunicationFixtures;
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'workflow', 'crm', 'social', 'intelligence'];

    private FakeTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();

        $this->transport = $this->useFakeProvider();
    }

    public function testAPersonsReplyInAConversationIsQueuedForTheCustomer(): void
    {
        $lead = $this->leadOf($this->personWithCellphone('+18095550701'));
        $channel = $this->conversationChannel($lead);
        Queue::fake();

        $note = $this->fileInConversation($channel, $lead, 'sms', ['content' => 'Seen it', 'from_me' => true]);
        $this->assertEmpty(Queue::listenersPushed(DeliverHumanReplyListener::class));

        $reply = $this->fileInConversation($channel, $lead, 'sms', ['content' => 'On my way', 'from_me' => true, 'from_human' => true]);
        $queued = Queue::listenersPushed(DeliverHumanReplyListener::class);

        $this->assertNotEmpty($queued);
        $queued->each(function (CallQueuedListener $job) use ($reply, $note): void {
            /** @var ChannelMessageAttachedEvent $event */
            $event = $job->data[0];

            $this->assertTrue($event->message->is($reply));
            $this->assertFalse($event->message->is($note));
            $this->assertSame(1, $job->tries, 'a retry after the send would message the customer twice');
        });
    }

    public function testWhatIsWrittenOnAnInternalChannelStaysThere(): void
    {
        $lead = $this->leadOf($this->personWithCellphone('+18095550702'));
        $channel = $this->conversationChannel($lead);
        $channel->forceFill(['name' => ChannelNameEnum::NOTES->value])->saveOrFail();
        Queue::fake();

        $this->fileInConversation($channel, $lead, 'sms', ['content' => 'Call him Monday', 'from_me' => true, 'from_human' => true]);

        $this->assertEmpty(Queue::listenersPushed(DeliverHumanReplyListener::class));
    }

    public function testTheReplyLeavesOnTheCompanysConnectionAndIsLinkedToTheMessage(): void
    {
        $connection = $this->defaultSmsConnection(number: '+18095550703');
        $lead = $this->leadOf($this->personWithCellphone('+18095550704'));
        $channel = $this->conversationChannel($lead);
        $reply = $this->written($channel, $lead, 'On my way');

        $result = $this->deliver($channel, $reply);

        $delivery = Delivery::query()->where('connection_id', $connection->getId())->sole();

        $this->assertSame('+18095550704', $this->transport->sent[0]['to']);
        $this->assertStringStartsWith('On my way', $this->transport->sent[0]['text']);
        $this->assertSame($reply->getId(), (int) $delivery->message_id);
        $this->assertTrue($result['delivered']);
        $this->assertTrue((bool) $reply->fresh()->is_un_response, 'a delivered reply answers the conversation');
        $this->assertCount(0, $this->notDeliveredNotes($lead));
    }

    public function testAReplyTheProviderRefusedIsNotedOnTheLeadAndAnswersNothing(): void
    {
        $this->defaultSmsConnection(number: '+18095550706');
        $lead = $this->leadOf($this->personWithCellphone('+18095550707'));
        $channel = $this->conversationChannel($lead);
        $inbound = $this->fileInConversation($channel, $lead, 'sms', ['content' => 'Is it still available?']);
        $reply = $this->written($channel, $lead, 'Yes, come by');
        $this->transport->sendResult = TransportResult::failed('30003', 'Unreachable destination handset');

        $result = $this->deliver($channel, $reply);

        $this->assertFalse($result['delivered']);
        $this->assertFalse((bool) $inbound->fresh()->is_un_response, 'the customer is still waiting for an answer');

        $notes = $this->notDeliveredNotes($lead);
        $this->assertCount(1, $notes);
        $this->assertStringContainsString('Unreachable destination handset', $notes->first()->message['content']);
        $this->assertStringContainsString('Yes, come by', $notes->first()->message['content']);
    }

    public function testACompanyThatCannotSendOnThatMediumSendsNothing(): void
    {
        $lead = $this->leadOf($this->personWithCellphone('+18095550705'));
        $channel = $this->conversationChannel($lead);
        $reply = $this->written($channel, $lead, 'On my way');

        $result = $this->deliver($channel, $reply);

        $this->assertNull($result['result']);
        $this->assertFalse($result['delivered']);
        $this->assertStringContainsString('no sms connection', $result['message']);
        $this->assertSame([], $this->transport->sent);
        $this->assertCount(1, $this->notDeliveredNotes($lead));
    }

    /**
     * @return array{message: string, result: array<string, mixed>|null, delivered: bool}
     */
    private function deliver(Channel $channel, Message $reply): array
    {
        return new DeliverConversationMessageAction($channel, $reply, writtenByPerson: true)->execute();
    }

    /**
     * @return Collection<int, Message>
     */
    private function notDeliveredNotes(Lead $lead): Collection
    {
        return MessagesRepository::getLatestMessagesByLead($lead, 20)
            ->filter(fn (Message $message): bool => $message->tags()
                ->where('name', DeliverConversationMessageAction::NOT_DELIVERED_TAG)
                ->exists())
            ->values();
    }

    private function written(Channel $channel, Lead $lead, string $text): Message
    {
        Queue::fake();

        return $this->fileInConversation($channel, $lead, 'sms', ['content' => $text, 'from_me' => true, 'from_human' => true]);
    }
}
