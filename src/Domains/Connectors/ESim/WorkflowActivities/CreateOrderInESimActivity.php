<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ESim\WorkflowActivities;

use GuzzleHttp\Exception\ClientException;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\CMLink\Actions\CreateEsimOrderAction;
use Kanvas\Connectors\ESim\Actions\PushOrderToCommerceAction;
use Kanvas\Connectors\ESim\DataTransferObject\ESim;
use Kanvas\Connectors\ESim\Enums\ConfigurationEnum;
use Kanvas\Connectors\ESim\Enums\CustomFieldEnum;
use Kanvas\Connectors\ESim\Enums\ProviderEnum;
use Kanvas\Connectors\ESim\Services\OrderService;
use Kanvas\Connectors\ESimGo\Services\ESimService;
use Kanvas\Connectors\Stripe\Enums\ConfigurationEnum as EnumsConfigurationEnum;
use Kanvas\Connectors\WooCommerce\Services\WooCommerceOrderService;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\MessagesTypes\Actions\CreateMessageTypeAction;
use Kanvas\Social\MessagesTypes\DataTransferObject\MessageTypeInput;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Kanvas\Workflow\KanvasActivity;

use function Sentry\captureException;

use Stripe\StripeClient;
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
        $fromMobile = isset($order->metadata['optionChecks']) && isset($order->metadata['paymentIntent']);
        $order->checkout_token = $order->metadata['paymentIntent']['client_secret'] ?? null;

        try {
            /**
             * @todo move this to a factory
             */
            if ($providerValue == strtolower(ProviderEnum::CMLINK->value)) {
                $esim = (new CreateEsimOrderAction($order))->execute();

                $woocommerceResponse = $fromMobile ? $this->sendOrderToCommerce($order, $esim, $providerValue) : ['web order' => true];

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

    /**
     * @todo this is ugly as hell and should be moved to a service
     */
    protected function sendOrderToCommerce(Order $order, ESim $esim, string $providerValue): array
    {
        try {
            $woocommerceOrder = new PushOrderToCommerceAction($order, $esim);
            $woocommerceResponse = $woocommerceOrder->execute($providerValue);

            $orderCommerceId = $woocommerceResponse['order']['id'];
            $order->set(CustomFieldEnum::WOOCOMMERCE_ORDER_ID->value, $woocommerceResponse['order']['id']);

            $stripe = new StripeClient($order->app->get(EnumsConfigurationEnum::STRIPE_SECRET_KEY->value));

            $clientSecret = $order->checkout_token;
            $paymentIntentId = explode('_secret_', $clientSecret)[0]; // Gets "pi_3RAClYDdrFkcUBzl0vNHHnFD"

            $paymentIntent = $stripe->paymentIntents->retrieve($paymentIntentId);

            $commerceOrder = new WooCommerceOrderService($order->app);
            $updateResponse = $commerceOrder->updateOrderStripePayment(
                $orderCommerceId,
                (string) $paymentIntent->latest_charge,
                'completed',
                $paymentIntent->toArray(),
            );

            if (! empty($woocommerceResponse['order']['number'])) {
                //$order->order_number = $woocommerceResponse['order']['number'];
            }
            $order->addPrivateMetadata('stripe_payment_intent', $paymentIntent->toArray());

            return [
                'order' => $woocommerceResponse,
                'update' => $updateResponse ?? null,
            ];
        } catch (Throwable $e) {
            captureException($e);

            return [
                'status' => 'error',
                'message' => 'Error sending order to commerce',
                'response' => $e->getMessage(),
            ];
        }
    }
}
