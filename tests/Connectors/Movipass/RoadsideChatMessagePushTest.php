<?php

declare(strict_types=1);

namespace Tests\Connectors\Movipass;

use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Movipass\Actions\SendRoadsideChatMessagePushAction;
use Kanvas\Connectors\Movipass\Enums\OrderTypeEnum;
use Kanvas\Connectors\Movipass\Handlers\MovipassHandler;
use Kanvas\Connectors\Movipass\Notifications\RoadsideChatMessageNotification;
use Kanvas\Connectors\Movipass\Workflows\Activities\SendRoadsideChatMessagePushActivity;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Models\OrderTypes;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Models\StoredWorkflow;
use Tests\Connectors\Traits\HasIntegrationCompany;
use Tests\TestCase;

final class RoadsideChatMessagePushTest extends TestCase
{
    use HasIntegrationCompany;

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
        $expoData = json_decode((string) $expo['data'], true);
        $this->assertSame('roadside-abc-123', $expoData['channel_slug']);
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

    public function testActionNotifiesCounterpartyOnRoadsideChat(): void
    {
        $order = $this->createOrder(OrderTypeEnum::ROADSIDE_ASSISTANCE->value);
        $mechanic = Users::factory()->create();
        $channel = $this->createOrderChannel($order, [$this->authUser->getId(), $mechanic->getId()]);
        $message = $this->createChannelMessage($channel, $this->authUser);

        Notification::fake();

        $notified = new SendRoadsideChatMessagePushAction($channel, $message)->execute();

        $this->assertSame(1, $notified);
        Notification::assertSentTo($mechanic, RoadsideChatMessageNotification::class);
        // The sender never gets pushed for their own message.
        Notification::assertNotSentTo($this->authUser, RoadsideChatMessageNotification::class);
    }

    public function testActionIgnoresNonRoadsideOrderChat(): void
    {
        $order = $this->createOrder(OrderTypeEnum::MOVIPASS->value);
        $mechanic = Users::factory()->create();
        $channel = $this->createOrderChannel($order, [$this->authUser->getId(), $mechanic->getId()]);
        $message = $this->createChannelMessage($channel, $this->authUser);

        Notification::fake();

        $notified = new SendRoadsideChatMessagePushAction($channel, $message)->execute();

        $this->assertSame(0, $notified);
        Notification::assertNothingSent();
    }

    public function testResolvesRoadsideOrderFromSlugWhenChannelNotOrderLinked(): void
    {
        $order = $this->createOrder(OrderTypeEnum::ROADSIDE_ASSISTANCE->value);
        $mechanic = Users::factory()->create();
        // Channel auto-created by the generic message flow: linked to the Message, not the Order.
        $channel = $this->createOrderChannel(
            $order,
            [$this->authUser->getId(), $mechanic->getId()],
            [
                'slug' => 'roadside-' . $order->uuid,
                'entity_id' => 999999,
                'entity_namespace' => Message::class,
            ],
        );
        $message = $this->createChannelMessage($channel, $this->authUser);

        Notification::fake();

        $notified = new SendRoadsideChatMessagePushAction($channel, $message)->execute();

        $this->assertSame(1, $notified);
        Notification::assertSentTo($mechanic, RoadsideChatMessageNotification::class);
    }

    public function testResolvesRoadsideOrderFromChannelMetadata(): void
    {
        $order = $this->createOrder(OrderTypeEnum::ROADSIDE_ASSISTANCE->value);
        $mechanic = Users::factory()->create();
        $channel = $this->createOrderChannel(
            $order,
            [$this->authUser->getId(), $mechanic->getId()],
            [
                'slug' => 'chat-' . $order->getId(),
                'entity_id' => 888888,
                'entity_namespace' => Message::class,
                'metadata' => ['order_uuid' => $order->uuid],
            ],
        );
        $message = $this->createChannelMessage($channel, $this->authUser);

        Notification::fake();

        $notified = new SendRoadsideChatMessagePushAction($channel, $message)->execute();

        $this->assertSame(1, $notified);
        Notification::assertSentTo($mechanic, RoadsideChatMessageNotification::class);
    }

    public function testReturnsZeroWhenNoOrderCanBeResolved(): void
    {
        $order = $this->createOrder(OrderTypeEnum::ROADSIDE_ASSISTANCE->value);
        $mechanic = Users::factory()->create();
        $channel = $this->createOrderChannel(
            $order,
            [$this->authUser->getId(), $mechanic->getId()],
            [
                'slug' => 'chat-no-order-ref',
                'entity_id' => 777777,
                'entity_namespace' => Message::class,
            ],
        );
        $message = $this->createChannelMessage($channel, $this->authUser);

        Notification::fake();

        $notified = new SendRoadsideChatMessagePushAction($channel, $message)->execute();

        $this->assertSame(0, $notified);
        Notification::assertNothingSent();
    }

    public function testActivityNotifiesCounterpartyWhenMessageCreated(): void
    {
        $order = $this->createOrder(OrderTypeEnum::ROADSIDE_ASSISTANCE->value);
        $mechanic = Users::factory()->create();
        $channel = $this->createOrderChannel($order, [$this->authUser->getId(), $mechanic->getId()]);
        $message = $this->createChannelMessage($channel, $this->authUser);
        $channel->messages()->attach($message->getKey(), ['users_id' => $this->authUser->getId()]);

        // Gate integration for the executeIntegration wrapper (integration-history logging).
        $this->setIntegration(
            $this->apps,
            IntegrationsEnum::MOVIPASS,
            MovipassHandler::class,
            $order->company,
            $this->authUser
        );

        Notification::fake();

        $result = new SendRoadsideChatMessagePushActivity(
            0,
            now()->toDateTimeString(),
            StoredWorkflow::make(),
            []
        )->execute($message, $this->apps, []);

        $this->assertTrue($result['result']);
        $this->assertSame(1, $result['recipients_notified']);
        Notification::assertSentTo($mechanic, RoadsideChatMessageNotification::class);
        // The sender never gets pushed for their own message.
        Notification::assertNotSentTo($this->authUser, RoadsideChatMessageNotification::class);
    }

    public function testActivityIgnoresNonRoadsideMessage(): void
    {
        $order = $this->createOrder(OrderTypeEnum::MOVIPASS->value);
        $mechanic = Users::factory()->create();
        $channel = $this->createOrderChannel($order, [$this->authUser->getId(), $mechanic->getId()]);
        $message = $this->createChannelMessage($channel, $this->authUser);
        $channel->messages()->attach($message->getKey(), ['users_id' => $this->authUser->getId()]);

        Notification::fake();

        $result = new SendRoadsideChatMessagePushActivity(
            0,
            now()->toDateTimeString(),
            StoredWorkflow::make(),
            []
        )->execute($message, $this->apps, []);

        $this->assertFalse($result['result']);
        $this->assertSame(0, $result['recipients_notified']);
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

    private function createOrderChannel(Order $order, array $memberIds, array $overrides = []): Channel
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
            ...$overrides,
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
