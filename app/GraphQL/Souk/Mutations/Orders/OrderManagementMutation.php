<?php

declare(strict_types=1);

namespace App\GraphQL\Souk\Mutations\Orders;

use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Social\Interactions\Actions\CreateInteraction;
use Kanvas\Social\Interactions\DataTransferObject\Interaction;
use Kanvas\Social\UsersInteractions\Actions\CreateUserInteractionAction;
use Kanvas\Social\UsersInteractions\DataTransferObject\UserInteraction;
use Kanvas\Souk\Orders\DataTransferObject\Order;
use Kanvas\Souk\Payments\DataTransferObject\CreditCard;
use Kanvas\Souk\Payments\Providers\AuthorizeNetProvider;

class OrderManagementMutation
{
    public function create(mixed $root, array $request): array
    {
        $user = auth()->user();
        $creditCard = CreditCard::from($request['input']['payment']);
        $payment = new AuthorizeNetProvider(
            app(Apps::class),
            $user->getCurrentBranch()
        );
        $cart = app('cart')->session($user->getId());

        $order = new Order(
            app(Apps::class),
            $user,
            $creditCard,
            $cart
        );

        $response = $payment->chargeCreditCard($order);

        //clean cart and add interaction
        if ($response != null) {
            if ($response->getMessages()->getResultCode() == 'Ok') {
                $tresponse = $response->getTransactionResponse();

                if ($tresponse != null && $tresponse->getMessages() != null) {
                    
                    //bought the variant so flag it
                    $interaction = (new CreateInteraction(
                        new Interaction(
                            'bought',
                            app(Apps::class),
                            'Bought',
                            'User bought a variant of a product'
                        )
                    ))->execute();

                    foreach ($cart->getContent() as $item) {
                        (new CreateUserInteractionAction(
                            new UserInteraction(
                                $user,
                                $interaction,
                                (string) $item->id,
                                Variants::class,
                                'User bought a variant of a product'
                            )
                        ))->execute();
                    }

                    return [
                        'transaction_id' => $tresponse->getTransId(),
                        'response_code' => $tresponse->getResponseCode(),
                        'message_code' => $tresponse->getMessages()[0]->getCode(),
                        'auth_code' => $tresponse->getAuthCode(),
                        'description' => $tresponse->getMessages()[0]->getDescription(),
                    ];
                } else {
                    return [
                        'error_code' => $tresponse->getErrors()[0]->getErrorCode(),
                        'error_message' => $tresponse->getErrors()[0]->getErrorText(),
                    ];
                }
            } else {
                return [
                    'error_code' => $response->getMessages()->getMessage()[0]->getCode(),
                    'error_message' => $response->getMessages()->getMessage()[0]->getText(),
                ];
            }
        } else {
            return [
                'error_code' => 'No response returned',
                'error_message' => 'No response returned',
            ];
        }
    }
}
