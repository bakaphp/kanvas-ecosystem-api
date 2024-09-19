<?php

declare(strict_types=1);

namespace Tests\GraphQL\Subscription;

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Request;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Stripe\Enums\ConfigurationEnum;
use Stripe\StripeClient;
use Tests\TestCase;

final class SubscriptionsTest extends TestCase
{
    public function testCreateSubscription()
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $stripeSecretKey = env('TEST_STRIPE_SECRET_KEY');
        $app->set(ConfigurationEnum::STRIPE_SECRET_KEY->value, $stripeSecretKey);
        $stripe = new StripeClient($stripeSecretKey);
        $customer = $stripe->customers->create([
            'email' => 'test@example.com',
            'name' => 'Test User',
        ]);
        $paymentMethod = $stripe->paymentMethods->create([
          'type' => 'card',
          'card' => [
            'number' => '4242424242424242',
            'exp_month' => 8,
            'exp_year' => 2026,
            'cvc' => '314',
          ],
        ]);

        $stripe->paymentMethods->attach(
            $paymentMethod->id,
            ['customer' => $customer->id]
        );
        $stripe->customers->update(
            $customer->id,
            ['invoice_settings' => ['default_payment_method' => $paymentMethod->id]]
        );

        \Stripe\Stripe::setApiKey($app->get(ConfigurationEnum::STRIPE_SECRET_KEY->value));

        $response = $this->graphQL('
            mutation {
                createSubscription(input: {
                    items: [
                        {   
                            price_id: "price_1Q0UbCBwyV21ueMMBn3VnjMo",
                            quantity: 2 
                        }
                    ],
                    name: "Test Subscription",       
                    payment_method_id: "' . $paymentMethod->id . '",       
                    trial_days: 14,                       
                }) {
                    id
                    name
                }
            }
        ');

        $response->assertJson([
            'data' => [
                'createSubscription' => [
                    'name' => 'Test Subscription',
                ],
            ],
        ]);
    }
}
