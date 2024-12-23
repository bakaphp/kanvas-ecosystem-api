<?php

declare(strict_types=1);

namespace App\GraphQL\Souk\Mutations\Orders;

use Baka\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Connectors\Stripe\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Users\Models\UserCompanyApps;
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
        $company = $user->getCurrentCompany();
        $orderId = $request['id'];

        if ($app->get('USE_B2B_COMPANY_GROUP')) {
            if (UserCompanyApps::where('companies_id', $app->get('B2B_GLOBAL_COMPANY'))->where('apps_id', $app->getId())->first()) {
                $company = Companies::getById($app->get('B2B_GLOBAL_COMPANY'));
            }
        }

        $order = Order::getByIdFromCompanyApp($orderId, $company, $app);

        if ($order->isFulfilled()) {
            throw new ValidationException('Order is already fulfilled');
        }

        $stripeApiKey = $app->get(ConfigurationEnum::STRIPE_SECRET_KEY->value);
        if (empty($stripeApiKey)) {
            throw new ValidationException('Stripe is not configured for this app');
        }

        Stripe::setApiKey($stripeApiKey);

        $totalAmount = $order->total_gross_amount * 100;
        $intent = PaymentIntent::create([
            'amount' => $totalAmount,
            'currency' => 'usd',
            ]);

        return [
            'status' => 'success',
            'client_secret' => $intent->client_secret,
            'message' => [
                'message' => 'Payment intent generated successfully',
                'amount' => $order->total_gross_amount,
                'currency' => 'usd',
            ],
        ];
    }
}
