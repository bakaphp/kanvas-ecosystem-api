<?php

declare(strict_types=1);

namespace Tests\Connectors\Movipass;

use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Movipass\Enums\OrderTypeEnum;
use Kanvas\Connectors\Movipass\Listeners\SendRoadsideChatMessagePushListener;
use Kanvas\Connectors\Movipass\Notifications\RoadsideChatMessageNotification;
use Kanvas\Social\Channels\Events\ChannelMessageCreatedEvent;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Models\OrderTypes;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

final class RoadsideChatMessagePushTest extends TestCase
{
    protected Apps $apps;
    protected Users $authUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->apps = app(Apps::class);
        $this->authUser = auth()->user();
    }

    public function testExpoPayloadCarriesChannelSlug(): void
    {
        $order = $this->createOrder(OrderTypeEnum::ROADSIDE_ASSISTANCE->value);

        $notification = new RoadsideChatMessageNotification(
            $order,
            'roadside-abc-123',
            'Juan Pérez',
            'Hi, where are you?',
            999,
        );

        // Regression: channel_slug must be a top-level scalar so it survives the Expo
        // scalar-only data filter — that's the payload the app deep-links the chat with.
        $expo = $notification->toExpo($this->authUser)->toArray();

        $this->assertSame('New message from Juan Pérez', $expo['title']);
        $this->assertSame('Hi, where are you?', $expo['body']);
        $this->assertSame('roadside-abc-123', $expo['data']['channel_slug']);
    }

    public function testOneSignalPayloadCarriesChannelSlug(): void
    {
        $order = $this->createOrder(OrderTypeEnum::ROADSIDE_ASSISTANCE->value);

        $notification = new RoadsideChatMessageNotification(
            $order,
            'roadside-abc-123',
            'Juan Pérez',
            'Hi, where are you?',
            999,
        );

        $oneSignal = $notification->toOneSignal($this->authUser);

        $this->assertSame('New message from Juan Pérez', $oneSignal['title']);
        $this->assertSame($this->authUser->getId(), $oneSignal['user_id']);
        $this->assertSame('roadside-abc-123', $oneSignal['data']['channel_slug']);
    }

    public function testListenerNotifiesCounterpartyOnRoadsideChat(): void
    {
        $order = $this->createOrder(OrderTypeEnum::ROADSIDE_ASSISTANCE->value);
        $mechanic = Users::factory()->create();
        $channel = $this->createOrderChannel($order, [$this->authUser->getId(), $mechanic->getId()]);
        $message = $this->createChannelMessage($channel, $this->authUser);

        Notification::fake();

        new SendRoadsideChatMessagePushListener()->handle(
            new ChannelMessageCreatedEvent($channel, $message),
        );

        Notification::assertSentTo($mechanic, RoadsideChatMessageNotification::class);
        // The sender never gets pushed for their own message.
        Notification::assertNotSentTo($this->authUser, RoadsideChatMessageNotification::class);
    }

    public function testListenerIgnoresNonRoadsideOrderChat(): void
    {
        $order = $this->createOrder(OrderTypeEnum::MOVIPASS->value);
        $mechanic = Users::factory()->create();
        $channel = $this->createOrderChannel($order, [$this->authUser->getId(), $mechanic->getId()]);
        $message = $this->createChannelMessage($channel, $this->authUser);

        Notification::fake();

        new SendRoadsideChatMessagePushListener()->handle(
            new ChannelMessageCreatedEvent($channel, $message),
        );

        Notification::assertNothingSent();
    }

    private function createOrder(string $type): Order
    {
        $orderType = OrderTypes::firstOrCreate([
            'name' => $type,
            'apps_id' => $this->apps->getId(),
        ]);

        $case = [
            'service' => 'Cambio de neumático',
            'location' => ['lat' => 18.486, 'lng' => -69.931],
        ];

        return Order::factory()
            ->withCompanyId($this->authUser->getCurrentCompany()->getId())
            ->withUserId($this->authUser->getId())
            ->create([
                'order_types_id' => $orderType->getId(),
                'user_email' => 'cliente@movipass.test',
                'user_phone' => '8090000000',
                'metadata' => ['assistance_case' => $case],
            ]);
    }

    private function createOrderChannel(Order $order, array $memberIds): Channel
    {
        $channel = Channel::create([
            'apps_id' => $this->apps->getId(),
            'companies_id' => $order->companies_id,
            'slug' => 'roadside-' . $order->uuid,
            'name' => 'Roadside ' . $order->getId(),
            'description' => 'Roadside assistance chat',
            'entity_id' => $order->getId(),
            'entity_namespace' => Order::class,
            'users_id' => $this->authUser->getId(),
        ]);

        foreach ($memberIds as $memberId) {
            $channel->users()->attach($memberId, ['roles_id' => 2]);
        }

        return $channel;
    }

    private function createChannelMessage(Channel $channel, Users $sender): Message
    {
        $messageType = MessageType::factory()->create();

        return Message::create([
            'apps_id' => $this->apps->getId(),
            'companies_id' => $channel->companies_id,
            'users_id' => $sender->getId(),
            'message_types_id' => $messageType->getId(),
            'message' => ['message' => 'Hi, where are you?'],
            'is_public' => 1,
        ]);
    }
}
