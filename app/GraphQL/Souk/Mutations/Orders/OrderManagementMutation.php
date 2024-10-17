<?php

declare(strict_types=1);

namespace App\GraphQL\Souk\Mutations\Orders;

use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Social\Interactions\Actions\CreateInteraction;
use Kanvas\Social\Interactions\Actions\CreateUserInteractionAction;
use Kanvas\Social\Interactions\DataTransferObject\Interaction;
use Kanvas\Social\Interactions\DataTransferObject\UserInteraction;
use Kanvas\Souk\Orders\DataTransferObject\DirectOrder;
use Kanvas\Souk\Payments\DataTransferObject\CreditCard;
use Kanvas\Souk\Payments\DataTransferObject\Profile;
use Kanvas\Souk\Payments\DataTransferObject\Transaction;
use Kanvas\Souk\Payments\Providers\AuthorizeNetPaymentProcessor;

class OrderManagementMutation
{
    public function create(mixed $root, array $request): array
    {
        $user = auth()->user();
        $creditCard = CreditCard::viaRequest($request['input']);
        $cart = app('cart')->session($user->getId());

        $order = new DirectOrder(
            app(Apps::class),
            $user,
            $creditCard,
            $cart
        );

        if ($cart->isEmpty()) {
            return [
                'error_code' => 'Cart is empty',
                'error_message' => 'Cart is empty',
            ];
        }

        $isSubscription = $cart->getContent()?->first()?->attributes->has('use_subscription');
        $response = $this->processPayment($order, $isSubscription);

        return $this->handlePaymentResponse($response, $isSubscription);
    }

    public function createCustomerProfileWithPayment(mixed $root, array $request)
    {
        $user = auth()->user();
        $creditCard = CreditCard::viaRequest($request['input']);
        $profile = Profile::viaRequest($request['input']);
        $cart = app('cart')->session($user->getId());

        $order = new DirectOrder(
            app(Apps::class),
            $user,
            $creditCard,
            $cart,
            $profile
        );

        $payment = new AuthorizeNetPaymentProcessor(
            app(Apps::class),
            auth()->user()->getCurrentBranch()
        );

        return $payment->createCustomerProfileWithPayment($order);
    }

    public function createCustomerPaymentProfile(mixed $root, array $request)
    {
        $user = auth()->user();
        $creditCard = CreditCard::viaRequest($request['input']);
        $profile = Profile::viaRequest($request['input']);
        $cart = app('cart')->session($user->getId());

        $order = new DirectOrder(
            app(Apps::class),
            $user,
            $creditCard,
            $cart,
            $profile
        );


        $payment = new AuthorizeNetPaymentProcessor(
            app(Apps::class),
            auth()->user()->getCurrentBranch()
        );

        return $payment->createCustomerPaymentProfile($order);
    }

    public function updateCustomerPaymentProfile(mixed $root, array $request)
    {
        $user = auth()->user();
        $creditCard = CreditCard::viaRequest($request['input']);
        $profile = Profile::viaRequest($request['input']);
        $cart = app('cart')->session($user->getId());

        $order = new DirectOrder(
            app(Apps::class),
            $user,
            $creditCard,
            $cart,
            $profile
        );


        $payment = new AuthorizeNetPaymentProcessor(
            app(Apps::class),
            auth()->user()->getCurrentBranch()
        );

        return $payment->updateCustomerPaymentProfile($order);
    }

    public function deleteCustomerPaymentProfile(mixed $root, array $request)
    {
        $user = auth()->user();
        $creditCard = CreditCard::viaRequest($request['input']);
        $profile = Profile::viaRequest($request['input']);
        $cart = app('cart')->session($user->getId());

        $order = new DirectOrder(
            app(Apps::class),
            $user,
            $creditCard,
            $cart,
            $profile
        );


        $payment = new AuthorizeNetPaymentProcessor(
            app(Apps::class),
            auth()->user()->getCurrentBranch()
        );

        return $payment->deleteCustomerPaymentProfile($order);
    }

    public function refundPayment(mixed $root, array $request)
    {
        $user = auth()->user();
        $creditCard = CreditCard::viaRequest($request['input']);
        $transaction = Transaction::viaRequest($request['input']);
        $cart = app('cart')->session($user->getId());

        $order = new DirectOrder(
            app(Apps::class),
            $user,
            $creditCard,
            $cart,
            null,
            $transaction
        );


        $payment = new AuthorizeNetPaymentProcessor(
            app(Apps::class),
            auth()->user()->getCurrentBranch()
        );

        return $payment->refundPayment($order);
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
                'error_code' => 'No response returned',
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
                            ! $isSubscription
                                ? 'User bought a variant of a product'
                                : 'User subscribed to a product ' . $subscriptionId
                        )
                    ))->execute();
                }
                $cart->clear();

                if (empty($tresponse)) {
                    return [
                        'description' => 'Subscription created successfully',
                        'message_code' => 'I00001',
                        'response_code' => 'I00001',
                        'transaction_id' => 'I00001',
                        'auth_code' => 'I00001',
                    ];
                }

                return [
                    'transaction_id' => $tresponse->getTransId(),
                    'response_code' => $tresponse->getResponseCode(),
                    'message_code' => $tresponse->getMessages()[0]->getCode(),
                    'auth_code' => $tresponse->getAuthCode(),
                    'description' => $tresponse->getMessages()[0]->getDescription(),
                ];
            } else {
                $cart->clear();

                return [
                    'error_code' => $tresponse->getErrors()[0]->getErrorCode(),
                    'error_message' => $tresponse->getErrors()[0]->getErrorText(),
                ];
            }
        } else {
            $cart->clear();

            return [
                'error_code' => $response->getMessages()->getMessage()[0]->getCode(),
                'error_message' => $response->getMessages()->getMessage()[0]->getText(),
            ];
        }
    }
}
