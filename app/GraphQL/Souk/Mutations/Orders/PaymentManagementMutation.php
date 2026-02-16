<?php

declare(strict_types=1);

namespace App\GraphQL\Souk\Mutations\Orders;

use Baka\Support\Str;
use Exception;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Connectors\Stripe\Actions\PushUserToStripeCustomerAction;
use Kanvas\Connectors\Stripe\Enums\ConfigurationEnum;
use Kanvas\Enums\AppEnums;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Souk\Orders\Models\Order;
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

        //$totalAmount = $amount * 100;
        //$totalAmount = $amount === $cart->getTotal() ? $amount : $cart->getTotal();
        $totalAmount = (int) round($cart->getTotal() * 100);

        // Get currency from cart items, fallback to 'usd'
        $currencyCode = 'usd';
        $cartContent = $cart->getContent();
        if ($cartContent && $cartContent->isNotEmpty()) {
            $firstItem = $cartContent->first();
            $attributes = $firstItem->attributes;
            $currency = is_array($attributes) || $attributes instanceof \ArrayAccess ? ($attributes['currency'] ?? null) : null;
            if (is_array($currency) && ! empty($currency['code'])) {
                $currencyCode = strtolower($currency['code']);
            }
        }

        Log::info('generatePaymentIntent currency: ' . $currencyCode);

        if ($totalAmount == 0 && $cart->getTotal() == 0) {
            return [
                'status' => 'success',
                'id' => $cart->getSessionKey(),
                'client_secret' => $cart->getSessionKey(),
                'message' => [
                'message' => 'Payment intent generated successfully',
                'amount' => $amount,
                'currency' => $currencyCode,
                ],
            ];
        }

        $intent = PaymentIntent::create([
            'amount' => $totalAmount,
            'currency' => $currencyCode,
            'customer' => $customer->id,
        ]);

        return [
            'status' => 'success',
            'id' => $intent->id,
            'client_secret' => $intent->client_secret,
            'message' => [
                'message' => 'Payment intent generated successfully',
                'amount' => $amount,
                'currency' => $currencyCode,
            ],
        ];
    }

    public function generatePaymentIntentFromOrder(mixed $root, array $request): array
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $orderId = (int) $request['orderId'];

        $order = Order::fromApp($app)
        ->where([
            'id' => $orderId,
        ])->first();

        if (! $order) {
            return [
                'status' => 'error',
                'message' => 'Order not found',
            ];
        }

        if ($order->isPaid()) {
            return [
                'status' => 'error',
                'message' => 'Order is already paid',
            ];
        }

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

        $amount = $order->getTotalAmount();
        $currencyCode = ! empty($order->currency) ? strtolower($order->currency) : 'usd';

        $totalAmount = (int) round($amount * 100);

        try {
            $intent = PaymentIntent::create([
                'amount' => $totalAmount,
                'currency' => $currencyCode,
                'customer' => $customer->id,
            ]);

            return [
                'status' => 'success',
                'id' => $intent->id,
                'client_secret' => $intent->client_secret,
                'message' => [
                    'message' => 'Payment intent generated successfully',
                    'amount' => $amount,
                    'currency' => $currencyCode,
                ],
            ];
        } catch (Exception $e) {
            report($e);

            throw new ValidationException($e->getMessage());
        }
    }
}
