<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ESim\WorkflowActivities;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\ESim\Enums\CustomFieldEnum;
use Kanvas\Connectors\ESim\Services\OrderService;
use Kanvas\Connectors\ESimGo\Services\ESimService;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\MessagesTypes\Actions\CreateMessageTypeAction;
use Kanvas\Social\MessagesTypes\DataTransferObject\MessageTypeInput;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Kanvas\Workflow\KanvasActivity;
use Throwable;

class CreateOrderInESimActivity extends KanvasActivity
{
    public function execute(Order $order, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);
        $orderHasMetaData = $order->get(CustomFieldEnum::ORDER_ESIM_METADATA->value);
        if (! empty($orderHasMetaData)) {
            return [
                'status' => 'success',
                'message' => 'Order already has eSim metadata',
                'response' => $orderHasMetaData,
            ];
        }

        $createOrder = new OrderService($order);
        $response = $createOrder->createOrder();

        $order->metadata = $response;
        $order->saveOrFail();
        $order->set(CustomFieldEnum::ORDER_ESIM_METADATA->value, $response);

        $response['order_id'] = $order->id;

        try {
            $esimGo = new ESimService($app);
            $esimData = $esimGo->getAppliedBundleStatus($response['data']['iccid'], $response['data']['plan']);
            $esimData['expiration_date'] = null;
            $esimData['phone_number'] = null;
            $response['esim_status'] = $esimData;
        } catch (Throwable $e) {
        }

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
