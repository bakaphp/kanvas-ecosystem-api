<?php

declare(strict_types=1);

namespace App\GraphQL\Souk\Mutations\Orders;

use Baka\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Connectors\Stripe\Actions\PushUserToStripeCustomerAction;
use Kanvas\Connectors\Stripe\Enums\ConfigurationEnum;
use Kanvas\Enums\AppEnums;
use Kanvas\Exceptions\ValidationException;
use Stripe\PaymentIntent;
use Stripe\Stripe;

class PaymentManagementMutation
{
    public function processPayment(mixed $root, array $request): array
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $branch = app(CompaniesBranches::class);

        return [
            'status' => 'success',
            'transaction_id' => Str::uuid(),
            'order_status' => 'paid',
            'message' => 'Payment processed successfully',
        ];
    }

    public function generatePaymentIntent(mixed $root, array $request): array
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $amount = (float) $request['amount'];
        $cart = app('cart')->session(app(AppEnums::KANVAS_IDENTIFIER->getValue()));

        $stripeApiKey = $app->get(ConfigurationEnum::STRIPE_SECRET_KEY->value);
        if (empty($stripeApiKey)) {
            throw new ValidationException('Stripe is not configured for this app');
        }

        Stripe::setApiKey($stripeApiKey);

        $customer = new PushUserToStripeCustomerAction(
            $user,
            $app,
            $user->getCurrentCompany()
        )->execute();

        $totalAmount = $amount * 100;

        if ($totalAmount == 0 && $cart->getTotal() == 0) {
            return [
                'status' => 'success',
                'id' => $cart->getSessionKey(),
                'client_secret' => $cart->getSessionKey(),
                'message' => [
                'message' => 'Payment intent generated successfully',
                'amount' => $amount,
                'currency' => 'usd',
                ],
            ];
        }

        //50 = $0.50 , stripe doesn't allow payment intent less than 0.5
        if ($totalAmount < 50) {
            throw new ValidationException('Payment amount is too low, amount must be at least $0.50 usd');
        }

        $intent = PaymentIntent::create([
            'amount' => $totalAmount,
            'currency' => 'usd',
            'customer' => $customer->id,
        ]);

        return [
            'status' => 'success',
            'id' => $intent->id,
            'client_secret' => $intent->client_secret,
            'message' => [
                'message' => 'Payment intent generated successfully',
                'amount' => $amount,
                'currency' => 'usd',
            ],
        ];
    }
}
