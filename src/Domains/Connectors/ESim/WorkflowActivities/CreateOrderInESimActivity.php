<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ESim\WorkflowActivities;

use GuzzleHttp\Exception\ClientException;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\CMLink\Actions\CreateEsimOrderAction;
use Kanvas\Connectors\ESim\DataTransferObject\ESim;
use Kanvas\Connectors\ESim\Enums\ConfigurationEnum;
use Kanvas\Connectors\ESim\Enums\CustomFieldEnum;
use Kanvas\Connectors\ESim\Enums\ProviderEnum;
use Kanvas\Connectors\ESim\Services\OrderService;
use Kanvas\Connectors\ESimGo\Services\ESimService;
use Kanvas\Connectors\WooCommerce\Actions\PushOrderToWooCommerceAction;
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
    //public $tries = 2;

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

        $firstItem = $order->items()->first();
        $variant = $firstItem->variant;

        // Get the variant provider attribute
        $variantProvider = $variant->getAttributeBySlug(ConfigurationEnum::VARIANT_PROVIDER_SLUG->value);

        // Fall back to product provider if variant provider is empty
        $provider = ! empty($variantProvider)
            ? $variantProvider
            : $variant->product->getAttributeBySlug(ConfigurationEnum::PROVIDER_SLUG->value);

        if (! $provider) {
            return [
                'status' => 'error',
                'message' => 'Provider not found',
            ];
        }

        $providerValue = strtolower($provider->value);

        try {
            /**
             * @todo move this to a factory
             */
            if ($providerValue == strtolower(ProviderEnum::CMLINK->value)) {
                $esim = (new CreateEsimOrderAction($order))->execute();

                try {
                    $woocommerceOrder = new PushOrderToWooCommerceAction($order, [],  $this->formatEsimForWoocommerce($order, $esim));
                    $woocommerceResponse = $woocommerceOrder->execute();
                } catch (Throwable $e) {
                    $woocommerceResponse = [
                        'status' => 'error',
                        'message' => 'Error creating order in WooCommerce',
                        'response' => $e->getMessage(),
                    ];
                }

                $response = [
                    'success' => true,
                    'data' => [
                        ...array_diff_key($esim->toArray(), ['esim_status' => '']),
                        'plan_origin' => $esim->plan,
                    ],
                    'esim_status' => $esim->esimStatus->toArray(),
                    'woocommerce_response' => $woocommerceResponse,
                ];
            } else {
                $createOrder = new OrderService($order);
                $response = $createOrder->createOrder();
            }
        } catch (ClientException $e) {
            return [
                'status' => 'error',
                'message' => 'Error creating order in eSim',
                'response' => $e->getMessage(),
            ];
        }

        $order->metadata = array_merge(($order->metadata ?? []), $response);
        $order->completed();
        //$order->saveOrFail();
        $order->set(CustomFieldEnum::ORDER_ESIM_METADATA->value, $response);

        $response['order_id'] = $order->id;
        $response['order'] = $order->toArray();

        if (! isset($response['label'])) {
            $response['label'] = $order->metadata['esimLabels'][0]['label'] ?? null;
        }

        $sku = null;
        foreach ($order->items as $item) {
            $variant = Variants::where('id', $item->variant_id)->first();
            $detail['variant'] = $variant->toArray();
            $detail['variant']['attributes'] = $variant->attributes()->pluck('value', 'name')->toArray();
            $sku = $variant->sku;

            $response['items'][] = $detail;
        }

        try {
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
                $response['data']['plan_origin'] = $response['data']['plan'];
                $response['data']['plan'] = $sku; //overwrite the plan with the sku
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
        $order->metadata = array_merge(($order->metadata ?? []), ['message_id' => $message->getId()]);
        $order->updateOrFail();
        $order->set(CustomFieldEnum::MESSAGE_ESIM_ID->value, $message->getId());

        return [
            'status' => 'success',
            'message' => 'Order updated with eSim metadata',
            'message_id' => $message->getId(),
            'response' => $response,
        ];
    }

    protected function formatEsimForWoocommerce(Order $order, ESim $esim): array
    {
        $esimData = $order->metadata['data'] ?? [];

        // Prepare eSIM metadata for the order
        $orderMetadata = [
           'purchase_type' => 'new',
           'recharge_status' => 'none',
           'is_archived' => false,
           'is_unlocked' => false,
           'has_valid_imei' => $esimData['has_valid_imei'] ?? true,
           'esim_name' => $esimData['destination'] ?? '',
           'imei' => $esimData['client_imei'] ?? '',
           'esim_email' => $order->user_email ?? '',
           'date_from' => $esim->esimStatus->assignmentDateTime ?? '',
           'date_to' => $esim->esimStatus->expirationDate ?? '',
           'total_days' => $esimData['total_days'] ?? 0,
           'apn' => $esimData['apn'] ?? '',
           'order_reference' => $order->order_number ?? '',
           'lpa_code' => $esim->lpaCode ?? '',
           'matching_id' => $esim->matchingId ?? '',
           'smdp_address' => $esim->smdpAddress ?? '',
           'label' => $esim->label ?? '',
           'agent_name' => '',
           'is_unlimited' => $esim->esimStatus->unlimited ?? false,
           'order_source' => 'kanvas',
        ];

        // Prepare activation data
        $activationData = [
            'plan' => $esim->plan,
            'iccid' => $esim->iccid,
            'apn' => $esimData['apn'] ?? '',
            'order_reference' => $order->order_number ?? '',
            'lpa_code' => $esim->lpaCode ?? '',
            'matching_id' => $esim->matchingId ?? '',
            'smdp_address' => $esim->smdpAddress ?? '',
            'phone_number' => $esim->esimStatus->phoneNumber ?? '',
        ];

        $orderMetadata['esim_activation'] = $activationData;

        return [[
            'key' => '_esim_details',
            'value' => $orderMetadata,
        ]];
    }
}
