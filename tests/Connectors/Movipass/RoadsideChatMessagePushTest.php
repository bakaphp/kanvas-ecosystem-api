<?php

declare(strict_types=1);

namespace Tests\Connectors\Movipass;

use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Auth\Actions\RegisterUsersAppAction;
use Kanvas\Connectors\Movipass\Actions\SendRoadsideChatMessagePushAction;
use Kanvas\Connectors\Movipass\Enums\OrderTypeEnum;
use Kanvas\Connectors\Movipass\Handlers\MovipassHandler;
use Kanvas\Connectors\Movipass\Notifications\RoadsideChatMessageNotification;
use Kanvas\Connectors\Movipass\Workflows\Activities\SendRoadsideChatMessagePushActivity;
use Kanvas\Connectors\Movipass\Workflows\Activities\SyncMovipassRoadsideAssistanceActivity;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Models\OrderTypes;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Enums\WorkflowEnum;
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

    public function testActionIgnoresMessageWithoutAssistanceChatVerb(): void
    {
        $order = $this->createOrder(OrderTypeEnum::ROADSIDE_ASSISTANCE->value);
        $mechanic = Users::factory()->create();
        $channel = $this->createOrderChannel($order, [$this->authUser->getId(), $mechanic->getId()]);
        $message = $this->createChannelMessage($channel, $this->authUser, 'some-other-verb');

        Notification::fake();

        $notified = new SendRoadsideChatMessagePushAction($channel, $message)->execute();

        $this->assertSame(0, $notified);
        Notification::assertNothingSent();
    }

    public function testResolvesRoadsideOrderFromDirectMessageChannelMembers(): void
    {
        $mechanic = Users::factory()->create();
        $order = $this->createOrder(OrderTypeEnum::ROADSIDE_ASSISTANCE->value);
        // Stamp the assigned mechanic on the case: that's what ties the memberless DM channel
        // (dm-{userId}-{userId}, the real client payload) back to this roadside order.
        $order->metadata = [
            'assistance_case' => [
                ...$order->metadata['assistance_case'],
                'mechanic' => ['user_id' => $mechanic->getId()],
            ],
        ];
        $order->saveQuietly();

        $channel = $this->createOrderChannel(
            $order,
            [$this->authUser->getId(), $mechanic->getId()],
            [
                'slug' => 'dm-' . $this->authUser->getId() . '-' . $mechanic->getId(),
                'entity_id' => $mechanic->getId(),
                'entity_namespace' => Users::class,
            ],
        );
        $message = $this->createChannelMessage($channel, $this->authUser);

        Notification::fake();

        $notified = new SendRoadsideChatMessagePushAction($channel, $message)->execute();

        $this->assertSame(1, $notified);
        Notification::assertSentTo($mechanic, RoadsideChatMessageNotification::class);
        Notification::assertNotSentTo($this->authUser, RoadsideChatMessageNotification::class);
    }

    public function testNotifiesMechanicOnSingleMemberDirectMessageChannel(): void
    {
        $mechanic = Users::factory()->create();
        $order = $this->createOrder(OrderTypeEnum::ROADSIDE_ASSISTANCE->value);
        $order->metadata = [
            'assistance_case' => [
                ...$order->metadata['assistance_case'],
                'mechanic' => ['user_id' => $mechanic->getId()],
            ],
        ];
        $order->saveQuietly();

        // Real client payload: dm-{customer}-{mechanic}, ONLY the sender (customer) in the users
        // pivot (CreateChannelAction attaches the creator alone), entity points at the mechanic User,
        // no order reference anywhere. Order resolves from the slug/entity ids; recipient from the order.
        $channel = Channel::create([
            'apps_id' => $this->apps->getId(),
            'companies_id' => $order->companies_id,
            'slug' => 'dm-' . $this->authUser->getId() . '-' . $mechanic->getId(),
            'name' => 'DM',
            'description' => 'DM',
            'entity_id' => $mechanic->getId(),
            'entity_namespace' => Users::class,
            'users_id' => $this->authUser->getId(),
        ]);
        $channel->users()->attach($this->authUser->getId(), ['roles_id' => 2]);

        $message = $this->createChannelMessage($channel, $this->authUser);

        Notification::fake();

        $notified = new SendRoadsideChatMessagePushAction($channel, $message)->execute();

        $this->assertSame(1, $notified);
        Notification::assertSentTo($mechanic, RoadsideChatMessageNotification::class);
        Notification::assertNotSentTo($this->authUser, RoadsideChatMessageNotification::class);
    }

    public function testNotifiesCustomerWhenMechanicWritesOnSingleMemberDirectMessageChannel(): void
    {
        $mechanic = Users::factory()->create();

        new RegisterUsersAppAction($mechanic, $this->apps)->execute($mechanic->password);

        $order = $this->createOrder(OrderTypeEnum::ROADSIDE_ASSISTANCE->value);
        $order->metadata = [
            'assistance_case' => [
                ...$order->metadata['assistance_case'],
                'mechanic' => ['user_id' => $mechanic->getId()],
            ],
        ];
        $order->saveQuietly();

        $channel = Channel::create([
            'apps_id' => $this->apps->getId(),
            'companies_id' => $order->companies_id,
            'slug' => 'dm-' . $this->authUser->getId() . '-' . $mechanic->getId(),
            'name' => 'DM',
            'description' => 'DM',
            'entity_id' => $mechanic->getId(),
            'entity_namespace' => Users::class,
            'users_id' => $this->authUser->getId(),
        ]);
        // Only the customer is in the pivot; the mechanic writes anyway.
        $channel->users()->attach($this->authUser->getId(), ['roles_id' => 2]);

        $message = $this->createChannelMessage($channel, $mechanic);

        Notification::fake();

        $notified = new SendRoadsideChatMessagePushAction($channel, $message)->execute();

        $this->assertSame(1, $notified);
        Notification::assertSentTo($this->authUser, RoadsideChatMessageNotification::class);
        Notification::assertNotSentTo($mechanic, RoadsideChatMessageNotification::class);
    }

    public function testActivityNotifiesCounterpartyWhenMessageAddedToChannel(): void
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

        // Mirrors Channel::addMessage() firing Channel/updated with the new message in params.
        $result = new SendRoadsideChatMessagePushActivity(
            0,
            now()->toDateTimeString(),
            StoredWorkflow::make(),
            []
        )->execute($channel, $this->apps, ['message' => $message]);

        $this->assertTrue($result['result']);
        $this->assertSame(1, $result['recipients_notified']);
        Notification::assertSentTo($mechanic, RoadsideChatMessageNotification::class);
        // The sender never gets pushed for their own message.
        Notification::assertNotSentTo($this->authUser, RoadsideChatMessageNotification::class);
    }

    public function testActivityIgnoresMessageWithoutAssistanceChatVerb(): void
    {
        $order = $this->createOrder(OrderTypeEnum::ROADSIDE_ASSISTANCE->value);
        $mechanic = Users::factory()->create();
        $channel = $this->createOrderChannel($order, [$this->authUser->getId(), $mechanic->getId()]);
        $message = $this->createChannelMessage($channel, $this->authUser, 'some-other-verb');
        $channel->messages()->attach($message->getKey(), ['users_id' => $this->authUser->getId()]);

        Notification::fake();

        // Guards on the verb before executeIntegration, so no MOVIPASS integration row is needed.
        $result = new SendRoadsideChatMessagePushActivity(
            0,
            now()->toDateTimeString(),
            StoredWorkflow::make(),
            []
        )->execute($channel, $this->apps, ['message' => $message]);

        $this->assertFalse($result['result']);
        $this->assertSame(0, $result['recipients_notified']);
        Notification::assertNothingSent();
    }

    public function testActivityIgnoresChannelUpdateWithoutMessage(): void
    {
        $order = $this->createOrder(OrderTypeEnum::ROADSIDE_ASSISTANCE->value);
        $mechanic = Users::factory()->create();
        $channel = $this->createOrderChannel($order, [$this->authUser->getId(), $mechanic->getId()]);

        Notification::fake();

        // A plain Channel/updated (rename, last_message_id, etc.) carries no message in params.
        $result = new SendRoadsideChatMessagePushActivity(
            0,
            now()->toDateTimeString(),
            StoredWorkflow::make(),
            []
        )->execute($channel, $this->apps, []);

        $this->assertFalse($result['result']);
        $this->assertSame(0, $result['recipients_notified']);
        Notification::assertNothingSent();
    }

    public function testSyncPushesLatestRoadsideChatMessageToCounterparty(): void
    {
        $mechanic = Users::factory()->create();
        $order = $this->createOrder(OrderTypeEnum::ROADSIDE_ASSISTANCE->value);
        $order->metadata = [
            'assistance_case' => [
                ...$order->metadata['assistance_case'],
                'mechanic' => ['user_id' => $mechanic->getId()],
            ],
        ];
        $order->saveQuietly();

        $channel = Channel::create([
            'apps_id' => $this->apps->getId(),
            'companies_id' => $order->companies_id,
            'slug' => 'dm-' . $this->authUser->getId() . '-' . $mechanic->getId(),
            'name' => 'DM',
            'description' => 'DM',
            'entity_id' => 1,
            'entity_namespace' => Message::class,
            'users_id' => $this->authUser->getId(),
        ]);
        $channel->users()->attach($this->authUser->getId(), ['roles_id' => 2]);
        $message = $this->createChannelMessage($channel, $this->authUser);
        $channel->messages()->attach($message->getKey(), ['users_id' => $this->authUser->getId()]);

        $this->setIntegration(
            $this->apps,
            IntegrationsEnum::MOVIPASS,
            MovipassHandler::class,
            $order->company,
            $this->authUser
        );

        Notification::fake();

        new SyncMovipassRoadsideAssistanceActivity(
            0,
            now()->toDateTimeString(),
            StoredWorkflow::make(),
            []
        )->execute($order, $this->apps, ['currentEventTypeName' => WorkflowEnum::UPDATED->value]);

        Notification::assertSentTo($mechanic, RoadsideChatMessageNotification::class);
        Notification::assertNotSentTo($this->authUser, RoadsideChatMessageNotification::class);

        // Idempotent: a second sync with no new chat message pushes nothing.
        $order->refresh();
        Notification::fake();

        new SyncMovipassRoadsideAssistanceActivity(
            0,
            now()->toDateTimeString(),
            StoredWorkflow::make(),
            []
        )->execute($order, $this->apps, ['currentEventTypeName' => WorkflowEnum::UPDATED->value]);

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

    private function createChannelMessage(
        Channel $channel,
        Users $sender,
        string $verb = SendRoadsideChatMessagePushAction::ROADSIDE_CHAT_VERB
    ): Message {
        $messageType = MessageType::where('apps_id', $this->apps->getId())
            ->where('verb', $verb)
            ->first()
            ?? MessageType::factory()->create(['verb' => $verb]);

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
