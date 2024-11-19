<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ESim\WorkflowActivities;

use Baka\Traits\KanvasJobsTrait;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\ESim\Services\OrderService;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\MessagesTypes\Actions\CreateMessageTypeAction;
use Kanvas\Social\MessagesTypes\DataTransferObject\MessageTypeInput;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Workflow\Activity;

class CreateOrderInESimActivity extends Activity
{
    use KanvasJobsTrait;

    public function execute(Order $order, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);
        $createOrder = new OrderService($order);
        $response = $createOrder->createOrder();

        $order->metadata = $response;
        $order->saveOrFail();

        $response['order_id'] = $order->id;

        //create the esim for the user
        $messageType = (new CreateMessageTypeAction(
            new MessageTypeInput(
                $app->getId(),
                0,
                'esim',
                'esim',
            )
        ))->execute();
        $createMessage = new CreateMessageAction(
            new MessageInput(
                $app,
                $order->company,
                $order->user,
                $messageType,
                $response
            ),
            SystemModulesRepository::getByModelName(Order::class, $app),
            $order->getId()
        );

        $message = $createMessage->execute();

        return [
            'status' => 'success',
            'message' => 'Order updated with eSim metadata',
            'response' => $response,
        ];
    }
}
