<?php

declare(strict_types=1);

namespace Tests\Social\Integration;

use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Social\Channels\Events\ChannelMessageCreatedEvent;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Actions\CreateMessageTypeAction;
use Kanvas\Social\MessagesTypes\DataTransferObject\MessageTypeInput;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

/** The channel-level push carries an id, never the body, so it must be enough to fetch and route. */
final class ChannelMessageBroadcastTest extends TestCase
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

    public function testAddingAPublicMessageBroadcastsARoutableHandle(): void
    {
        Event::fake([ChannelMessageCreatedEvent::class]);

        $channel = $this->makeChannel();
        $message = $this->makeMessage(isPublic: 1);

        $channel->addMessage($message, $this->actingUser);

        Event::assertDispatched(
            ChannelMessageCreatedEvent::class,
            function (ChannelMessageCreatedEvent $event) use ($channel, $message): bool {
                $payload = $event->broadcastWith();

                return $payload['id'] === $message->id
                    && $payload['channel_id'] === $channel->id
                    && $payload['channel_slug'] === $channel->slug;
            },
        );
    }

    /**
     * Asserts the routing, not the property: only BroadcastManager honouring `$broadcastQueue`
     * actually changes delivery, so a property assertion would pass while everything stayed on default.
     */
    public function testBroadcastIsRoutedToItsOwnQueueNotDefault(): void
    {
        Queue::fake();

        $channel = $this->makeChannel();
        $message = $this->makeMessage(isPublic: 1);

        $channel->addMessage($message, $this->actingUser);

        Queue::assertPushedOn('broadcasts', BroadcastEvent::class);
    }

    /** Private/locked messages are held back (support mode), so the stream is not a full mirror. */
    public function testPrivateMessageIsNotBroadcast(): void
    {
        Event::fake([ChannelMessageCreatedEvent::class]);

        $channel = $this->makeChannel();
        $message = $this->makeMessage(isPublic: 0);

        $channel->addMessage($message, $this->actingUser);

        Event::assertNotDispatched(ChannelMessageCreatedEvent::class);
    }

    private function makeChannel(): Channel
    {
        return Channel::create([
            'apps_id' => $this->currentApp->getId(),
            'companies_id' => $this->currentCompany->getId(),
            'users_id' => $this->actingUser->getId(),
            'name' => 'Broadcast channel ' . uniqid(),
            'description' => 'Broadcast channel',
            'slug' => 'broadcast-channel-' . uniqid(),
        ]);
    }

    private function makeMessage(int $isPublic): Message
    {
        $type = new CreateMessageTypeAction(
            new MessageTypeInput(
                apps_id: $this->currentApp->getId(),
                languages_id: 1,
                name: 'broadcast-test-' . uniqid(),
                verb: 'broadcast-test-' . uniqid(),
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
            )
        );
        $action->runWorkflow = false;

        return $action->execute();
    }
}
