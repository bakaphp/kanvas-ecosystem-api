<?php

declare(strict_types=1);

namespace Tests\GraphQL\Ecosystem;

use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Subscription\Subscriptions\Models\AppsStripeCustomer;
use Laravel\Cashier\Subscription;
use Tests\TestCase;

class UserSubscriptionTest extends TestCase
{
    public function testGetUserCurrentSubscription(): void
    {
        $user = auth()->user();
        $app = app(Apps::class);

        $configData = ['plan' => 'premium', 'seats' => 5];

        $stripeCustomer = AppsStripeCustomer::firstOrCreate([
            'users_id' => $user->getId(),
            'companies_id' => 0,
            'apps_id' => $app->getId(),
        ]);
        $stripeCustomer->config = $configData;
        $stripeCustomer->saveOrFail();

        $subscription = Subscription::create([
            'apps_stripe_customer_id' => $stripeCustomer->id,
            'type' => 'default',
            'stripe_id' => 'sub_test_' . Str::random(20),
            'stripe_status' => 'active',
            'stripe_price' => 'price_test_123',
            'quantity' => 1,
        ]);

        $response = $this->graphQL('
            query {
                me {
                    id
                    currentSubscription {
                        id
                        user_id
                        type
                        provider
                        stripe_id
                        stripe_status
                        status
                        plan_name
                        stripe_price
                        quantity
                        started_at
                        is_active
                        config
                    }
                }
            }
        ');

        $response->assertSuccessful()
            ->assertJson([
                'data' => [
                    'me' => [
                        'currentSubscription' => [
                            'id' => (string) $stripeCustomer->id,
                            'type' => 'default',
                            'stripe_status' => 'active',
                            'status' => 'active',
                            'plan_name' => 'default',
                            'stripe_price' => 'price_test_123',
                            'is_active' => true,
                            'config' => $configData,
                        ],
                    ],
                ],
            ]);
    }

    public function testGetUserSubscriptionById(): void
    {
        $user = auth()->user();
        $app = app(Apps::class);

        $configData = ['plan' => 'basic', 'seats' => 3];

        $stripeCustomer = AppsStripeCustomer::firstOrCreate([
            'users_id' => $user->getId(),
            'companies_id' => 0,
            'apps_id' => $app->getId(),
        ]);
        $stripeCustomer->config = $configData;
        $stripeCustomer->saveOrFail();

        $subscription = Subscription::create([
            'apps_stripe_customer_id' => $stripeCustomer->id,
            'type' => 'default',
            'stripe_id' => 'sub_test_' . Str::random(20),
            'stripe_status' => 'active',
            'stripe_price' => 'price_test_456',
            'quantity' => 1,
        ]);

        $response = $this->graphQL('
            query($id: ID!) {
                userSubscription(id: $id) {
                    id
                    user_id
                    type
                    provider
                    stripe_id
                    stripe_status
                    status
                    plan_name
                    stripe_price
                    quantity
                    started_at
                    is_active
                    config
                }
            }
        ', ['id' => $stripeCustomer->id]);

        $response->assertSuccessful()
            ->assertJson([
                'data' => [
                    'userSubscription' => [
                        'id' => (string) $stripeCustomer->id,
                        'type' => 'default',
                        'stripe_status' => 'active',
                        'status' => 'active',
                        'plan_name' => 'default',
                        'stripe_price' => 'price_test_456',
                        'is_active' => true,
                        'config' => $configData,
                    ],
                ],
            ]);
    }

    public function testGetUserCurrentSubscriptionReturnsNullWhenNoSubscription(): void
    {
        $response = $this->graphQL('
            query {
                me {
                    id
                    currentSubscription {
                        id
                        stripe_status
                    }
                }
            }
        ');

        $response->assertSuccessful()
            ->assertJson([
                'data' => [
                    'me' => [
                        'currentSubscription' => null,
                    ],
                ],
            ]);
    }
}
