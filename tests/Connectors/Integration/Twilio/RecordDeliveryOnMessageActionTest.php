<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Twilio;

use Illuminate\Broadcasting\Channel as PusherChannel;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Twilio\Actions\RecordDeliveryOnMessageAction;
use Kanvas\Connectors\Twilio\Actions\StoreMessageSidAction;
use Kanvas\Connectors\Twilio\Enums\MessageStatusEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Social\Channels\Actions\CreateChannelAction;
use Kanvas\Social\Channels\DataTransferObject\Channel as ChannelDto;
use Kanvas\Social\Channels\Events\ChannelMessageCreatedEvent;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Events\MessageDeliveryStatusEvent;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\SystemModules\Models\SystemModules;
use Tests\TestCase;

final class RecordDeliveryOnMessageActionTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = [null, 'social', 'crm', 'workflow'];

    public function testAcceptedSendWritesStatusAndSidIntoTheBody(): void
    {
        $message = $this->createMessage(['content' => 'Hi there']);

        new StoreMessageSidAction($message)->execute([
            'channel' => 'sms',
            'messages' => [['sid' => 'SM-accepted', 'status' => 'queued', 'to' => '+18095551234']],
        ]);

        $body = $message->fresh()->getMessage();
        $this->assertSame('Hi there', $body['content']);
        $this->assertSame('queued', $body['delivery']['status']);
        $this->assertSame('SM-accepted', $body['delivery']['message_sid']);
        $this->assertNull($body['delivery']['error_code']);
    }

    public function testRejectedSendWritesFailedAndTheTwilioCode(): void
    {
        $message = $this->createMessage(['content' => 'Hi there']);

        new StoreMessageSidAction($message)->execute([
            'status' => 'error',
            'twilio_error_code' => 21211,
            'error' => 'Invalid To number',
            'messages' => [],
        ]);

        $delivery = $message->fresh()->getMessage()['delivery'];
        $this->assertSame('failed', $delivery['status']);
        $this->assertSame('21211', $delivery['error_code']);
        $this->assertNull($delivery['message_sid']);
    }

    public function testALaterStatusKeepsTheSidItAlreadyHad(): void
    {
        $message = $this->createMessage(['content' => 'Hi there']);
        new RecordDeliveryOnMessageAction($message)->execute(MessageStatusEnum::QUEUED, 'SM-keep');

        new RecordDeliveryOnMessageAction($message->fresh())->execute(MessageStatusEnum::DELIVERED);

        $delivery = $message->fresh()->getMessage()['delivery'];
        $this->assertSame('delivered', $delivery['status']);
        $this->assertSame('SM-keep', $delivery['message_sid']);
    }

    public function testDeliveredStatusIsPushedToTheMessageChannels(): void
    {
        Event::fake([MessageDeliveryStatusEvent::class]);
        $message = $this->createMessage(['content' => 'Hi there']);
        $channel = $this->attachToChannel($message);
        new RecordDeliveryOnMessageAction($message)->execute(MessageStatusEnum::QUEUED, 'SM-delivered');

        new RecordDeliveryOnMessageAction($message->fresh())->execute(MessageStatusEnum::DELIVERED);

        Event::assertDispatchedTimes(MessageDeliveryStatusEvent::class, 1);
        Event::assertDispatched(
            MessageDeliveryStatusEvent::class,
            function (MessageDeliveryStatusEvent $event) use ($channel, $message): bool {
                $payload = $event->broadcastWith();

                return $event->broadcastAs() === 'message.delivery.updated'
                    && $payload['id'] === $message->getId()
                    && $payload['channel_id'] === $channel->getId()
                    && $payload['delivery']['status'] === 'delivered'
                    && $payload['delivery']['message_sid'] === 'SM-delivered'
                    && $payload['delivery']['error_code'] === null;
            }
        );
    }

    public function testRejectedSendIsPushedWithTheTwilioError(): void
    {
        Event::fake([MessageDeliveryStatusEvent::class]);
        $message = $this->createMessage(['content' => 'Hi there']);
        $this->attachToChannel($message);

        new StoreMessageSidAction($message)->execute([
            'status' => 'error',
            'twilio_error_code' => 21211,
            'error' => 'Invalid To number',
            'messages' => [],
        ]);

        Event::assertDispatched(
            MessageDeliveryStatusEvent::class,
            fn (MessageDeliveryStatusEvent $event): bool => $event->delivery['status'] === 'failed'
                && $event->delivery['error_code'] === '21211'
                && $event->delivery['error_message'] === 'Invalid To number'
        );
    }

    public function testInFlightStatusIsNotPushed(): void
    {
        Event::fake([MessageDeliveryStatusEvent::class]);
        $message = $this->createMessage(['content' => 'Hi there']);
        $this->attachToChannel($message);

        new StoreMessageSidAction($message)->execute([
            'messages' => [['sid' => 'SM-queued', 'status' => 'queued']],
        ]);
        new RecordDeliveryOnMessageAction($message->fresh())->execute(MessageStatusEnum::SENT);

        Event::assertNotDispatched(MessageDeliveryStatusEvent::class);
    }

    public function testDeliveryEventReachesTheSamePusherChannelsAsANewMessage(): void
    {
        $message = $this->createMessage(['content' => 'Hi there']);
        $channel = $this->attachToChannel($message);

        $names = static fn (array $channels): array => array_map(
            static fn (PusherChannel $pusherChannel): string => $pusherChannel->name,
            $channels,
        );

        $this->assertSame(
            $names(new ChannelMessageCreatedEvent($channel, $message)->broadcastOn()),
            $names(new MessageDeliveryStatusEvent($channel, $message, [])->broadcastOn()),
        );
    }

    private function attachToChannel(Message $message): Channel
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        SystemModules::firstOrCreate(
            ['model_name' => People::class, 'apps_id' => $app->getId()],
            ['name' => 'People', 'slug' => 'people', 'description' => 'People system module']
        );
        $people = People::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId($user->getId())
            ->create();

        $channel = new CreateChannelAction(
            ChannelDto::from([
                'apps' => $app,
                'companies' => $company,
                'users' => $user,
                'entity_id' => $people->getId(),
                'entity_namespace' => People::class,
                'name' => 'SMS ' . $people->getId(),
                'slug' => 'sms-delivery-' . $people->getId(),
            ])
        )->execute();
        $channel->messages()->attach($message->getId(), ['users_id' => $user->getId()]);

        return $channel;
    }

    private function createMessage(array $body): Message
    {
        $user = auth()->user();

        return Message::factory()
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId($user->getCurrentCompany()->getId())
            ->create([
                'users_id' => $user->getId(),
                'message' => $body,
            ]);
    }
}
