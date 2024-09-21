<?php

declare(strict_types=1);

namespace Tests\GraphQL\Subscription;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Stripe\Enums\ConfigurationEnum;
use Stripe\StripeClient;
use Tests\TestCase;

final class SubscriptionsTest extends TestCase
{
    protected $app;
    private $stripe;
    private $paymentMethod;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $stripeSecretKey = env('TEST_STRIPE_SECRET_KEY');
        $this->app->set(ConfigurationEnum::STRIPE_SECRET_KEY->value, $stripeSecretKey);
        $this->stripe = new StripeClient($stripeSecretKey);
        $customer = $this->stripe->customers->create([
            'email' => 'test_subscription@example.com',
            'name' => 'Test_subscription_User',
        ]);
        $this->paymentMethod = $this->stripe->paymentMethods->create([
            'type' => 'card',
            'card' => [
                'number' => '4242424242424242',
                'exp_month' => 8,
                'exp_year' => 2026,
                'cvc' => '314',
            ],
        ]);

        $this->stripe->paymentMethods->attach(
            $this->paymentMethod->id,
            ['customer' => $customer->id]
        );
        $this->stripe->customers->update(
            $customer->id,
            ['invoice_settings' => ['default_payment_method' => $this->paymentMethod->id]]
        );

        \Stripe\Stripe::setApiKey($this->app->get(ConfigurationEnum::STRIPE_SECRET_KEY->value));
    }

    public function testCreateSubscription()
    {
        $response = $this->graphQL('
            mutation {
                createSubscription(input: {
                    items: [
                        {
                            apps_plans_prices_id: 1, #Basic
                            quantity: 1 #Optional, default 1
                        }
                    ],
                    name: "TestCreate Subscription",       
                    payment_method_id: "' . $this->paymentMethod->id . '",       
                    trial_days: 30,                       
                }) {
                    id
                    name
                    subscriptionItems {
                        id
                        quantity
                    }
                }
            }
        ');

        $response->assertJson([
            'data' => [
                'createSubscription' => [
                    'name' => 'TestCreate Subscription',
                ],
            ],
        ]);
    }

    public function testChangeSubscriptionItems() //equivalent to change plan when using one subscription_item
    {
        $response = $this->graphQL('
            mutation {
                addSubscriptionItem(input: {
                    subscription_id: 1,
                    items: [
                        {
                            apps_plans_prices_id: 2, #Change to Pro
                            quantity: 1 #Optional, update quantity
                        }
                    ]
                }) {
                    id
                    stripe_id
                    quantity
                }
            }
        ');

        $response->assertJson([
            'data' => [
                'addSubscriptionItem' => [
                    'quantity' => 1,
                ],
            ],
        ]);

        $response = $this->graphQL('
            mutation {
                deleteSubscriptionItem(id: 1, subscription_id: 1) #Basic (previous plan)
            }
        ');

        $response->assertJson([
            'data' => [
                'deleteSubscriptionItem' => true,
            ],
        ]);
    }

    public function testCancelSubscription()
    {
        $response = $this->graphQL('
            mutation {
                cancelSubscription(id: 1)
            }
        ');

        $response->assertJson([
            'data' => [
                'cancelSubscription' => true,
            ],
        ]);
    }
}
