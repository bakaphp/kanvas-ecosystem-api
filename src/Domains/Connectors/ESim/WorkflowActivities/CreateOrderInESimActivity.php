<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ESim\WorkflowActivities;

use GuzzleHttp\Exception\ClientException;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\ESim\Enums\ConfigurationEnum;
use Kanvas\Connectors\ESim\Enums\CustomFieldEnum;
use Kanvas\Connectors\ESim\Enums\ProviderEnum;
use Kanvas\Connectors\ESim\Services\OrderService;
use Kanvas\Connectors\ESimGo\Services\ESimService;
use Kanvas\Inventory\Variants\Models\Variants;
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

        try {
            $createOrder = new OrderService($order);
            $response = $createOrder->createOrder();
        } catch (ClientException $e) {
            return [
                'status' => 'error',
                'message' => 'Error creating order in eSim',
                'response' => $e->getMessage(),
            ];
        }

        $order->metadata = array_merge(($order->metadata ?? []), $response);
        $order->saveOrFail();
        $order->set(CustomFieldEnum::ORDER_ESIM_METADATA->value, $response);

        $provider = $order->items()->first()->variant->product->getAttributeBySlug(ConfigurationEnum::PROVIDER_SLUG->value);

        $response['order_id'] = $order->id;
        $response['order'] = $order->toArray();

        foreach ($order->items as $item) {
            $variant = Variants::where('id', $item->variant_id)->first();
            $detail['variant'] = $variant->toArray();
            $detail['variant']['attributes'] = $variant->attributes()->pluck('value', 'name')->toArray();

            $response['items'][] = $detail;
        }

        try {
            $providerValue = strtolower($provider->value);
            if ($providerValue === strtolower(ProviderEnum::E_SIM_GO->value)) {
                $esimGo = new ESimService($app);
                $esimData = $esimGo->getAppliedBundleStatus($response['data']['iccid'], $response['data']['plan']);
                $esimData['expiration_date'] = null;
                $esimData['phone_number'] = null;
                $response['esim_status'] = $esimData;
            } elseif ($providerValue === strtolower(ProviderEnum::EASY_ACTIVATION->value)) {
                $response['esim_status'] = [
                    'expiration_date' => $response['data']['end_date'] ?? null,
                    'esim_status' => $response['data']['status'] ?? null,
                    'phone_number' => $response['data']['phone_number'] ?? null,
                ];
            }
        } catch (Throwable $e) {
            // Log the exception or handle it as needed
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
