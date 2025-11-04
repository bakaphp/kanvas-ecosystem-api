<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ESim\WorkflowActivities;

use Illuminate\Database\Eloquent\ModelNotFoundException;
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
use Kanvas\Connectors\Stripe\Services\StripeCustomerService;
use Kanvas\Connectors\VentaMobile\Actions\CreateEsimOrderAction as ActionsCreateEsimOrderAction;
use Kanvas\Connectors\WooCommerce\Services\WooCommerceOrderService;
use Kanvas\Exceptions\ModelNotFoundException as ExceptionsModelNotFoundException;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Filesystem\Services\FilesystemServices;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Actions\CreateMessageTypeAction;
use Kanvas\Social\MessagesTypes\DataTransferObject\MessageTypeInput;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Notifications\NewOrderNotification;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;
use Stripe\StripeClient;
use Throwable;

class CreateOrderInESimActivity extends KanvasActivity
{
    //public $tries = 2;

    public function execute(Order $order, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);

        return $this->executeIntegration(
            entity: $order,
            app: $app,
            integration: IntegrationsEnum::ESIM_SOLUTION,
            additionalParams: $params,
            integrationOperation: function ($order, $app, $integrationCompany, $additionalParams) use ($params) {
                $orderHasMetaData = $order->get(CustomFieldEnum::ORDER_ESIM_METADATA->value);
                if (! empty($orderHasMetaData)) {
                    return [
                        'status' => 'success',
                        'message' => 'Order already has eSim metadata',
                        'response' => $orderHasMetaData,
                    ];
                }

                $validateEsimProductType = ['local', 'global', 'regional'];
                $responses = [];
                $allEsimResponses = [];
                $woocommerceResponse = ['web order' => true]; // Default for non-mobile orders
                $woocommerceSent = false; // Flag to track if WooCommerce order was sent
                $language = $order->metadata['language'] ?? $params['language'] ?? 'en';
                $metaDataSendEmail = (bool) ($order->metadata['send_kanvas_email'] ?? false);

                if (isset($order->metadata['is_stand'])) {
                    $this->fixStandUSProductSku($order);
                }

                foreach ($order->items as $item) {
                    $variant = $item->variant;
                    // Get the product type from the variant's product
                    $productType = strtolower($variant->product->productType->name ?? '');
                    if (! in_array($productType, $validateEsimProductType)) {
                        continue;
                    }

                    // Get the variant provider attribute
                    $variantProvider = $variant->getAttributeBySlug(ConfigurationEnum::VARIANT_PROVIDER_SLUG->value);
                    // Fall back to product provider if variant provider is empty
                    $provider = ! empty($variantProvider)
                        ? $variantProvider
                        : $variant->product->getAttributeBySlug(ConfigurationEnum::PROVIDER_SLUG->value);
                    if (! $provider) {
                        $responses[] = [
                            'status' => 'error',
                            'message' => 'Provider not found',
                        ];

                        continue;
                    }

                    $providerValue = strtolower($provider->value);
                    $fromMobile = strtolower($order->metadata['source'] ?? '') !== 'b2b' && isset($order->metadata['optionChecks']) && isset($order->metadata['paymentIntent']);
                    $isRefuelOrder = isset($order->metadata['parent_order_id']) && ! empty($order->metadata['parent_order_id']);
                    $order->checkout_token = $order->metadata['paymentIntent']['client_secret'] ?? null;
                    $language = $order->metadata['language'] ?? 'es';

                    // Get quantity from item (default to 1 if not set)
                    $quantity = $item->quantity ?? 1;
                    $esimExtraInfoDetails = $order->metadata['esimDetails'] ?? [];

                    if (isset($order->metadata['is_stand']) && (bool) $order->metadata['is_stand']) {
                        $order->addTag('stand');
                    } else {
                        $order->addTag(strtolower($order->metadata['source'] ?? 'website'));
                    }

                    if (! empty($order->metadata['platformOS'])) {
                        $order->addTag(strtolower($order->metadata['platformOS']));
                    }

                    // Create eSims based on quantity
                    for ($i = 0; $i < $quantity; $i++) {
                        try {
                            if ($providerValue == strtolower(ProviderEnum::CMLINK->value) ||
                                $providerValue == strtolower(ProviderEnum::VENTA_MOBILE->value)) {
                                $actionClass = $providerValue == strtolower(ProviderEnum::CMLINK->value)
                                    ? CreateEsimOrderAction::class
                                    : ActionsCreateEsimOrderAction::class;

                                // Pass the variant to the action for this specific eSim creation
                                $esim = (new $actionClass($order, null, $variant))->execute();

                                // Send to WooCommerce only once and only for mobile orders
                                if ($fromMobile && ! $woocommerceSent) {
                                    $woocommerceResponse = $this->sendOrderToCommerce($order, $esim, $providerValue);
                                    $woocommerceSent = true;
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
                                $createOrder = new OrderService($order, $variant);
                                $response = $createOrder->createOrder();
                            }
                        } catch (Throwable $e) {
                            report($e);
                            $responses[] = [
                                'status' => 'error',
                                'message' => 'Error creating order in eSim',
                                'response' => $e->getMessage(),
                            ];

                            continue;
                        }

                        $response['order_id'] = $order->id;
                        $response['order'] = $order->toArray();
                        $response['esim_sequence'] = $i + 1; // Add sequence number for tracking
                        $response['total_quantity'] = $quantity; // Add total quantity info

                        try {
                            $response['variant_info'] = [
                                'name' => $variant->name,
                                'product_name' => $variant->product->name,
                                'attributes' => $variant->attributes()->pluck('value', 'name')->toArray(),
                            ];

                            $response['qr_url'] = ! empty($response['data']['qr_code']) ? $this->saveQrCodeFromBase64(
                                $response['data']['qr_code'] ?? '',
                                $order,
                                $response['data']['iccid'] . '.png'
                            )->url : null;
                        } catch (Throwable $e) {
                            report($e);
                            $response['qr_url'] = null; // Set to null if QR code saving fails
                        }

                        if (! isset($response['label'])) {
                            $response['label'] = $order->metadata['esimLabels'][0]['label'] ?? null;
                        }

                        if (isset($esimExtraInfoDetails[$variant->id]['labels']) && ! empty($esimExtraInfoDetails[$variant->id]['labels'])) {
                            $response['label'] = array_shift($esimExtraInfoDetails[$variant->id]['labels']);
                        }

                        if (empty($response['label']) && isset($item->metadata['eSimDetails'][$i]['label'])) {
                            $response['label'] = $item->metadata['eSimDetails'][$i]['label'];
                        }

                        if (isset($item->metadata['eSimDetails'][$i])) {
                            $response['eSimDetails'] = $item->metadata['eSimDetails'][$i];
                        }

                        $sku = null;
                        foreach ($order->items as $itemDetail) {
                            $variantDetail = Variants::where('id', $itemDetail->variant_id)->first();
                            $detail['variant'] = $variantDetail->toArray();
                            $detail['variant']['attributes'] = $variantDetail->attributes()->pluck('value', 'name')->toArray();

                            $sku = $variantDetail->sku;
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
                                $response['data']['plan'] = $sku;
                            } elseif ($providerValue === strtolower(ProviderEnum::AIRALO->value)) {
                                $response['esim_status'] = [
                                    'expiration_date' => null,
                                    'esim_status' => $response['data']['status'] ?? null,
                                    'phone_number' => null,
                                ];
                                $response['data']['plan_origin'] = $response['data']['plan'] ?? null;
                                $response['data']['plan'] = $sku;
                            }
                        } catch (Throwable $e) {
                            report($e);
                        }

                        $response['provider'] = $variantDetail->getAttributeBySlug(ConfigurationEnum::VARIANT_PROVIDER_SLUG->value)?->value
                                ?? $variantDetail->product->getAttributeBySlug(ConfigurationEnum::PROVIDER_SLUG->value)?->value
                                ?? $providerValue;

                        // Create message for each eSim
                        if (! $isRefuelOrder) {
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
                            $this->updateMessageMetaDataOrderNumber($message, $woocommerceResponse ?? []);

                            // Set ICCID custom field for this message to support targeted refueling
                            $iccid = $response['data']['iccid'] ?? $response['data']['sim'] ?? null;
                            if ($iccid) {
                                $message->set(CustomFieldEnum::MESSAGE_ESIM_ICCID->value, $iccid);
                            }

                            // Set variant SKU for this message to support refuel targeting
                            $message->set(CustomFieldEnum::MESSAGE_ESIM_VARIANT_SKU->value, $variant->sku);

                            $response['message_id'] = $message->getId();
                        } else {
                            $parentOrder = Order::getById($order->metadata['parent_order_id']);
                            $message = Message::getById($parentOrder->get(CustomFieldEnum::MESSAGE_ESIM_ID->value));
                            $message->setPublic();
                            $message->addEntity($order);
                            $response['message_id'] = $message->getId();
                        }

                        // Store each eSim response with its message ID
                        $allEsimResponses[] = $response;

                        $responses[] = [
                            'status' => 'success',
                            'message' => 'Order updated with eSim metadata',
                            'message_id' => $message->getId(),
                            'response' => $response,
                        ];
                    }
                }

                // After processing all items and quantities, update order metadata
                if (! empty($allEsimResponses)) {
                    // Merge all responses into order metadata

                    foreach ($allEsimResponses as $key => $esimResponse) {
                        // Ensure each response has the 'order' and 'items' keys
                        unset($allEsimResponses[$key]['order'], $allEsimResponses[$key]['items']);
                    }
                    $combinedResponse = [
                        'esims' => $allEsimResponses,
                        'total_esims_created' => count($allEsimResponses),
                    ];

                    // Collect all message IDs
                    $messageIds = array_column($allEsimResponses, 'message_id');

                    $order->metadata = array_merge(($order->metadata ?? []), $combinedResponse);
                    $order->metadata = array_merge(($order->metadata ?? []), ['message_ids' => $messageIds]);

                    //@todo this is a temporary fix to handle single eSim responses
                    if ($combinedResponse['total_esims_created'] === 1) {
                        $legacyEsim = [
                            'success' => $allEsimResponses[0]['success'] ?? true,
                            'data' => $allEsimResponses[0]['data'] ?? [],
                            'esim_status' => $allEsimResponses[0]['esim_status'] ?? [],
                            'woocommerce_response' => $allEsimResponses[0]['woocommerce_response'] ?? [],
                            'message_id' => $allEsimResponses[0]['message_id'] ?? null,
                        ];

                        $order->metadata = array_merge(($order->metadata ?? []), $legacyEsim);
                    }

                    $order->completed();
                    $order->set(CustomFieldEnum::ORDER_ESIM_METADATA->value, $combinedResponse);

                    // Set the first message ID as the primary message ID for backward compatibility
                    if (! empty($messageIds)) {
                        $order->set(CustomFieldEnum::MESSAGE_ESIM_ID->value, $messageIds[0]);
                    }

                    $order->updateOrFail();
                }

                try {
                    if ($app->get('esim-send-email') || (isset($params['send_email']) && $params['send_email'] === true) || $metaDataSendEmail) {
                        $orderNotification = new NewOrderNotification($order, [
                            'app' => $order->app,
                            'company' => $order->company,
                            'isRefuelOrder' => $isRefuelOrder ?? false,
                            'subject' => $language === 'en' ? 'Your eSIM from ' . ucfirst($order->app->name) . ' is ready for use' : 'Tu eSIM de ' . ucfirst($order->app->name) . ' está lista para usar',
                        ]);
                        $orderNotification->channels = ['mail'];
                        $order->user->notify($orderNotification);
                    }

                    $order->user->set('coupon-sl5', 1); // Set a flag for sl5 coupon usage
                } catch (ModelNotFoundException | ExceptionsModelNotFoundException $e) {
                    // Handle notification failure
                }

                foreach ($responses as $response) {
                    if (isset($response['status']) && $response['status'] === 'error') {
                        return $this->failWorkflow($responses);
                    }
                }

                if (count($responses) === 1) {
                    return $responses[0];
                }

                return $responses;
            },
            company: $order->company,
        );
    }

    /**
     * @todo this is ugly as hell and should be moved to a service
     */
    protected function sendOrderToCommerce(Order $order, ESim $esim, string $providerValue): array
    {
        try {
            $woocommerceOrder = new PushOrderToCommerceAction($order, $esim);
            $woocommerceResponse = $woocommerceOrder->execute($providerValue);

            $orderCommerceId = $woocommerceResponse['order']['id'] ?? null;

            if ($orderCommerceId === null) {
                return [
                    'status' => 'error',
                    'message' => 'Error sending order to commerce',
                    'response' => $woocommerceResponse,
                ];
            }

            $order->set(CustomFieldEnum::WOOCOMMERCE_ORDER_ID->value, $woocommerceResponse['order']['id']);

            $stripe = new StripeClient($order->app->get(EnumsConfigurationEnum::STRIPE_SECRET_KEY->value));

            $clientSecret = $order->checkout_token;
            $paymentIntentId = explode('_secret_', $clientSecret ?? '')[0]; // Gets "pi_3RAClYDdrFkcUBzl0vNHHnFD"

            if (empty($paymentIntentId)) {
                return [
                    'status' => 'error',
                    'message' => 'Payment intent ID is empty',
                ];
            }

            $paymentIntent = $stripe->paymentIntents->retrieve($paymentIntentId);

            try {
                $stripeService = new StripeCustomerService($order->app);
                $stripe->paymentIntents->update($paymentIntentId, [
                    'customer' => $stripeService->getOrCreateCustomerByPerson($order->people)->id,
                    'description' => 'Kanvas Order #' . $order->order_number,
                ]);
            } catch (Throwable $e) {
                report($e);
            }

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
            report($e);

            return [
                'status' => 'error',
                'message' => 'Error sending order to commerce',
                'response' => $e->getMessage(),
            ];
        }
    }

    protected function updateMessageMetaDataOrderNumber(Message $message, array $commerceResponse): void
    {
        if (empty($commerceResponse)) {
            return;
        }

        if (isset($message->message['order']['order_number']) && isset($commerceResponse['order']['order']['id'])) {
            $messageData = $message->message;
            $messageData['order']['order_number'] = $commerceResponse['order']['order']['id'];

            $message->message = $messageData;
            $message->saveOrFail();
        }
    }

    protected function saveQrCodeFromBase64(string $base64DataUri, Order $order, ?string $filename = null): Filesystem
    {
        // Extract the base64 data from the data URI
        $base64Data = substr($base64DataUri, strpos($base64DataUri, ',') + 1);

        // Generate filename if not provided
        if (! $filename) {
            $filename = 'qr_' . uniqid() . '.png';
        }

        // Use your existing FilesystemServices
        $filesystemService = new FilesystemServices($order->app, $order->company);

        return $filesystemService->createFileSystemFromBase64(
            $base64Data,  // Just the base64 data without the data URI prefix
            $filename,
            $order->user
        );
    }

    protected function findVariantByClosestChannelPrice(int $targetAmountInCents, int $productId, Order $order): ?Variants
    {
        try {
            // Get the default channel for the order's company and app
            $defaultChannel = Channels::getDefault($order->company, $order->app);

            // Get all published variants for the product with their channel prices
            $variants = Variants::query()
                ->where('products_id', $productId)
                ->where('is_published', 1)
                ->whereHas('channels', function ($query) use ($defaultChannel) {
                    $query->where('channels_id', $defaultChannel->getId());
                })
                ->get();

            if ($variants->isEmpty()) {
                return null;
            }

            $closestVariant = null;
            $smallestDifference = PHP_INT_MAX;

            foreach ($variants as $variant) {
                // Get the channel price from the pivot relationship
                $channelPrice = $variant->channels->first()?->pivot?->price ?? null;

                if ($channelPrice === null) {
                    continue;
                }

                // Convert to cents for comparison
                $variantPriceInCents = (int) ($channelPrice * 100);
                $difference = abs($targetAmountInCents - $variantPriceInCents);

                if ($difference < $smallestDifference) {
                    $smallestDifference = $difference;
                    $closestVariant = $variant;
                }

                // If we found an exact match, no need to continue
                if ($difference === 0) {
                    break;
                }
            }

            return $closestVariant;
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * @todo remove this hack when frontend deploys
     */
    protected function fixStandUSProductSku(Order $order): void
    {
        $validateEsimProductType = ['local', 'global', 'regional'];
        $stripe = new StripeClient($order->app->get(EnumsConfigurationEnum::STRIPE_SECRET_KEY->value));
        $paymentIntentId = explode('_secret_', $order->metadata['paymentIntent'] ?? '')[0]; // Gets "pi_3RAClYDdrFkcUBzl0vNHHnFD"

        if (empty($paymentIntentId)) {
            return;
        }

        $stripePaymentIntent = $stripe->paymentIntents->retrieve($paymentIntentId);
        $itemAmount = $stripePaymentIntent->amount ?? 0;
        $correctProduct = 221606;

        //  $hasUsBadProduct = false;
        $foundItem = null;
        foreach ($order->items as $item) {
            $variant = $item->variant;
            // Get the product type from the variant's product
            $productType = strtolower($variant->product->productType->name ?? '');
            if (! in_array($productType, $validateEsimProductType)) {
                continue;
            }

            if ((int) $variant->getId() !== 239306) { // This is the bad product id
                //  $hasUsBadProduct = true;

                continue;
            }

            $itemUnitPrice = (int) ($item->unit_price_net_amount * 100); // Convert to cents for comparison
            if ($itemAmount > $itemUnitPrice) {
                $foundItem = $item->id;

                break;
            }
        }

        if ($foundItem) {
            foreach ($order->items as $item) {
                if ((int) $item->id === (int) $foundItem) {
                    // Find the correct variant by closest channel price
                    $correctVariant = $this->findVariantByClosestChannelPrice(
                        $itemAmount,
                        $item->variant->product->getId(),
                        $order
                    );

                    if ($correctVariant) {
                        $item->product_sku = $correctVariant->sku;
                        $item->variant_id = $correctVariant->getId();
                        $item->unit_price_net_amount = $itemAmount / 100;
                        $item->unit_price_gross_amount = $itemAmount / 100;
                        $item->saveOrFail();
                        $order->calculateTotal();
                        $order->updateOrFail();

                        $order->addTag('stand-us-fix');
                        $order->refresh();
                    }

                    return;
                }
            }
        }
    }
}
