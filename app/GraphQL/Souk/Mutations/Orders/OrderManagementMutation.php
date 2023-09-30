<?php

declare(strict_types=1);

namespace App\GraphQL\Souk\Mutations\Orders;

use Kanvas\Apps\Models\Apps;
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

        if ($response != null) {
            // Check to see if the API request was successfully received and acted upon
            if ($response->getMessages()->getResultCode() == 'Ok') {
                // Since the API request was successful, look for a transaction response
                // and parse it to display the results of authorizing the card
                $tresponse = $response->getTransactionResponse();

                if ($tresponse != null && $tresponse->getMessages() != null) {
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
            // Or, print errors if the API request wasn't successful
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
