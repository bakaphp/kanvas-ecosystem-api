<?php

declare(strict_types=1);

namespace Kanvas\Connectors\InAppPurchase\Workflows;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Kanvas\Social\Channels\Actions\CreateChannelAction;
use Kanvas\Social\Channels\DataTransferObject\Channel;
use Kanvas\Social\Messages\Actions\CreateAppModuleMessageAction;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\Messages\Services\MessageInteractionService;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\KanvasActivity;

class LinkMessageToOrderActivity extends KanvasActivity implements WorkflowActivityInterface
{
    /**
     * @param Model<Order> $order
     */
    public function execute(Model $order, AppInterface $app, array $params): array
    {
        $this->overwriteAppService($app);
        $warning = null;
        if (! $order->get('message_id')) {
            return [
                'message' => 'No message id found in order',
                'order' => $order->id,
            ];
        }

        $message = Message::getById($order->get('message_id'), $app);
        $orderSystemModule = SystemModulesRepository::getByModelName(Order::class);
        (new CreateAppModuleMessageAction($message, $orderSystemModule, $order->getId()))->execute();

        $order->metadata = array_merge(
            $order->metadata,
            ['message_id' => $message->getId(), 'message' => $message->message]
        );
        $order->saveOrFail();
        $user = $order->user;

        $newPurchaseMessageChannel = new CreateChannelAction(
            new Channel(
                apps: $app,
                companies: $message->company,
                users: $order->user,
                entity_id: $user->getId(),
                entity_namespace: Users::class,
                name: 'Purchase Message',
                description: 'Purchase Message Channel',
                slug: 'PMC-' . $user->uuid
            ),
            $order->user
        );

        $purchaseChannel = $newPurchaseMessageChannel->execute();
        $purchaseChannel->addMessage($message, $user);

        $user->set('purchase_channel', $purchaseChannel->uuid);

        try {
            $messageInteractionService = new MessageInteractionService($message);
            $messageInteractionService->purchase($user);
        } catch (UniqueConstraintViolationException $e) {
            $warning = [
                'msg' => 'This order has been linked to a message and a channel',
                'exception' => $e->getMessage(),
            ];
        }

        return [
            'order' => $order->id,
            'message' => $message->id,
            'channel' => $purchaseChannel->id,
            'channel_name' => $purchaseChannel->name,
            'warning' => $warning,
        ];
    }
}
