<?php

declare(strict_types=1);

namespace App\GraphQL\Souk\Mutations\Orders;

use Kanvas\Apps\Models\Apps;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Customers\Actions\CreatePeopleFromUserAction;
use Kanvas\Guild\Customers\DataTransferObject\Address;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Social\Interactions\Actions\CreateInteraction;
use Kanvas\Social\Interactions\Actions\CreateUserInteractionAction;
use Kanvas\Social\Interactions\DataTransferObject\Interaction;
use Kanvas\Social\Interactions\DataTransferObject\UserInteraction;
use Kanvas\Souk\Orders\Actions\CreateOrderFromCartAction;
use Kanvas\Souk\Orders\Actions\UpdateOrderAction;
use Kanvas\Souk\Orders\DataTransferObject\DirectOrder;
use Kanvas\Souk\Orders\DataTransferObject\OrderCustomer;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\DataTransferObject\CreditCard;
use Kanvas\Souk\Payments\DataTransferObject\CreditCardBilling;
use Kanvas\Souk\Payments\Providers\AuthorizeNetPaymentProcessor;
use Kanvas\Souk\Services\B2BConfigurationService;

class OrderManagementMutation
{
    public function create(mixed $root, array $request): array
    {
        $user = auth()->user();
        $creditCard = CreditCard::viaRequest($request['input']);
        $cart = app('cart')->session($user->getId());
        $app = app(Apps::class);

        $order = new DirectOrder(
            $app,
            $user,
            $creditCard,
            $cart
        );

        if ($cart->isEmpty()) {
            return [
                'error_code'    => 'Cart is empty',
                'error_message' => 'Cart is empty',
            ];
        }

        $isSubscription = $cart->getContent()?->first()?->attributes->has('use_subscription');
        $response = $this->processPayment($order, $isSubscription);

        return $this->handlePaymentResponse($response, $isSubscription);
    }

    public function createFromCart(mixed $root, array $request): array
    {
        $user = auth()->user();
        $cart = app('cart')->session($user->getId());
        $app = app(Apps::class);
        $company = B2BConfigurationService::getConfiguredB2BCompany($app, $user->getCurrentCompany());

        $region = Regions::getDefault($company);
        $orderCustomer = OrderCustomer::from($request['input']['customer']);
        $createPeople = new CreatePeopleFromUserAction(
            $app,
            $user->getCurrentBranch(),
            $user
        );

        $people = $createPeople->execute();

        $billing = isset($request['input']['billing']) ? CreditCardBilling::from($request['input']) : null;
        $shippingAddress = isset($request['input']['shipping_address']) ? Address::from($request['input']['shipping_address']) : null;

        if ($cart->isEmpty() && empty($request['input']['items'])) {
            return [
                'order'   => null,
                'message' => [
                    'error_code'    => 'Cart is empty',
                    'error_message' => 'Cart is empty',
                ],
            ];
        }

        $createOrder = new CreateOrderFromCartAction(
            $cart,
            $company,
            $region,
            $orderCustomer,
            $people,
            $user,
            $app,
            $billing,
            $shippingAddress,
            $request
        );

        return [
            'order'   => $createOrder->execute(),
            'message' => 'Order created successfully',
        ];
    }

    public function update(mixed $root, array $request): array
    {
        $user = auth()->user();
        $app = app(Apps::class);

        $orderId = (int) $request['id'];
        $orderData = $request['input'];

        $order = Order::where([
            'apps_id' => $app->getId(),
            'id'      => $orderId,
        ])->first();

        if ($order->fulfillment_status === 'fulfilled') {
            throw new ValidationException('Order is already fulfilled');
        }

        $updateOrder = new UpdateOrderAction(
            $order,
            $orderData,
            $user
        );

        return [
            'order'   => $updateOrder->execute(),
            'message' => 'Order updated successfully',
        ];
    }

    private function processPayment(DirectOrder $order, bool $isSubscription): mixed
    {
        $payment = new AuthorizeNetPaymentProcessor(
            app(Apps::class),
            auth()->user()->getCurrentBranch()
        );

        return $isSubscription
            ? $payment->processSubscriptionPayment($order)
            : $payment->processCreditCardPayment($order);
    }

    private function handlePaymentResponse(mixed $response, bool $isSubscription): array
    {
        $cart = app('cart')->session(auth()->user()->getId());

        if (empty($response)) {
            return [
                'error_code'    => 'No response returned',
                'error_message' => 'No response returned',
            ];
        }

        if ($response->getMessages()->getResultCode() == 'Ok') {
            $tresponse = method_exists($response, 'getTransactionResponse')
                ? $response->getTransactionResponse()
                : [];

            if (($tresponse != null && $tresponse->getMessages() != null) || (empty($tresponse) && $isSubscription)) {
                $interaction = (new CreateInteraction(
                    new Interaction(
                        'bought',
                        app(Apps::class),
                        'Bought',
                        'User bought a variant of a product'
                    )
                ))->execute();

                $subscriptionId = $isSubscription && method_exists($response, 'getSubscriptionId')
                    ? $response->getSubscriptionId()
                    : null;

                foreach ($cart->getContent() as $item) {
                    (new CreateUserInteractionAction(
                        new UserInteraction(
                            auth()->user(),
                            $interaction,
                            (string) $item->id,
                            Variants::class,
                            !$isSubscription
                                ? 'User bought a variant of a product'
                                : 'User subscribed to a product '.$subscriptionId
                        )
                    ))->execute();
                }
                $cart->clear();

                if (empty($tresponse)) {
                    return [
                        'description'    => 'Subscription created successfully',
                        'message_code'   => 'I00001',
                        'response_code'  => 'I00001',
                        'transaction_id' => 'I00001',
                        'auth_code'      => 'I00001',
                    ];
                }

                return [
                    'transaction_id' => $tresponse->getTransId(),
                    'response_code'  => $tresponse->getResponseCode(),
                    'message_code'   => $tresponse->getMessages()[0]->getCode(),
                    'auth_code'      => $tresponse->getAuthCode(),
                    'description'    => $tresponse->getMessages()[0]->getDescription(),
                ];
            } else {
                $cart->clear();

                return [
                    'error_code'    => $tresponse->getErrors()[0]->getErrorCode(),
                    'error_message' => $tresponse->getErrors()[0]->getErrorText(),
                ];
            }
        } else {
            $cart->clear();

            return [
                'error_code'    => $response->getMessages()->getMessage()[0]->getCode(),
                'error_message' => $response->getMessages()->getMessage()[0]->getText(),
            ];
        }
    }
}
