<?php

declare(strict_types=1);

namespace Tests\Guild\Leads;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Queue;
use Kanvas\Communication\Messages\DataTransferObject\TransportResult;
use Kanvas\Communication\Messages\Models\Delivery;
use Kanvas\Guild\Leads\Actions\Communication\DeliverConversationMessageAction;
use Kanvas\Guild\Leads\Activities\DeliverHumanReplyActivity;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Channels\Enums\ChannelNameEnum;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\Messages\Repositories\MessagesRepository;
use Kanvas\Workflow\Enums\StatusEnum;
use Kanvas\Workflow\Models\StoredWorkflow;
use Tests\Communication\Concerns\BuildsCommunicationFixtures;
use Tests\Communication\Fixtures\FakeTransport;
use Tests\TestCase;

/**
 * A person's reply in a lead conversation reaches the customer through the app default rule's
 * activity: the conversation says who, the message's kind says how.
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

    public function testOnlyAPersonsReplyInAConversationIsDeliveredAndNeverRetried(): void
    {
        $lead = $this->leadOf($this->personWithCellphone('+18095550701'));
        $channel = $this->conversationChannel($lead);
        Queue::fake();

        $note = $this->fileInConversation(
            $channel,
            $lead,
            'sms',
            ['content' => 'Seen it', 'from_me' => true]
        );
        [$activity, $result] = $this->runActivity($channel, $note);

        $this->assertSame(1, $activity->tries, 'a retry after the send would message the customer twice');
        $this->assertTrue($result['skipped']);
        $this->assertSame(StatusEnum::SKIPPED, $activity->workflowStatus());
        $this->assertSame([], $this->transport->sent);
    }

    public function testWhatIsWrittenOnAnInternalChannelStaysThere(): void
    {
        $this->defaultSmsConnection(number: '+18095550708');
        $lead = $this->leadOf($this->personWithCellphone('+18095550702'));
        $channel = $this->conversationChannel($lead);
        $channel->forceFill(['name' => ChannelNameEnum::NOTES->value])->saveOrFail();
        Queue::fake();

        $reply = $this->fileInConversation(
            $channel,
            $lead,
            'sms',
            ['content' => 'Call him Monday', 'from_me' => true, 'from_human' => true]
        );
        [$activity, $result] = $this->runActivity($channel, $reply);

        $this->assertTrue($result['skipped']);
        $this->assertSame(StatusEnum::SKIPPED, $activity->workflowStatus());
        $this->assertSame([], $this->transport->sent);

        $channel->forceFill(['name' => ChannelNameEnum::DEFAULT->value])->saveOrFail();
        [$activity] = $this->runActivity($channel, $reply);

        $this->assertSame(StatusEnum::SKIPPED, $activity->workflowStatus(), 'nor on the default channel');
        $this->assertSame([], $this->transport->sent);
    }

    public function testTheActivitySendsAPersonsReply(): void
    {
        $this->defaultSmsConnection(number: '+18095550709');
        $lead = $this->leadOf($this->personWithCellphone('+18095550710'));
        $channel = $this->conversationChannel($lead);
        $reply = $this->written($channel, $lead, 'On my way');

        [$activity, $result] = $this->runActivity($channel, $reply);

        $this->assertNull($activity->workflowStatus());
        $this->assertTrue($result['delivered']);
        $this->assertSame('+18095550710', $this->transport->sent[0]['to']);
    }

    /**
     * The reply did not leave: the action already noted it on the lead, and the run is a failure.
     */
    public function testAnUndeliveredReplyFailsTheRun(): void
    {
        $lead = $this->leadOf($this->personWithCellphone('+18095550711'));
        $channel = $this->conversationChannel($lead);
        $reply = $this->written($channel, $lead, 'On my way');

        [$activity, $result] = $this->runActivity($channel, $reply);

        $this->assertSame(StatusEnum::FAILED, $activity->workflowStatus());
        $this->assertTrue($result['failed']);
        $this->assertFalse($result['delivered']);
        $this->assertCount(1, $this->notDeliveredNotes($lead));
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
        foreach (['lead-channel-' . $lead->getId(), 'people-channel-' . $lead->people->getId()] as $history) {
            $this->assertTrue(
                Channel::query()
                    ->where('slug', $history)
                    ->sole()
                    ->messages()
                    ->whereKey($reply->getId())
                    ->exists(),
                'a delivered reply is on ' . $history
            );
        }
        $this->assertCount(0, $this->notDeliveredNotes($lead));
    }

    /**
     * The customer wrote to an agent's own number. A person answering in that conversation leaves
     * from the same number, not from the company's default, and needs no default to do it.
     */
    public function testAReplyLeavesFromTheNumberTheCustomerWroteToNotTheCompanysDefault(): void
    {
        $default = $this->defaultSmsConnection(number: '+18095550710');
        $conversationNumber = $this->connection('fake', '+18095550711');
        $lead = $this->leadOf($this->personWithCellphone('+18095550712'));
        $channel = $this->conversationChannel($lead);
        $this->fileInConversation(
            $channel,
            $lead,
            'sms',
            [
                'content' => 'Is it still available?',
                'from_me' => false,
                'communication' => ['connection_id' => $conversationNumber->getId(), 'channel' => 'sms'],
            ]
        );
        $reply = $this->written($channel, $lead, 'Yes, it is');

        $result = $this->deliver($channel, $reply);

        $this->assertTrue($result['delivered']);
        $this->assertSame(
            $conversationNumber->getId(),
            (int) Delivery::query()->where('message_id', $reply->getId())->sole()->connection_id
        );
        $this->assertSame(0, Delivery::query()->where('connection_id', $default->getId())->count());

        $conversationNumber->forceFill(['enabled' => false])->saveOrFail();
        $second = $this->written($channel, $lead, 'Come by today');

        $this->deliver($channel, $second);

        $this->assertSame(
            $default->getId(),
            (int) Delivery::query()->where('message_id', $second->getId())->sole()->connection_id,
            'a number that can no longer send falls back to the default'
        );
    }

    public function testAReplyTheProviderRefusedIsNotedOnTheLeadAndAnswersNothing(): void
    {
        $this->defaultSmsConnection(number: '+18095550706');
        $lead = $this->leadOf($this->personWithCellphone('+18095550707'));
        $channel = $this->conversationChannel($lead);
        $inbound = $this->fileInConversation(
            $channel,
            $lead,
            'sms',
            ['content' => 'Is it still available?']
        );
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
     * @return array{0: DeliverHumanReplyActivity, 1: array<string, mixed>}
     */
    private function runActivity(Channel $channel, Message $message): array
    {
        $activity = new DeliverHumanReplyActivity(
            0,
            now()->toDateTimeString(),
            new StoredWorkflow(),
            []
        );

        return [$activity, $activity->execute($channel, $channel->app, ['message' => $message])];
    }

    /**
     * @return array{message: string, result: array<string, mixed>|null, delivered: bool, failed: bool}
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

        return $this->fileInConversation(
            $channel,
            $lead,
            'sms',
            ['content' => $text, 'from_me' => true, 'from_human' => true]
        );
    }
}
