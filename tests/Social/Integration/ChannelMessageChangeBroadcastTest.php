<?php

declare(strict_types=1);

namespace Tests\Social\Integration;

use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Broadcasting\Channel as BroadcastChannel;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Leads\Enums\LeadMessageTypeEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Channels\Events\ChannelMessageChangedEvent;
use Kanvas\Social\Channels\Events\ChannelMessageCreatedEvent;
use Kanvas\Social\Channels\Events\ChannelMessageRemovedEvent;
use Kanvas\Social\Channels\Events\ChannelMessageUpdatedEvent;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Actions\CreateMessageTypeAction;
use Kanvas\Social\MessagesTypes\DataTransferObject\MessageTypeInput;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

/** Edits and deletes of a note reach other open clients; every other message type stays silent. */
final class ChannelMessageChangeBroadcastTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'social'];

    private Apps $currentApp;
    private Companies $currentCompany;
    private Users $actingUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->currentApp = app(Apps::class);
        $this->actingUser = auth()->user();
        $this->currentCompany = $this->actingUser->getCurrentCompany();
    }

    public function testUpdatedIsDispatchedOncePerChannel(): void
    {
        $this->fakeBroadcastEvents();

        $first = $this->makeChannel();
        $second = $this->makeChannel();
        $note = $this->makeMessage(LeadMessageTypeEnum::NOTES->value);
        $this->attach($note, $first, $second);

        $note->message = ['content' => 'edited'];
        $note->saveOrFail();

        Event::assertDispatchedTimes(ChannelMessageUpdatedEvent::class, 2);

        foreach ([$first, $second] as $channel) {
            Event::assertDispatched(
                ChannelMessageUpdatedEvent::class,
                fn (ChannelMessageUpdatedEvent $event): bool => $event->broadcastWith() === [
                    'id' => $note->id,
                    'slug' => $note->slug,
                    'channel_id' => $channel->id,
                    'channel_slug' => $channel->slug,
                ]
            );
        }

        Event::assertNotDispatched(ChannelMessageRemovedEvent::class);
    }

    public function testUpdatedIgnoresOtherAttributes(): void
    {
        $this->fakeBroadcastEvents();

        $note = $this->makeMessage(LeadMessageTypeEnum::NOTES->value);
        $this->attach($note, $this->makeChannel());

        $note->total_children = 3;
        $note->saveOrFail();

        Event::assertNotDispatched(ChannelMessageUpdatedEvent::class);
    }

    public function testUpdatedIgnoresNonNoteMessages(): void
    {
        $this->fakeBroadcastEvents();

        $message = $this->makeMessage('broadcast-test-' . uniqid());
        $this->attach($message, $this->makeChannel());

        $message->message = ['content' => 'edited by a server'];
        $message->saveOrFail();

        Event::assertNotDispatched(ChannelMessageUpdatedEvent::class);
    }

    public function testNoEventForPrivateOrLockedMessage(): void
    {
        $this->fakeBroadcastEvents();

        $channel = $this->makeChannel();
        $private = $this->makeMessage(LeadMessageTypeEnum::NOTES->value, isPublic: 0);
        $locked = $this->makeMessage(LeadMessageTypeEnum::NOTES->value, isLocked: 1);
        $this->attach($private, $channel);
        $this->attach($locked, $channel);

        foreach ([$private, $locked] as $note) {
            $note->message = ['content' => 'edited'];
            $note->saveOrFail();
            $note->delete();
            $note->restore();
        }

        Event::assertNotDispatched(ChannelMessageUpdatedEvent::class);
        Event::assertNotDispatched(ChannelMessageRemovedEvent::class);
        Event::assertNotDispatched(ChannelMessageCreatedEvent::class);
    }

    public function testRemovedIsDispatchedAfterSoftDelete(): void
    {
        $this->fakeBroadcastEvents();

        $first = $this->makeChannel();
        $second = $this->makeChannel();
        $note = $this->makeMessage(LeadMessageTypeEnum::NOTES->value);
        $this->attach($note, $first, $second);

        $note->delete();

        Event::assertDispatchedTimes(ChannelMessageRemovedEvent::class, 2);

        foreach ([$first, $second] as $channel) {
            Event::assertDispatched(
                ChannelMessageRemovedEvent::class,
                fn (ChannelMessageRemovedEvent $event): bool => $event->broadcastWith()['id'] === $note->id
                    && $event->broadcastWith()['channel_id'] === $channel->id
            );
        }
    }

    public function testRemovedEventSurvivesSerialization(): void
    {
        $this->fakeBroadcastEvents();

        $channel = $this->makeChannel();
        $note = $this->makeMessage(LeadMessageTypeEnum::NOTES->value);
        $this->attach($note, $channel);

        $note->delete();

        $this->assertNull(Message::find($note->id), 'a deleted message must be invisible to the queue worker');

        $dispatched = Event::dispatched(ChannelMessageRemovedEvent::class)->first()[0];
        $revived = unserialize(serialize($dispatched));

        $this->assertSame($dispatched->broadcastWith(), $revived->broadcastWith());
        $this->assertEquals($dispatched->broadcastOn(), $revived->broadcastOn());
    }

    public function testBroadcastNamesAndChannelsMatchCreated(): void
    {
        $this->fakeBroadcastEvents();

        $channel = $this->makeChannel();
        $channel->entity_namespace = Lead::class;
        $channel->entity_id = '987';
        $channel->saveOrFail();
        $note = $this->makeMessage(LeadMessageTypeEnum::NOTES->value);
        $this->attach($note, $channel);

        $note->message = ['content' => 'edited'];
        $note->saveOrFail();
        $note->delete();

        $created = new ChannelMessageCreatedEvent($channel, $note);
        $names = fn (array $channels): array => array_map(
            fn (BroadcastChannel $broadcastChannel): string => $broadcastChannel->name,
            $channels
        );

        $updated = Event::dispatched(ChannelMessageUpdatedEvent::class)->first()[0];
        $removed = Event::dispatched(ChannelMessageRemovedEvent::class)->first()[0];

        $this->assertSame('message.channel.updated', $updated->broadcastAs());
        $this->assertSame('message.channel.removed', $removed->broadcastAs());
        $this->assertSame($created->broadcastWith(), $updated->broadcastWith());
        $this->assertSame($names($created->broadcastOn()), $names($updated->broadcastOn()));
        $this->assertSame($names($created->broadcastOn()), $names($removed->broadcastOn()));
        $this->assertCount(3, $updated->broadcastOn());
    }

    public function testRoutedOnBroadcastsQueue(): void
    {
        Queue::fake();

        $note = $this->makeMessage(LeadMessageTypeEnum::NOTES->value);
        $this->attach($note, $this->makeChannel());

        $note->message = ['content' => 'edited'];
        $note->saveOrFail();

        Queue::assertPushedOn('broadcasts', BroadcastEvent::class);
    }

    public function testEventsAreDispatchedAfterCommit(): void
    {
        $this->assertTrue(is_subclass_of(ChannelMessageUpdatedEvent::class, ChannelMessageChangedEvent::class));
        $this->assertTrue(is_subclass_of(ChannelMessageRemovedEvent::class, ChannelMessageChangedEvent::class));
        $this->assertTrue(is_subclass_of(ChannelMessageChangedEvent::class, ShouldDispatchAfterCommit::class));
    }

    public function testRestoredNoteIsBroadcastAsAdded(): void
    {
        $this->fakeBroadcastEvents();

        $first = $this->makeChannel();
        $second = $this->makeChannel();
        $note = $this->makeMessage(LeadMessageTypeEnum::NOTES->value);
        $this->attach($note, $first, $second);
        $note->delete();

        $note->restore();

        Event::assertDispatchedTimes(ChannelMessageCreatedEvent::class, 2);
        Event::assertNotDispatched(ChannelMessageUpdatedEvent::class);

        foreach ([$first, $second] as $channel) {
            Event::assertDispatched(
                ChannelMessageCreatedEvent::class,
                fn (ChannelMessageCreatedEvent $event): bool => $event->broadcastWith()['id'] === $note->id
                    && $event->broadcastWith()['channel_id'] === $channel->id
            );
        }
    }

    public function testRestoredNonNoteIsNotBroadcast(): void
    {
        $this->fakeBroadcastEvents();

        $message = $this->makeMessage('broadcast-test-' . uniqid());
        $this->attach($message, $this->makeChannel());
        $message->delete();

        $message->restore();

        Event::assertNotDispatched(ChannelMessageCreatedEvent::class);
    }

    private function fakeBroadcastEvents(): void
    {
        Event::fake([
            ChannelMessageCreatedEvent::class,
            ChannelMessageUpdatedEvent::class,
            ChannelMessageRemovedEvent::class,
        ]);
    }

    private function attach(Message $message, Channel ...$channels): void
    {
        foreach ($channels as $channel) {
            $channel->messages()->attach($message->id, ['users_id' => $this->actingUser->getId()]);
        }

        $message->unsetRelation('channels');
    }

    private function makeChannel(): Channel
    {
        return Channel::create([
            'apps_id' => $this->currentApp->getId(),
            'companies_id' => $this->currentCompany->getId(),
            'users_id' => $this->actingUser->getId(),
            'name' => 'Change broadcast channel ' . uniqid(),
            'description' => 'Change broadcast channel',
            'slug' => 'change-broadcast-channel-' . uniqid(),
        ]);
    }

    private function makeMessage(string $verb, int $isPublic = 1, int $isLocked = 0): Message
    {
        $type = new CreateMessageTypeAction(
            new MessageTypeInput(
                apps_id: $this->currentApp->getId(),
                languages_id: 1,
                name: $verb,
                verb: $verb,
            )
        )->execute();

        $action = new CreateMessageAction(
            new MessageInput(
                app: $this->currentApp,
                company: $this->currentCompany,
                user: $this->actingUser,
                type: $type,
                message: ['content' => 'hello channel'],
                is_public: $isPublic,
                is_locked: $isLocked,
            )
        );
        $action->runWorkflow = false;

        return $action->execute();
    }
}
