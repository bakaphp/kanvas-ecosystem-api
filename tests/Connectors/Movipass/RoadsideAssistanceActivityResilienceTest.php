<?php

declare(strict_types=1);

namespace Tests\Connectors\Movipass;

use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Movipass\Actions\SendRoadsideChatMessagePushAction;
use Kanvas\Connectors\Movipass\Enums\MovipassOrderStatusEnum;
use Kanvas\Connectors\Movipass\Enums\OrderTypeEnum;
use Kanvas\Connectors\Movipass\Handlers\MovipassHandler;
use Kanvas\Connectors\Movipass\Notifications\RoadsideAssistanceStatusNotification;
use Kanvas\Connectors\Movipass\Notifications\RoadsideChatMessageNotification;
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

final class RoadsideAssistanceActivityResilienceTest extends TestCase
{
    use HasIntegrationCompany;

    private const MISSING_USER_ID = 999999999;

    protected Apps $apps;
    protected Users $authUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->apps = app(Apps::class);
        $this->authUser = auth()->user();
    }

    /**
     * Regression: an undeliverable chat message used to call failWorkflow() and leave
     * last_pushed_chat_message_id behind, so every later roadside event re-picked the same message
     * and failed the run again — the case was stuck forever on one unreachable recipient.
     */
    public function testUndeliverableChatMessageAdvancesThePointerInsteadOfPoisoningTheOrder(): void
    {
        $order = $this->createRoadsideOrder(['mechanic' => ['user_id' => self::MISSING_USER_ID]]);
        $channel = $this->createDirectMessageChannel($order, self::MISSING_USER_ID);
        $message = $this->createChatMessage($channel, $this->authUser);

        $this->gateIntegration($order);
        Notification::fake();

        $result = $this->runActivity($order, WorkflowEnum::UPDATED->value);

        $this->assertSame('skipped', $result['chat_push']);
        $this->assertSame('no recipients resolved for the message', $result['chat_push_reason']);
        Notification::assertNothingSent();

        $order->refresh();
        $this->assertSame(
            (int) $message->id,
            (int) $order->metadata['assistance_case']['last_pushed_chat_message_id'],
        );

        $secondResult = $this->runActivity($order, WorkflowEnum::UPDATED->value);

        $this->assertSame('skipped', $secondResult['chat_push']);
        $this->assertSame('already pushed', $secondResult['chat_push_reason']);
    }

    /**
     * Regression: an action that pushes its own message (arrival, cancel, completion, decline)
     * transitions the order right after, and the STATUS_TRANSITION run sent a second, generic push
     * for the same status.
     */
    public function testStatusTransitionSkipsThePushTheActionAlreadySent(): void
    {
        $mechanic = Users::factory()->create();
        $order = $this->createRoadsideOrder([
            'mechanic' => ['user_id' => $mechanic->getId()],
            'status_notified' => MovipassOrderStatusEnum::ON_SITE->slug(),
        ]);

        $this->gateIntegration($order);
        Notification::fake();

        $this->runActivity($order, WorkflowEnum::STATUS_TRANSITION->value, ['to_status' => MovipassOrderStatusEnum::ON_SITE->slug()]);

        Notification::assertNothingSent();

        $order->refresh();
        $this->assertArrayNotHasKey('status_notified', $order->metadata['assistance_case']);
    }

    public function testStatusTransitionNobodyNotifiedPushesArrivalToTheCustomerOnly(): void
    {
        $mechanic = Users::factory()->create();
        $order = $this->createRoadsideOrder(['mechanic' => ['user_id' => $mechanic->getId()]]);

        $this->gateIntegration($order);
        Notification::fake();

        $this->runActivity($order, WorkflowEnum::STATUS_TRANSITION->value, ['to_status' => MovipassOrderStatusEnum::ON_SITE->slug()]);

        Notification::assertSentTo(
            $this->authUser,
            RoadsideAssistanceStatusNotification::class,
            fn (RoadsideAssistanceStatusNotification $notification) => $notification->getData()['title'] === 'Mechanic on site'
        );
        Notification::assertNotSentTo($mechanic, RoadsideAssistanceStatusNotification::class);
    }

    /**
     * Regression: the channel workflow pushed a chat message without recording it, so the next
     * order event's sync pushed the same message to the counterparty again.
     */
    public function testChatMessageIsPushedOnceAcrossBothPushPaths(): void
    {
        $mechanic = Users::factory()->create();
        $order = $this->createRoadsideOrder(['mechanic' => ['user_id' => $mechanic->getId()]]);
        $channel = $this->createDirectMessageChannel($order, $mechanic->getId());
        $message = $this->createChatMessage($channel, $this->authUser);

        Notification::fake();

        $this->assertSame(1, new SendRoadsideChatMessagePushAction($channel, $message)->execute());
        $this->assertSame(0, new SendRoadsideChatMessagePushAction($channel, $message)->execute());

        Notification::assertSentToTimes($mechanic, RoadsideChatMessageNotification::class, 1);

        $order->refresh();
        $this->assertSame(
            (int) $message->id,
            (int) $order->metadata['assistance_case']['last_pushed_chat_message_id'],
        );
    }

    /**
     * Regression: the order-type gate read $order->orderType->name unguarded, so an order whose
     * type row is gone raised "Attempt to read property on null" and failed the whole run.
     */
    public function testOrderWithoutAnOrderTypeIsSkippedInsteadOfFatal(): void
    {
        $order = $this->createRoadsideOrder();
        $order->order_types_id = 987654321;
        $order->saveQuietly();

        $this->gateIntegration($order);

        $result = $this->runActivity($order, WorkflowEnum::UPDATED->value);

        $this->assertSame('success', $result['status']);
        $this->assertSame('Order is not a roadside assistance type', $result['message']);
        $this->assertNull($result['order_type']);
    }

    /**
     * A roadside order with no assistance_case used to fall through handleStatusTransition and
     * report a plain success, hiding the fact that nothing was written.
     */
    public function testStatusTransitionWithoutAssistanceCaseReportsWhyItDidNothing(): void
    {
        $order = $this->createRoadsideOrder();
        $order->metadata = [];
        $order->saveQuietly();

        $this->gateIntegration($order);

        $result = $this->runActivity($order, WorkflowEnum::STATUS_TRANSITION->value, [
            'to_status' => 'dispatched',
        ]);

        $this->assertSame(
            'Roadside assistance status transition skipped: order has no assistance_case metadata',
            $result['message'],
        );
        $this->assertSame('dispatched', $result['to_status']);
    }

    private function runActivity(Order $order, string $event, array $params = []): array
    {
        return new SyncMovipassRoadsideAssistanceActivity(
            0,
            now()->toDateTimeString(),
            StoredWorkflow::make(),
            []
        )->execute($order, $this->apps, [
            'currentEventTypeName' => $event,
            ...$params,
        ]);
    }

    private function createRoadsideOrder(array $assistanceCase = []): Order
    {
        $orderType = OrderTypes::firstOrCreate([
            'name' => OrderTypeEnum::ROADSIDE_ASSISTANCE->value,
            'apps_id' => $this->apps->getId(),
        ]);

        return Order::factory()
            ->withCompanyId($this->authUser->getCurrentCompany()->getId())
            ->withUserId($this->authUser->getId())
            ->create([
                'order_types_id' => $orderType->getId(),
                'user_email' => 'cliente@movipass.test',
                'user_phone' => '8090000000',
                'metadata' => ['assistance_case' => [
                    'service' => 'Cambio de neumático',
                    'location' => ['lat' => 18.486, 'lng' => -69.931],
                    ...$assistanceCase,
                ]],
            ]);
    }

    /**
     * The real client payload: dm-{customer}-{mechanic} with only the sender in the users pivot.
     */
    private function createDirectMessageChannel(Order $order, int $mechanicId): Channel
    {
        $channel = Channel::create([
            'apps_id' => $this->apps->getId(),
            'companies_id' => $order->companies_id,
            'slug' => 'dm-' . $this->authUser->getId() . '-' . $mechanicId,
            'name' => 'DM',
            'description' => 'DM',
            'entity_id' => 1,
            'entity_namespace' => Message::class,
            'users_id' => $this->authUser->getId(),
        ]);
        $channel->users()->attach($this->authUser->getId(), ['roles_id' => 2]);

        return $channel;
    }

    private function createChatMessage(Channel $channel, Users $sender): Message
    {
        $messageType = MessageType::where('apps_id', $this->apps->getId())
            ->where('verb', SendRoadsideChatMessagePushAction::ROADSIDE_CHAT_VERB)
            ->first()
            ?? MessageType::factory()->create(['verb' => SendRoadsideChatMessagePushAction::ROADSIDE_CHAT_VERB]);

        $message = Message::create([
            'apps_id' => $this->apps->getId(),
            'companies_id' => $channel->companies_id,
            'users_id' => $sender->getId(),
            'message_types_id' => $messageType->getId(),
            'message' => ['message' => 'Hi, where are you?'],
            'is_public' => 1,
        ]);

        $channel->messages()->attach($message->getKey(), ['users_id' => $sender->getId()]);

        return $message;
    }

    private function gateIntegration(Order $order): void
    {
        $this->setIntegration(
            $this->apps,
            IntegrationsEnum::MOVIPASS,
            MovipassHandler::class,
            $order->company,
            $this->authUser
        );
    }
}
